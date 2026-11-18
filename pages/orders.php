<?php
defined('APP') || exit;
$db = db();
$drivers = $db->query('SELECT id,name,active,tg_chat_id FROM drivers ORDER BY name')->fetchAll();
$dmap = array_column($drivers, null, 'id');
$form = null;   // لو فيه قيم نعرض الفورم
$us = user_shift();
// مستخدم الشيفت يتعامل مع أوردرات شيفته فقط
$orderOk = function ($id) use ($db, $us) {
    if ($us === '' || !$id) return true;
    $q = $db->prepare('SELECT shift FROM orders WHERE id=?');
    $q->execute([(int)$id]);
    return $q->fetchColumn() === $us;
};
$dupWarn = false;
$err = '';

// ---------- معالجة الإرسال ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'save' && can('edit')) {
        $id = (int)($_POST['id'] ?? 0);
        if (!$orderOk($id)) { flash('هذا الأوردر ليس من شيفتك', 'err'); redirect('index.php?p=orders'); }
        $shift = $us !== '' ? $us : ($_POST['s'] ?? 'صباحي');
        $status = $_POST['status'] ?? 'new';
        $o = [
            'd' => trim($_POST['d'] ?? ''),
            'shift' => in_array($shift, ['صباحي', 'مسائي'], true) ? $shift : 'صباحي',
            'bon' => trim($_POST['b'] ?? ''),
            'fee' => (float)($_POST['f'] ?? 0),
            'val' => (float)($_POST['v'] ?? 0),
            'phone' => trim($_POST['p'] ?? ''), 'cn' => trim($_POST['cn'] ?? ''),
            'driver_id' => (int)($_POST['m'] ?? 0) ?: null,
            'out_t' => trim($_POST['o'] ?? ''),
            'ret_t' => trim($_POST['r'] ?? ''),
            'addr' => trim($_POST['a'] ?? ''),
            'pay_method' => pay_pick($_POST['pm'] ?? 'cash'),
            'pay_ref' => trim($_POST['pr'] ?? ''),
            'status' => isset(STATUS[$status]) ? $status : 'new',
        ];
        if ($o['pay_method'] === 'cash') $o['pay_ref'] = '';   // المرجع للفيزا/إنستا باي فقط
        if (!valid_date($o['d']) || $o['phone'] === '') {
            $err = 'التاريخ ورقم العميل مطلوبان';
        } elseif ($o['bon'] !== '' && empty($_POST['force'])) {
            $c = $db->prepare('SELECT COUNT(*) FROM orders WHERE bon=? AND id<>? AND shift=?');
            $c->execute([$o['bon'], $id, $o['shift']]);
            if ((int)$c->fetchColumn() > 0) { $err = 'رقم البون مكرر. فعّل «السماح بالتكرار» ثم احفظ لو ده مقصود.'; $dupWarn = true; }
        }
        if ($err !== '') {
            $form = $o + ['id' => $id];
        } else {
            if ($o['status'] === 'out' && $o['out_t'] === '') $o['out_t'] = date('H:i');
            if ($o['status'] === 'done' && $o['ret_t'] === '') $o['ret_t'] = date('H:i');
            $cust = ensure_customer($o['phone'], $o['cn'], $o['addr']);
            $o['customer_id'] = $cust ? (int)$cust['id'] : null;
            $oldDriver = null;
            if ($id) {
                $q = $db->prepare('SELECT driver_id FROM orders WHERE id=?');
                $q->execute([$id]);
                $oldDriver = $q->fetchColumn();
                $db->prepare('UPDATE orders SET d=?,shift=?,bon=?,fee=?,val=?,phone=?,driver_id=?,out_t=?,ret_t=?,addr=?,status=?,customer_id=?,pay_method=?,pay_ref=? WHERE id=?')
                   ->execute([$o['d'], $o['shift'], $o['bon'], $o['fee'], $o['val'], $o['phone'], $o['driver_id'], $o['out_t'], $o['ret_t'], $o['addr'], $o['status'], $o['customer_id'], $o['pay_method'], $o['pay_ref'], $id]);
            } else {
                $db->prepare('INSERT INTO orders(d,shift,bon,fee,val,phone,driver_id,out_t,ret_t,addr,status,created_at,customer_id,pay_method,pay_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$o['d'], $o['shift'], $o['bon'], $o['fee'], $o['val'], $o['phone'], $o['driver_id'], $o['out_t'], $o['ret_t'], $o['addr'], $o['status'], time(), $o['customer_id'], $o['pay_method'], $o['pay_ref']]);
                $id = (int)$db->lastInsertId();
            }
            $msg = 'تم حفظ الأوردر';
            if ($o['driver_id'] && $o['status'] !== 'done' && (int)$oldDriver !== (int)$o['driver_id']) {
                if (empty($dmap[$o['driver_id']]['tg_chat_id'])) $msg .= ' — المندوب غير مربوط بتليجرام (لم يُرسل له الأوردر)';
                elseif (notify_order($id)) $msg .= ' وتم إرساله للمندوب على تليجرام ✅';
                else $msg .= ' — تعذر الإرسال لتليجرام، تأكد من إعدادات البوت';
            }
            flash($msg);
            redirect('index.php?p=orders');
        }
    } elseif ($act === 'delete' && can('del')) {
        if (!$orderOk((int)($_POST['id'] ?? 0))) { flash('هذا الأوردر ليس من شيفتك', 'err'); redirect('index.php?p=orders'); }
        $db->prepare('DELETE FROM orders WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('تم حذف الأوردر');
        redirect(qs(['edit' => null]));
    } elseif ($act === 'resend' && can('edit')) {
        if (!$orderOk((int)($_POST['id'] ?? 0))) { flash('هذا الأوردر ليس من شيفتك', 'err'); redirect('index.php?p=orders'); }
        $ok = notify_order((int)($_POST['id'] ?? 0));
        flash($ok ? 'تم إرسال الأوردر للمندوب على تليجرام ✅' : 'تعذر الإرسال: المندوب غير مربوط أو البوت غير مضبوط', $ok ? 'ok' : 'err');
        redirect(qs());
    }
}

// ---------- فورم الإضافة/التعديل ----------
if ($form === null && isset($_GET['edit']) && can('edit')) {
    $eid = (int)$_GET['edit'];
    if ($eid && !$orderOk($eid)) { flash('هذا الأوردر ليس من شيفتك', 'err'); redirect('index.php?p=orders'); }
    if ($eid) {
        $q = $db->prepare('SELECT * FROM orders WHERE id=?');
        $q->execute([$eid]);
        $r = $q->fetch();
        if ($r) $form = ['id' => $r['id'], 'd' => $r['d'], 'shift' => $r['shift'], 'bon' => $r['bon'], 'fee' => $r['fee'], 'val' => $r['val'],
            'phone' => $r['phone'], 'driver_id' => $r['driver_id'], 'out_t' => $r['out_t'], 'ret_t' => $r['ret_t'], 'addr' => $r['addr'], 'status' => $r['status'],
            'pay_method' => pay_pick($r['pay_method']), 'pay_ref' => $r['pay_ref']];
    }
    if ($form === null) $form = ['id' => 0, 'd' => date('Y-m-d'), 'shift' => cur_shift() ?: 'صباحي', 'bon' => '', 'fee' => 15, 'val' => '', 'phone' => '',
        'driver_id' => null, 'out_t' => '', 'ret_t' => '', 'addr' => '', 'status' => 'new', 'pay_method' => 'cash', 'pay_ref' => ''];
}

if ($form !== null):
    if (!isset($form['cn'])) {
        $cs = $db->prepare('SELECT code,name FROM customers WHERE phone=?');
        $cs->execute([$form['phone']]);   // العميل المرتبط بالأوردر
        $cr = $cs->fetch();
        $form['cn'] = $cr['name'] ?? '';
        $form['ccode'] = $cr['code'] ?? '';
    }
    $fld = fn($name, $label, $val, $type = 'text') => '<label>' . h($label) . '<input name="' . $name . '" type="' . $type . '"' . ($type === 'number' ? ' step="any"' : '') . ' value="' . h($val) . '"></label>';
?>
<?php if ($err): ?><div class="fl err"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="u" style="max-width:640px">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
  <h3 style="margin-top:0"><?= $form['id'] ? 'تعديل' : 'إضافة' ?> أوردر</h3>
  <div class="fg">
    <?= $fld('d', 'التاريخ', $form['d'], 'date') ?>
    <?php if ($us !== ''): ?><label>الشفت<input value="<?= h($us) ?>" disabled></label>
    <?php else: ?><label>الشفت<select name="s"><?php foreach (['صباحي', 'مسائي'] as $x): ?><option <?= $x === $form['shift'] ? 'selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <?= $fld('b', 'رقم البون', $form['bon']) ?>
    <label>رقم العميل<input name="p" id="fp" value="<?= h($form['phone']) ?>" list="cl" autocomplete="off"><datalist id="cl"></datalist></label>
    <label>اسم العميل <span class="sub" id="fc"><?= $form['ccode'] ?? '' ? '(' . h($form['ccode']) . ')' : '' ?></span><input name="cn" id="fn" value="<?= h($form['cn'] ?? '') ?>"></label>
    <?= $fld('v', 'قيمة الأوردر', $form['val'], 'number') ?>
    <?= $fld('f', 'خدمة التوصيل', $form['fee'], 'number') ?>
    <label>المندوب<select name="m"><option value="">—</option>
      <?php foreach ($drivers as $dr) if ($dr['active'] || $dr['id'] == $form['driver_id']): ?>
        <option value="<?= $dr['id'] ?>" <?= $dr['id'] == $form['driver_id'] ? 'selected' : '' ?>><?= h($dr['name']) ?><?= $dr['tg_chat_id'] ? ' ✈️' : '' ?></option>
      <?php endif; ?></select></label>
    <label>الحالة<select name="status"><?php foreach (STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $k === $form['status'] ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
    <label>طريقة الدفع<?= pay_select('pm', $form['pay_method'], false, ' id="fpm"') ?></label>
    <label id="fprw" style="<?= $form['pay_method'] === 'cash' ? 'display:none' : '' ?>">مرجع العملية (رقم الإيصال / التحويل)<input name="pr" value="<?= h($form['pay_ref']) ?>"></label>
    <label style="grid-column:1/-1">العنوان<input name="a" id="fa" value="<?= h($form['addr']) ?>"></label>
    <?= $fld('o', 'خروج (مثال 11:09)', $form['out_t']) ?>
    <?= $fld('r', 'عودة (مثال 11:24)', $form['ret_t']) ?>
  </div>
  <p class="mu" id="fpn"></p>
  <p class="mu">✈️ = المندوب مربوط بتليجرام، وعند اختياره يوصله الأوردر فوراً بأزرار «خرجت» و«تم التسليم».</p>
  <?php if ($dupWarn): ?><label class="pm"><input type="checkbox" name="force" value="1"> السماح بتكرار رقم البون</label><?php endif; ?>
  <p><button class="btn">حفظ</button> <a class="btn g" href="index.php?p=orders">إلغاء</a></p>
</form>
<script>
(function () {
  const fp = document.getElementById('fp'), fn = document.getElementById('fn'), fa = document.getElementById('fa'), fc = document.getElementById('fc'), cl = document.getElementById('cl');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let t;
  fp.addEventListener('input', () => {
    clearTimeout(t);
    const q = fp.value.trim();
    if (q.length < 3) return;
    t = setTimeout(async () => {
      try {
        const j = await (await fetch('api.php?a=customers&q=' + encodeURIComponent(q))).json();
        cl.innerHTML = (j.customers || []).map(c => '<option value="' + esc(c.phone) + '">' + esc(c.code + ' — ' + c.name) + '</option>').join('');
      } catch (e) {}
    }, 250);
  });
  async function fill() {
    const q = fp.value.trim();
    if (!q) return;
    try {
      const j = await (await fetch('api.php?a=customer&phone=' + encodeURIComponent(q))).json();
      if (j.customer) {
        if (!fn.value) fn.value = j.customer.name;
        if (!fa.value) fa.value = j.customer.address;
        fc.textContent = '(' + j.customer.code + ')';
      } else { fc.textContent = '(عميل جديد — هيتكوّد تلقائياً)'; }
    } catch (e) {}
  }
  const pm = document.getElementById('fpm'), prw = document.getElementById('fprw'), fpn = document.getElementById('fpn');
  function payUi() {
    const cash = pm.value === 'cash';
    prw.style.display = cash ? 'none' : '';
    fpn.textContent = cash ? '💵 كاش: المندوب يحصّل (القيمة + التوصيل) من العميل وتُحسب عهدة عليه.' : '✅ مدفوع إلكترونياً: لا يحصّل المندوب شيئاً ولا تُحسب عهدة عليه، وبتظهر للمندوب على تليجرام «مدفوع مسبقاً».';
  }
  pm.addEventListener('change', payUi); payUi();
  fp.addEventListener('change', fill);
  fp.addEventListener('blur', fill);
})();
</script>
<?php
    return;
endif;

// ---------- القائمة ----------
$w = []; $a = [];
date_where($w, $a, 'o.d');
$fs = cur_shift() ?: ($_GET['s'] ?? ''); $fm = (int)($_GET['m'] ?? 0); $fq = trim($_GET['q'] ?? ''); $fst = $_GET['st'] ?? ''; $fpm = $_GET['pm'] ?? '';
if (in_array($fs, ['صباحي', 'مسائي'], true)) { $w[] = 'o.shift=?'; $a[] = $fs; }
if ($fm) { $w[] = 'o.driver_id=?'; $a[] = $fm; }
if (isset(STATUS[$fst])) { $w[] = 'o.status=?'; $a[] = $fst; }
if (pay_ok($fpm)) { $w[] = 'o.pay_method=?'; $a[] = $fpm; }
if ($fq !== '') {
    $w[] = '(o.phone LIKE ? OR o.bon LIKE ? OR o.addr LIKE ? OR cu.name LIKE ? OR cu.code LIKE ?)';
    $like = '%' . $fq . '%';
    array_push($a, $like, $like, $like, $like, $like);
}
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$base = "FROM orders o LEFT JOIN drivers dr ON dr.id=o.driver_id LEFT JOIN customers cu ON cu.id=o.customer_id $where";

$q = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.val),0) v, COALESCE(SUM(o.fee),0) f $base");
$q->execute($a);
$T = $q->fetch();

// ملخص حسب طريقة الدفع (بنفس الفلاتر)
$q = $db->prepare("SELECT o.pay_method pm, COUNT(*) n, COALESCE(SUM(o.val+o.fee),0) t $base GROUP BY o.pay_method");
$q->execute($a);
$PM = array_fill_keys(array_keys(PAY), ['n' => 0, 't' => 0]);
foreach ($q->fetchAll() as $r) $PM[pay_pick($r['pm'])] = ['n' => (int)$r['n'], 't' => (float)$r['t']];

$order = 'ORDER BY o.d DESC, CAST(o.bon AS INTEGER) DESC, o.id DESC';
$cols = 'o.*, dr.name AS dname, dr.tg_chat_id AS dtg, cu.name AS cname, cu.code AS ccode';
$export = isset($_GET['export']) && can('export');
$per = 100;
$pg = max(1, (int)($_GET['pg'] ?? 1));
$sql = "SELECT $cols $base $order" . ($export ? '' : ' LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per));
$q = $db->prepare($sql);
$q->execute($a);
$L = $q->fetchAll();

$heads = ['التاريخ', 'الشفت', 'البون', 'كود', 'الاسم', 'العميل', 'المندوب', 'القيمة', 'التوصيل', 'الإجمالي', 'طريقة الدفع', 'مرجع الدفع', 'خروج', 'عودة', 'العنوان', 'الحالة'];
$rows = array_map(fn($o) => [$o['d'], $o['shift'], $o['bon'], $o['ccode'] ?? '', $o['cname'] ?? '', $o['phone'], $o['dname'] ?? '', N($o['val']), N($o['fee']), N($o['val'] + $o['fee']), pay_label($o['pay_method']), $o['pay_ref'],
    $o['out_t'], $o['ret_t'], $o['addr'], STATUS[$o['status']] ?? ''], $L);
if ($export) csv_out('orders', $heads, $rows);
?>
<form class="fb no" method="get">
  <input type="hidden" name="p" value="orders">
  <?= dates_inputs() ?>
  <?php if (cur_shift() === ''): ?><label>الشفت<select name="s"><option value="">الكل</option><?php foreach (['صباحي', 'مسائي'] as $x): ?><option <?= $x === $fs ? 'selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label>المندوب<select name="m"><option value="">الكل</option><?php foreach ($drivers as $dr): ?><option value="<?= $dr['id'] ?>" <?= $dr['id'] == $fm ? 'selected' : '' ?>><?= h($dr['name']) ?></option><?php endforeach; ?></select></label>
  <label>الحالة<select name="st"><option value="">الكل</option><?php foreach (STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $k === $fst ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
  <label>طريقة الدفع<?= pay_select('pm', $fpm, true) ?></label>
  <label>بحث (رقم/اسم/كود/بون/عنوان)<input name="q" value="<?= h($fq) ?>"></label>
  <button class="btn">عرض</button>
  <a class="btn g" href="index.php?p=orders">مسح الفلتر</a>
  <?php if (can('edit')): ?><a class="btn" href="index.php?p=orders&edit=0">+ أوردر جديد</a><?php endif; ?>
  <?php if (can('export')): ?><a class="btn g" href="<?= h(qs(['export' => 1])) ?>">تصدير CSV</a><button type="button" class="btn g" onclick="window.print()">طباعة</button><?php endif; ?>
</form>
<div class="k">
  <div><b><?= N($T['n']) ?></b><span>عدد الأوردرات</span></div>
  <div><b><?= N($T['v']) ?></b><span>قيمة الأوردرات</span></div>
  <div><b><?= N($T['f']) ?></b><span>خدمة التوصيل</span></div>
  <?php foreach (PAY as $k => $v): ?><div><b><?= N($PM[$k]['t']) ?></b><span><?= PAY_ICON[$k] ?> <?= h($v) ?> (<?= (int)$PM[$k]['n'] ?> أوردر)</span></div><?php endforeach; ?>
</div>
<?= table($heads, $rows, function ($i, $r) use ($L) {
    $o = $L[$i];
    $s = '';
    if (can('edit')) $s .= '<a class="btn g s" href="index.php?p=orders&edit=' . (int)$o['id'] . '">تعديل</a> ';
    if (can('edit') && $o['driver_id'] && $o['dtg'] && $o['status'] !== 'done')
        $s .= '<form class="inl" method="post" action="' . h(qs()) . '">' . csrf_field() . '<input type="hidden" name="act" value="resend"><input type="hidden" name="id" value="' . (int)$o['id'] . '"><button class="btn g s">إرسال لتليجرام</button></form> ';
    if (can('del'))
        $s .= '<form class="inl" method="post" action="' . h(qs()) . '" onsubmit="return confirm(\'حذف هذا الأوردر نهائياً؟\')">' . csrf_field() . '<input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="' . (int)$o['id'] . '"><button class="btn r s">حذف</button></form>';
    return $s;
}) ?>
<?= pager((int)$T['n'], $per, $pg) ?>
