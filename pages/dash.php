<?php
defined('APP') || exit;
$db = db();
$sw = []; $sa = [];
shift_where($sw, $sa);
$sWhere = $sw ? 'WHERE ' . implode(' AND ', $sw) : '';
$sAnd = $sw ? ' AND ' . implode(' AND ', $sw) : '';
$q = $db->prepare("SELECT d, SUM(val+fee) t FROM orders $sWhere GROUP BY d ORDER BY d DESC LIMIT 21");
$q->execute($sa);
$days = array_reverse($q->fetchAll());
$mx = 1;
foreach ($days as $x) $mx = max($mx, (float)$x['t']);
$q = $db->prepare("SELECT MAX(d) FROM orders $sWhere");
$q->execute($sa);
$last = $q->fetchColumn();
$mo = $last ? substr($last, 0, 7) : '';
$like = $mo . '%';

$st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(val),0) v, COALESCE(SUM(fee),0) f, COUNT(DISTINCT phone) c,
  COALESCE(SUM(shift='صباحي'),0) am, COALESCE(SUM(shift='مسائي'),0) pm FROM orders WHERE d LIKE ?$sAnd");
$st->execute(array_merge([$like], $sa));
$M = $st->fetch();

$q = $db->prepare('SELECT dr.name, COUNT(*) n, SUM(o.fee) f FROM orders o JOIN drivers dr ON dr.id=o.driver_id WHERE o.d LIKE ?' . str_replace('shift', 'o.shift', $sAnd) . ' GROUP BY dr.id ORDER BY n DESC LIMIT 5');
$q->execute(array_merge([$like], $sa));
$top = $q->fetchAll();
$q = $db->prepare("SELECT o.phone, c.name, COUNT(*) n, SUM(o.val) v FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.d LIKE ?" . str_replace('shift', 'o.shift', $sAnd) . " GROUP BY o.phone ORDER BY v DESC LIMIT 5");
$q->execute(array_merge([$like], $sa));
$tc = $q->fetchAll();

$q = $db->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('new','out')$sAnd");
$q->execute($sa);
$active = (int)$q->fetchColumn();
$live = (int)$db->query('SELECT COUNT(*) FROM drivers WHERE active=1 AND last_at>' . (time() - 600))->fetchColumn();
?>
<div class="k">
  <div><b><?= $active ?></b><span>أوردرات جارية الآن</span></div>
  <div><b><?= $live ?></b><span>مناديب ظاهرون على الخريطة (آخر 10 دقائق)</span></div>
</div>
<p class="mu"><?= cur_shift() !== '' ? 'الشيفت ' . h(cur_shift()) . ' — ' : '' ?>بيانات شهر <?= h($mo ?: '—') ?> (آخر شهر مسجّل) — آخر 21 يوم عمل:</p>
<div class="bars">
<?php foreach ($days as $x): ?>
  <div title="<?= h($x['d']) ?>: <?= N($x['t']) ?> ج"><i style="height:<?= round($x['t'] / $mx * 100, 1) ?>%"></i><small><?= h(substr($x['d'], 8)) ?></small></div>
<?php endforeach; ?>
</div>
<div class="k" style="margin-top:16px">
  <div><b><?= N($M['n']) ?></b><span>أوردر في الشهر</span></div>
  <div><b><?= N($M['v']) ?></b><span>قيمة الأوردرات</span></div>
  <div><b><?= N($M['f']) ?></b><span>خدمة التوصيل</span></div>
  <div><b><?= N($M['c']) ?></b><span>عميل</span></div>
  <div><b><?= (int)$M['am'] ?> / <?= (int)$M['pm'] ?></b><span>صباحي / مسائي</span></div>
</div>
<h3>أكثر المندوبين أوردرات</h3>
<?= table(['المندوب', 'الأوردرات', 'خدمة التوصيل'], array_map(fn($r) => [$r['name'], N($r['n']), N($r['f'])], $top)) ?>
<h3>أعلى العملاء مسحوبات</h3>
<?= table(['العميل', 'الأوردرات', 'المسحوبات'], array_map(fn($r) => [($r['name'] ? $r['name'] . ' — ' : '') . $r['phone'], N($r['n']), N($r['v'])], $tc)) ?>
