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
        if ($phone === '') {
            flash('رقم العميل مطلوب', 'err');
            redirect(qs(['ec' => $id ?: 0]));
        }
        $dup = $db->prepare('SELECT id FROM customers WHERE phone=? AND id<>?');
        $dup->execute([$phone, $id]);
        if ($dup->fetchColumn()) {
            flash('الرقم ده مسجّل لعميل آخر', 'err');
            redirect(qs(['ec' => $id ?: 0]));
        }
        if ($id) {
            $q = $db->prepare('SELECT phone FROM customers WHERE id=?');
            $q->execute([$id]);
            $old = $q->fetchColumn();
            $db->prepare('UPDATE customers SET name=?, phone=?, address=? WHERE id=?')->execute([$name, $phone, $addr, $id]);
            if ($old !== false && $old !== $phone) $db->prepare('UPDATE orders SET phone=? WHERE phone=?')->execute([$phone, $old]);
            flash('تم حفظ بيانات العميل');
        } else {
            ensure_customer($phone, $name, $addr);
            flash('تمت إضافة العميل وتكويده');
        }
        redirect(qs(['ec' => null]));
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
    $row = ['id' => 0, 'code' => '', 'name' => '', 'phone' => '', 'address' => ''];
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
  <label>رقم الموبايل<input name="phone" value="<?= h($row['phone']) ?>"></label>
  <label>العنوان<input name="addr" value="<?= h($row['address']) ?>"></label>
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
    $cw[] = '(c.phone LIKE ? OR c.name LIKE ? OR c.code LIKE ? OR c.address LIKE ?)';
    $like = '%' . $fq . '%';
    array_push($ca, $like, $like, $like, $like);
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

$heads = ['الكود', 'الاسم', 'رقم العميل', 'العنوان', 'الأوردرات', 'المسحوبات', 'التوصيل', 'آخر طلب'];
$rows = array_map(fn($g) => [$g['code'], $g['name'], $g['phone'], $g['address'], N($g['n']), N($g['v']), N($g['f']), $g['l'] ?? ''], $G);
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
    $w2 = ['o.customer_id=(SELECT id FROM customers WHERE phone=?)']; $a2 = [$cu];
    date_where($w2, $a2, 'o.d');
    shift_where($w2, $a2, 'o.shift');
    $q = $db->prepare('SELECT o.*, dr.name dname FROM orders o LEFT JOIN drivers dr ON dr.id=o.driver_id WHERE ' . implode(' AND ', $w2) . ' ORDER BY o.d DESC, o.id DESC');
    $q->execute($a2);
    $Dt = $q->fetchAll();
    $sv = array_sum(array_column($Dt, 'val')); $sf = array_sum(array_column($Dt, 'fee'));
    $ci = $db->prepare('SELECT * FROM customers WHERE phone=?'); $ci->execute([$cu]); $info = $ci->fetch(); ?>
<h3>تقرير العميل <?= h(($info['code'] ?? '') . ' ' . ($info['name'] ?? '')) ?> — <?= h($cu) ?></h3>
<?php if (!empty($info['address'])): ?><p class="mu">العنوان: <?= h($info['address']) ?></p><?php endif; ?>
<div class="k">
  <div><b><?= N($sv) ?></b><span>إجمالي المسحوبات</span></div>
  <div><b><?= N($sf) ?></b><span>إجمالي خدمة التوصيل</span></div>
  <div><b><?= count($Dt) ?></b><span>عدد الأوردرات</span></div>
</div>
<?= table(['التاريخ', 'الشفت', 'البون', 'المندوب', 'القيمة', 'التوصيل', 'الدفع', 'العنوان'], array_map(fn($o) => [$o['d'], $o['shift'], $o['bon'], $o['dname'] ?? '', N($o['val']), N($o['fee']), pay_full($o['pay_method'] ?? 'cash') . ($o['pay_ref'] !== '' ? ' (' . $o['pay_ref'] . ')' : ''), $o['addr']], $Dt)) ?>
<?php endif; ?>
