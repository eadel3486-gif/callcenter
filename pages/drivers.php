<?php
defined('APP') || exit;
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $did = (int)($_POST['driver_id'] ?? 0);
    if ($act === 'add' && can('edit')) {
        $n = trim($_POST['name'] ?? '');
        if ($n === '') flash('اكتب اسم المندوب', 'err');
        else {
            $c = $db->prepare('SELECT COUNT(*) FROM drivers WHERE name=?');
            $c->execute([$n]);
            if ($c->fetchColumn() > 0) flash('المندوب موجود بالفعل', 'err');
            else {
                $db->prepare('INSERT INTO drivers(name,phone,token,link_code) VALUES(?,?,?,?)')->execute([$n, trim($_POST['phone'] ?? ''), new_token(), new_code()]);
                flash('تمت إضافة المندوب — افتح «ربط تليجرام» لتعطيه كود الربط');
            }
        }
    } elseif ($act === 'pay' && can('pay')) {
        $amt = (float)($_POST['amount'] ?? 0);
        if ($amt <= 0 || !$did) flash('أدخل مبلغاً صحيحاً', 'err');
        else {
            $d = valid_date($_POST['d'] ?? '') ? $_POST['d'] : date('Y-m-d');
            $psh = user_shift() ?: (in_array($_POST['shift'] ?? '', ['صباحي', 'مسائي'], true) ? $_POST['shift'] : (cur_shift() ?: 'صباحي'));
            $db->prepare('INSERT INTO payments(driver_id,d,amount,note,shift,method) VALUES(?,?,?,?,?,?)')->execute([$did, $d, $amt, trim($_POST['note'] ?? ''), $psh, pay_pick($_POST['method'] ?? 'cash')]);
            flash('تم تسجيل الدفعة');
        }
    } elseif ($act === 'deldriver' && can('del')) {
        // حذف المندوب مستقل: الأوردرات والدفعات تبقى كسجلات تاريخية بلا ارتباط بمندوب نشط.
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE orders SET driver_id=NULL WHERE driver_id=?')->execute([$did]);
            // الدفعات محفوظة كما هي كسجل مالي تاريخي ولا يتم حذفها مع المندوب.
            $db->prepare('DELETE FROM locations WHERE driver_id=?')->execute([$did]);
            $db->prepare('DELETE FROM drivers WHERE id=?')->execute([$did]);
            $db->commit();
            flash('تم حذف المندوب. الأوردرات والدفعات السابقة ما زالت محفوظة بشكل مستقل.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('تعذر حذف المندوب: ' . $e->getMessage(), 'err');
        }
    } elseif ($act === 'delpay' && can('del')) {
        if (user_shift() !== '') $db->prepare('DELETE FROM payments WHERE id=? AND shift=?')->execute([(int)($_POST['id'] ?? 0), user_shift()]);
        else $db->prepare('DELETE FROM payments WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('تم حذف الدفعة');
    } elseif ($act === 'regen' && can('edit')) {
        $db->prepare('UPDATE drivers SET link_code=? WHERE id=?')->execute([new_code(), $did]);
        flash('تم توليد كود ربط جديد');
    } elseif ($act === 'unlink' && can('edit')) {
        $db->prepare('UPDATE drivers SET tg_chat_id=NULL, tg_username=NULL, link_code=? WHERE id=?')->execute([new_code(), $did]);
        flash('تم فصل حساب تليجرام عن المندوب');
    } elseif ($act === 'toggle' && can('edit')) {
        $db->prepare('UPDATE drivers SET active=1-active WHERE id=?')->execute([$did]);
        flash('تم تغيير حالة المندوب');
    } elseif ($act === 'webtoken' && can('edit')) {
        $db->prepare('UPDATE drivers SET token=? WHERE id=?')->execute([new_token(), $did]);
        flash('تم تغيير رابط الموقع من المتصفح (القديم لم يعد يعمل)');
    }
    redirect('index.php?p=drivers');
}

$sh = cur_shift();
$oSh = $sh !== '' ? ' AND o.shift=?' : '';
$pSh = $sh !== '' ? ' AND p.shift=?' : '';
$q = $db->prepare("SELECT dr.*,
  (SELECT COUNT(*) FROM orders o WHERE o.driver_id=dr.id$oSh) n,
  (SELECT COALESCE(SUM(val),0) FROM orders o WHERE o.driver_id=dr.id$oSh) v,
  (SELECT COALESCE(SUM(fee),0) FROM orders o WHERE o.driver_id=dr.id$oSh) f,
  (SELECT COALESCE(SUM(val+fee),0) FROM orders o WHERE o.driver_id=dr.id AND o.pay_method='cash'$oSh) cash_due,
  (SELECT COALESCE(SUM(val+fee),0) FROM orders o WHERE o.driver_id=dr.id AND o.pay_method<>'cash'$oSh) elec,
  (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.driver_id=dr.id$pSh) paid
  FROM drivers dr ORDER BY dr.active DESC, dr.name");
$q->execute($sh !== '' ? [$sh, $sh, $sh, $sh, $sh, $sh] : []);
$D = $q->fetchAll();

$sumN = $sumPaid = $sumRem = $owing = 0;
$rows = [];
foreach ($D as $r) {
    $tot = $r['v'] + $r['f'];
    $rem = $r['cash_due'] - $r['paid'];   // العهدة = أوردرات الكاش فقط − ما سلّمه المندوب
    $sumN += $r['n']; $sumPaid += $r['paid']; $sumRem += $rem;
    if ($rem > 0) $owing++;
    $tgCell = $r['tg_chat_id']
        ? raw('<span class="tg-on">✈️ مربوط' . ($r['tg_username'] ? ' @' . h($r['tg_username']) : '') . '</span>')
        : raw('<span class="tg-off">غير مربوط</span>');
    $seen = $r['last_at'] ? raw(h(date('H:i d/m', $r['last_at']))) : '—';
    $rows[] = [
        raw(h($r['name']) . ($r['active'] ? '' : ' <span class="mu">(معطّل)</span>')),
        N($r['n']), N($r['v']), N($r['f']), N($tot), N($r['elec']), N($r['cash_due']), N($r['paid']),
        raw($rem > 0 ? '<span class="neg">' . N($rem) . '</span>' : N($rem)),
        $rem > 0 ? 'عليه رصيد' : 'مسدد', $tgCell, $seen,
    ];
}
$q = $db->prepare('SELECT p.*, dr.name dname FROM payments p JOIN drivers dr ON dr.id=p.driver_id WHERE 1=1' . $pSh . ' ORDER BY p.d DESC, p.id DESC LIMIT 200');
$q->execute($sh !== '' ? [$sh] : []);
$P = $q->fetchAll();
$bot = tg_bot();
$post = fn($act, $id, $label, $cls = 'g', $confirm = '') =>
    '<form class="inl" method="post" action="index.php?p=drivers"' . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\')"' : '') . '>' . csrf_field()
    . '<input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="driver_id" value="' . (int)$id . '"><button class="btn ' . $cls . ' s">' . $label . '</button></form>';
?>
<?php if (can('edit')): ?>
<form class="fb no" method="post">
  <?= csrf_field() ?><input type="hidden" name="act" value="add">
  <input name="name" placeholder="اسم مندوب جديد"> <input name="phone" placeholder="رقم الموبايل (اختياري)">
  <button class="btn">إضافة مندوب</button>
</form>
<?php endif; ?>
<div class="k">
  <div><b><?= N($sumN) ?></b><span>إجمالي الأوردرات</span></div>
  <div><b><?= N($sumPaid) ?></b><span>إجمالي المحصّل</span></div>
  <div><b><?= N($sumRem) ?></b><span>إجمالي المتبقي</span></div>
  <div><b><?= $owing ?></b><span>مندوبون عليهم رصيد</span></div>
</div>
<?= table(['المندوب', 'الأوردرات', 'القيمة', 'التوصيل', 'الإجمالي', 'إلكتروني (فيزا/إنستا)', 'كاش عليه', 'سلّمه', 'المتبقي', 'الحالة', 'تليجرام', 'آخر ظهور'], $rows,
function ($i, $r) use ($D, $post) {
    $d = $D[$i];
    $s = '<a class="btn g s" href="index.php?p=orders&m=' . (int)$d['id'] . '">أوردراته</a> ';
    if (can('pay')) $s .= '<button class="btn s" onclick="document.getElementById(\'pay' . (int)$d['id'] . '\').showModal()">تسجيل دفعة</button> ';
    if (can('edit')) $s .= '<button class="btn g s" onclick="document.getElementById(\'lk' . (int)$d['id'] . '\').showModal()">ربط تليجرام</button> ' . $post('toggle', $d['id'], $d['active'] ? 'تعطيل' : 'تفعيل');
    if (can('del')) $s .= ' ' . $post('deldriver', $d['id'], 'حذف', 'r', 'حذف هذا المندوب نهائياً؟');
    return $s;
}) ?>
<p class="mu">الإجمالي = قيمة الأوردرات + خدمة التوصيل. أوردرات الفيزا وإنستا باي مدفوعة إلكترونياً فلا تدخل في عهدة المندوب، والمتبقي = «كاش عليه» − «سلّمه».<?= $sh !== '' ? ' الأرقام هنا لشيفت ' . h($sh) . ' فقط (أوردراته ودفعاته).' : ' الدفعات القديمة بدون شيفت تظهر في «الكل» فقط.' ?></p>

<?php foreach ($D as $d): $id = (int)$d['id']; ?>
<dialog id="pay<?= $id ?>">
  <h3>دفعة من <?= h($d['name']) ?></h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="pay"><input type="hidden" name="driver_id" value="<?= $id ?>">
    <div class="fg"><label>التاريخ<input type="date" name="d" value="<?= date('Y-m-d') ?>"></label><label>المبلغ<input type="number" step="any" name="amount"></label><label>طريقة التسليم<?= pay_select('method', 'cash') ?></label>
      <?php if (user_shift() !== ''): ?><label>الشيفت<input value="<?= h(user_shift()) ?>" disabled></label><?php else: ?><label>الشيفت<select name="shift"><?php foreach (['صباحي', 'مسائي'] as $x): ?><option <?= $x === ($sh ?: 'صباحي') ? 'selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></label><?php endif; ?></div>
    <label>ملاحظة<input name="note"></label>
    <p><button class="btn">حفظ</button> <button type="button" class="btn g" onclick="this.closest('dialog').close()">إلغاء</button></p>
  </form>
</dialog>
<dialog id="lk<?= $id ?>">
  <h3>ربط <?= h($d['name']) ?> بتليجرام</h3>
  <?php if ($d['tg_chat_id']): ?>
    <p class="tg-on">✈️ مربوط<?= $d['tg_username'] ? ' (@' . h($d['tg_username']) . ')' : '' ?>. بتوصله الأوردرات على تليجرام.</p>
    <?= $post('unlink', $id, 'فصل الحساب', 'r', 'فصل حساب تليجرام عن هذا المندوب؟') ?>
  <?php else: ?>
    <p>خلّي المندوب يفتح البوت <?= $bot ? '<b>@' . h($bot) . '</b>' : '(اضبط البوت من «إعدادات تليجرام»)' ?> ويبعت له الكود ده:</p>
    <p><span class="cd big"><?= h($d['link_code']) ?></span></p>
    <?php if ($bot): ?><p>أو يفتح الرابط مباشرة:<br><a class="cd" href="https://t.me/<?= h($bot) ?>?start=<?= h($d['link_code']) ?>" target="_blank">https://t.me/<?= h($bot) ?>?start=<?= h($d['link_code']) ?></a></p><?php endif; ?>
    <?= $post('regen', $id, 'كود جديد') ?>
  <?php endif; ?>
  <hr>
  <p class="mu">بديل لإرسال الموقع من المتصفح (لو لا يريد استخدام Live Location في تليجرام) — رابط خاص بالمندوب، يفتحه على موبايله ويضغط «ابدأ»:</p>
  <p><span class="cd"><?= h(base_url() . '/driver.php?t=' . $d['token']) ?></span></p>
  <?= $post('webtoken', $id, 'تغيير الرابط', 'g', 'الرابط القديم سيتوقف عن العمل. متابعة؟') ?>
  <p><button type="button" class="btn g" onclick="this.closest('dialog').close()">إغلاق</button></p>
</dialog>
<?php endforeach; ?>

<h3>سجل الدفعات</h3>
<?= table(['التاريخ', 'الشيفت', 'المندوب', 'المبلغ', 'الطريقة', 'ملاحظة'], array_map(fn($p) => [$p['d'], $p['shift'] ?: '—', $p['dname'], N($p['amount']), pay_full($p['method'] ?? 'cash'), $p['note']], $P),
function ($i) use ($P) {
    return can('del') ? '<form class="inl" method="post" action="index.php?p=drivers" onsubmit="return confirm(\'حذف الدفعة؟\')">' . csrf_field()
        . '<input type="hidden" name="act" value="delpay"><input type="hidden" name="id" value="' . (int)$P[$i]['id'] . '"><button class="btn r s">حذف</button></form>' : '';
}) ?>
