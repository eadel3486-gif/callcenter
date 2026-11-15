<?php
defined('APP') || exit;
$db = db();
$w = []; $a = [];
date_where($w, $a);
shift_where($w, $a);
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$q = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(val),0) v, COALESCE(SUM(fee),0) f FROM orders $where");
$q->execute($a);
$T = $q->fetch();
$q = $db->prepare("SELECT o.phone, c.name, COUNT(*) n, SUM(o.val) v FROM orders o LEFT JOIN customers c ON c.id=o.customer_id " . str_replace(['d>=', 'd<=', 'shift='], ['o.d>=', 'o.d<=', 'o.shift='], $where) . " GROUP BY o.phone ORDER BY v DESC");
$q->execute($a);
$G = $q->fetchAll();
$q = $db->prepare("SELECT d, COUNT(*) n, SUM(val) v, SUM(fee) f FROM orders $where GROUP BY d ORDER BY d");
$q->execute($a);
$Dy = $q->fetchAll();
$hc = ['الاسم', 'رقم العميل', 'المسحوبات', 'عدد العمليات'];
$rc = array_map(fn($g) => [$g['name'] ?? '', $g['phone'], N($g['v']), N($g['n'])], $G);
if (isset($_GET['export']) && can('export')) csv_out('period-customers', $hc, $rc);
$cnt = count($G);
?>
<form class="fb no" method="get">
  <input type="hidden" name="p" value="period">
  <?= dates_inputs() ?>
  <button class="btn">عرض</button> <a class="btn g" href="index.php?p=period">كل الفترة</a>
  <?php if (can('export')): ?><a class="btn g" href="<?= h(qs(['export' => 1])) ?>">تصدير CSV</a><button type="button" class="btn g" onclick="window.print()">طباعة</button><?php endif; ?>
</form>
<div class="k">
  <div><b><?= N($T['v'] + $T['f']) ?></b><span>إجمالي الفترة</span></div>
  <div><b><?= $cnt ?></b><span>عدد العملاء</span></div>
  <div><b><?= $cnt ? h($G[0]['phone']) : '—' ?></b><span>أعلى عميل (<?= $cnt ? N($G[0]['v']) : 0 ?>)</span></div>
  <div><b><?= N($cnt ? $T['v'] / $cnt : 0) ?></b><span>متوسط المسحوبات للعميل</span></div>
  <div><b><?= N($T['n']) ?></b><span>إجمالي العمليات</span></div>
</div>
<h3>حسب العميل</h3>
<?= table($hc, array_slice($rc, 0, 300)) ?>
<?= $cnt > 300 ? '<p class="mu no">معروض أول 300 عميل. التصدير يشمل الكل.</p>' : '' ?>
<h3>حسب اليوم</h3>
<?= table(['اليوم', 'الأوردرات', 'القيمة', 'التوصيل'], array_map(fn($g) => [$g['d'], N($g['n']), N($g['v']), N($g['f'])], $Dy)) ?>
