<?php
defined('APP') || exit;
$db = db();

// ---------- إضافة / تعديل / حذف عميل ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'save' && can('edit')) {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $addr = trim($_POST['addr'] ?? '');
        $phone2 = trim($_POST['phone2'] ?? '');
        $addr2 = trim($_POST['addr2'] ?? '');
        $notes = trim($_POST['notes'] ?? ''); $problem = !empty($_POST['problem']) ? 1 : 0;
        if ($phone === '') {
            flash('رقم العميل مطلوب', 'err');
            redirect(qs(['ec' => $id ?: 0]));
        }
        if ($phone2 !== '' && $phone2 === $phone) {
            flash('الرقم التاني نفس الرقم الأساسي', 'err');
            redirect(qs(['ec' => $id ?: 0]));
        }
        // الرقمين (الأساسي والتاني) ما ينفعش يكونوا مسجلين عند عميل آخر
        $nums = array_values(array_filter([$phone, $phone2], fn($x) => $x !== ''));
        $ph = implode(',', array_fill(0, count($nums), '?'));
        $dup = $db->prepare("SELECT id FROM customers WHERE id<>? AND (phone IN ($ph) OR phone2 IN ($ph))");
        $dup->execute(array_merge([$id], $nums, $nums));
        if ($dup->fetchColumn()) {
            flash('أحد الرقمين مسجّل لعميل آخر', 'err');
            redirect(qs(['ec' => $id ?: 0]));
        }
        if ($id) {
            $q = $db->prepare('SELECT phone, phone2 FROM customers WHERE id=?');
            $q->execute([$id]);
            $old = $q->fetch();
            $db->prepare('UPDATE customers SET name=?, phone=?, address=?, phone2=?, address2=?, notes=?, problem=? WHERE id=?')->execute([$name, $phone, $addr, $phone2, $addr2, $notes, $problem, $id]);
            // لو رقم اتغيّر، يتغيّر كمان في أوردرات العميل السابقة
            if ($old) foreach ([[$old['phone'], $phone], [$old['phone2'], $phone2]] as [$o, $n])
                if ($o !== '' && $n !== '' && $o !== $n) $db->prepare('UPDATE orders SET phone=? WHERE customer_id=? AND phone=?')->execute([$n, $id, $o]);
            flash('تم حفظ بيانات العميل');
        } else {
            $c = ensure_customer($phone, $name, $addr);
            if ($c) $db->prepare('UPDATE customers SET phone2=?, address2=?, notes=?, problem=? WHERE id=?')->execute([$phone2, $addr2, $notes, $problem, $c['id']]);
            flash('تمت إضافة العميل وتكويده');
        }
        redirect(qs(['ec' => null]));
    } elseif ($act === 'merge' && can('edit')) {
        $keep=(int)($_POST['keep_id']??0); $remove=(int)($_POST['remove_id']??0);
        if (!$keep || !$remove || $keep===$remove) flash('اختر عميلين مختلفين للدمج','err');
        else {
            $db->beginTransaction();
            $a=$db->prepare('SELECT * FROM customers WHERE id=?'); $a->execute([$keep]); $main=$a->fetch();
            $b=$db->prepare('SELECT * FROM customers WHERE id=?'); $b->execute([$remove]); $other=$b->fetch();
            if (!$main || !$other) { $db->rollBack(); flash('أحد العميلين غير موجود','err'); }
            else {
                $phones=array_values(array_unique(array_filter([$main['phone'],$main['phone2'],$other['phone'],$other['phone2']])));
                $p1=$main['phone']; $p2=''; foreach($phones as $ph) if($ph!==$p1){$p2=$ph;break;}
                $db->prepare('UPDATE orders SET customer_id=? WHERE customer_id=?')->execute([$keep,$remove]);
                $db->prepare("UPDATE customers SET phone=?,phone2=?,name=CASE WHEN name='' THEN ? ELSE name END,address=CASE WHEN address='' THEN ? ELSE address END,address2=CASE WHEN address2='' THEN ? ELSE address2 END,notes=TRIM(CASE WHEN notes='' THEN ? WHEN ?='' THEN notes ELSE notes||char(10)||? END),problem=MAX(problem,?) WHERE id=?")->execute([$p1,$p2,$other['name'],$other['address'],$other['address2'],$other['notes'],$other['notes'],$other['notes'],$other['problem'],$keep]);
                $db->prepare('DELETE FROM customers WHERE id=?')->execute([$remove]); $db->commit(); flash('تم دمج العميلين ونقل كل الأوردرات للسجل الأساسي');
            }
        }
        redirect('index.php?p=customers');
    } elseif ($act === 'delete' && can('del')) {
        $cnt = $db->prepare('SELECT COUNT(*) FROM orders WHERE customer_id=?');
        $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() > 0) flash('لا يمكن حذف عميل له أوردرات مسجّلة. عدّل بياناته بدلاً من الحذف.', 'err');
        else { $db->prepare('DELETE FROM customers WHERE id=?')->execute([$id]); flash('تم حذف العميل'); }
        redirect(qs(['ec' => null]));
    }
}

// ---------- فورم إضافة/تعديل ----------
if (isset($_GET['ec']) && can('edit')):
    $ec = (int)$_GET['ec'];
    $row = ['id' => 0, 'code' => '', 'name' => '', 'phone' => '', 'address' => '', 'phone2' => '', 'address2' => '', 'notes'=>'', 'problem'=>0];
    if ($ec) {
        $q = $db->prepare('SELECT * FROM customers WHERE id=?');
        $q->execute([$ec]);
        $row = $q->fetch() ?: $row;
    }
?>
<form method="post" class="u" style="max-width:560px">
  <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
  <h3 style="margin-top:0"><?= $row['id'] ? 'تعديل العميل ' . h($row['code']) : 'عميل جديد' ?></h3>
  <label>الاسم<input name="name" value="<?= h($row['name']) ?>"></label>
  <label>رقم الموبايل (الأساسي)<input name="phone" value="<?= h($row['phone']) ?>"></label>
  <label>رقم موبايل تاني (اختياري)<input name="phone2" value="<?= h($row['phone2'] ?? '') ?>"></label>
  <label>العنوان (الأساسي)<input name="addr" value="<?= h($row['address']) ?>"></label>
  <label>عنوان تاني (اختياري)<input name="addr2" value="<?= h($row['address2'] ?? '') ?>"></label>
  <label>ملاحظات للمندوب<textarea name="notes" rows="3"><?= h($row['notes'] ?? '') ?></textarea></label>
  <label class="pm"><input type="checkbox" name="problem" value="1" <?= !empty($row['problem'])?'checked':'' ?>> ⚠️ عميل مشكلة</label>
  <?php if ($row['id']): ?><p class="mu">لو غيّرت الرقم، بيتغيّر كمان في كل أوردرات العميل السابقة.</p><?php else: ?><p class="mu">الكود بيتولّد تلقائياً (C0001، C0002…).</p><?php endif; ?>
  <p><button class="btn">حفظ</button> <a class="btn g" href="<?= h(qs(['ec' => null])) ?>">إلغاء</a></p>
</form>
<?php
    return;
endif;

// ---------- القائمة (مع إحصائيات الشيفت والفترة) ----------
$ow = []; $oa = [];
date_where($ow, $oa);
shift_where($ow, $oa);
$oWhere = $ow ? 'WHERE ' . implode(' AND ', $ow) : '';
$filtered = (bool)$ow;   // لو فيه شيفت/تاريخ: اعرض فقط من عنده أوردرات في النطاق

$fq = trim($_GET['q'] ?? '');
$cw = []; $ca = [];
if ($fq !== '') {
    $cw[] = '(c.phone LIKE ? OR c.phone2 LIKE ? OR c.name LIKE ? OR c.code LIKE ? OR c.address LIKE ? OR c.address2 LIKE ?)';
    $like = '%' . $fq . '%';
    array_push($ca, $like, $like, $like, $like, $like, $like);
}
if ($filtered && empty($_GET['all'])) $cw[] = 's.n > 0';
$cWhere = $cw ? 'WHERE ' . implode(' AND ', $cw) : '';

$sql = "SELECT c.*, COALESCE(s.n,0) n, COALESCE(s.v,0) v, COALESCE(s.f,0) f, s.l
        FROM customers c
        LEFT JOIN (SELECT customer_id, COUNT(*) n, SUM(val) v, SUM(fee) f, MAX(d) l FROM orders $oWhere GROUP BY customer_id) s ON s.customer_id=c.id
        $cWhere ORDER BY COALESCE(s.v,0) DESC, c.id";
$q = $db->prepare($sql);
$q->execute(array_merge($oa, $ca));
$G = $q->fetchAll();

$heads = ['الكود', 'الاسم', 'رقم العميل', 'رقم تاني', 'العنوان', 'عنوان تاني', 'الأوردرات', 'المسحوبات', 'التوصيل', 'آخر طلب'];
$rows = array_map(fn($g) => [$g['code'], $g['name'], $g['phone'], $g['phone2'], $g['address'], $g['address2'], N($g['n']), N($g['v']), N($g['f']), $g['l'] ?? ''], $G);
if (isset($_GET['export']) && can('export')) csv_out('customers', $heads, $rows);
$cu = trim($_GET['cu'] ?? '');
$shown = array_slice($G, 0, 300);
?>
<form class="fb no" method="get">
  <input type="hidden" name="p" value="customers">
  <?= dates_inputs() ?>
  <label>بحث (اسم/رقم/كود/عنوان)<input name="q" value="<?= h($fq) ?>"></label>
  <?php if ($filtered): ?><label class="pm"><input type="checkbox" name="all" value="1" <?= !empty($_GET['all']) ? 'checked' : '' ?>> إظهار العملاء بدون أوردرات في النطاق</label><?php endif; ?>
  <button class="btn">عرض</button> <a class="btn g" href="index.php?p=customers">مسح</a>
  <?php if (can('edit')): ?><a class="btn" href="<?= h(qs(['ec' => 0])) ?>">+ عميل جديد</a><?php endif; ?>
  <?php if (can('export')): ?><a class="btn g" href="<?= h(qs(['export' => 1])) ?>">تصدير CSV</a><button type="button" class="btn g" onclick="window.print()">طباعة</button><?php endif; ?>
</form>
<?php if (can('edit') && count($G)>1): ?><form method="post" class="fb u no" onsubmit="return confirm('دمج السجلين ونقل كل الأوردرات؟')"><?= csrf_field() ?><input type="hidden" name="act" value="merge"><b>دمج عميلين مكررين</b><label>السجل الأساسي (سيظل موجوداً)<select name="keep_id" required><?php foreach($G as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['code'].' — '.$c['name'].' — '.$c['phone']) ?></option><?php endforeach; ?></select></label><label>السجل المكرر (سيتم حذفه بعد نقل الأوردرات)<select name="remove_id" required><?php foreach($G as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['code'].' — '.$c['name'].' — '.$c['phone']) ?></option><?php endforeach; ?></select></label><button class="btn">دمج السجلين</button></form><?php endif; ?>
<p class="mu"><?= count($G) ?> عميل<?= cur_shift() !== '' ? ' — إحصائيات شيفت ' . h(cur_shift()) . ' فقط' : '' ?><?= count($G) > 300 ? ' (معروض أول 300 — التصدير يشمل الكل)' : '' ?></p>
<?= table($heads, array_slice($rows, 0, 300), function ($i) use ($shown) {
    $g = $shown[$i];
    $s = '<a class="btn g s" href="' . h(qs(['cu' => $g['phone']])) . '">تقرير</a> ';
    if (can('edit')) $s .= '<a class="btn g s" href="' . h(qs(['ec' => (int)$g['id']])) . '">تعديل</a> ';
    if (can('del')) $s .= '<form class="inl" method="post" action="' . h(qs()) . '" onsubmit="return confirm(\'حذف العميل من السجل؟\')">' . csrf_field()
        . '<input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="' . (int)$g['id'] . '"><button class="btn r s">حذف</button></form>';
    return $s;
}) ?>
<?php if ($cu !== ''):
    $w2 = ['o.customer_id=(SELECT id FROM customers WHERE phone=? OR phone2=? LIMIT 1)']; $a2 = [$cu, $cu];
    date_where($w2, $a2, 'o.d');
    shift_where($w2, $a2, 'o.shift');
    $q = $db->prepare('SELECT o.*, dr.name dname FROM orders o LEFT JOIN drivers dr ON dr.id=o.driver_id WHERE ' . implode(' AND ', $w2) . ' ORDER BY o.d DESC, o.id DESC');
    $q->execute($a2);
    $Dt = $q->fetchAll();
    $sv = array_sum(array_column($Dt, 'val')); $sf = array_sum(array_column($Dt, 'fee'));
    $ci = $db->prepare('SELECT * FROM customers WHERE phone=? OR phone2=?'); $ci->execute([$cu, $cu]); $info = $ci->fetch(); ?>
<h3>تقرير العميل <?= h(($info['code'] ?? '') . ' ' . ($info['name'] ?? '')) ?> — <?= h($cu) ?></h3>
<?php if (!empty($info['phone2'])): ?><p class="mu">رقم تاني: <?= h($info['phone2']) ?></p><?php endif; ?>
<?php if (!empty($info['address'])): ?><p class="mu">العنوان: <?= h($info['address']) ?></p><?php endif; ?>
<?php if (!empty($info['address2'])): ?><p class="mu">عنوان تاني: <?= h($info['address2']) ?></p><?php endif; ?>
<div class="k">
  <div><b><?= N($sv) ?></b><span>إجمالي المسحوبات</span></div>
  <div><b><?= N($sf) ?></b><span>إجمالي خدمة التوصيل</span></div>
  <div><b><?= count($Dt) ?></b><span>عدد الأوردرات</span></div>
</div>
<?= table(['التاريخ', 'الشفت', 'البون', 'المندوب', 'القيمة', 'التوصيل', 'الدفع', 'العنوان'], array_map(fn($o) => [$o['d'], $o['shift'], $o['bon'], $o['dname'] ?? '', N($o['val']), N($o['fee']), pay_full($o['pay_method'] ?? 'cash') . ($o['pay_ref'] !== '' ? ' (' . $o['pay_ref'] . ')' : ''), $o['addr']], $Dt)) ?>
<?php endif; ?>
