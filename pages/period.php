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
$q = $db->prepare("SELECT COALESCE(c.phone, o.phone) AS phone, c.name, COUNT(*) n, SUM(o.val) v FROM orders o LEFT JOIN customers c ON c.id=o.customer_id " . str_replace(['d>=', 'd<=', 'shift='], ['o.d>=', 'o.d<=', 'o.shift='], $where) . " GROUP BY COALESCE(o.customer_id, o.phone) ORDER BY v DESC");
$q->execute($a);
$G = $q->fetchAll();
$q = $db->prepare("SELECT d, COUNT(*) n, SUM(val) v, SUM(fee) f,
  SUM(CASE WHEN pay_method='cash' THEN val+fee ELSE 0 END) pc, SUM(CASE WHEN pay_method='visa' THEN val+fee ELSE 0 END) pv,
  SUM(CASE WHEN pay_method='instapay' THEN val+fee ELSE 0 END) pn FROM orders $where GROUP BY d ORDER BY d");
$q->execute($a);
$Dy = $q->fetchAll();
$q = $db->prepare("SELECT pay_method pm, COUNT(*) n, COALESCE(SUM(val),0) v, COALESCE(SUM(fee),0) f FROM orders $where GROUP BY pay_method");
$q->execute($a);
$PM = array_fill_keys(array_keys(PAY), ['n' => 0, 'v' => 0, 'f' => 0]);
foreach ($q->fetchAll() as $r) $PM[pay_pick($r['pm'])] = ['n' => (int)$r['n'], 'v' => (float)$r['v'], 'f' => (float)$r['f']];
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
<h3>حسب طريقة الدفع</h3>
<?= table(['الطريقة', 'الأوردرات', 'القيمة', 'التوصيل', 'الإجمالي'], array_map(fn($k) => [pay_full($k), N($PM[$k]['n']), N($PM[$k]['v']), N($PM[$k]['f']), N($PM[$k]['v'] + $PM[$k]['f'])], array_keys(PAY))) ?>
<h3>حسب العميل</h3>
<?= table($hc, array_slice($rc, 0, 300)) ?>
<?= $cnt > 300 ? '<p class="mu no">معروض أول 300 عميل. التصدير يشمل الكل.</p>' : '' ?>
<h3>حسب اليوم</h3>
<?= table(['اليوم', 'الأوردرات', 'القيمة', 'التوصيل', '💵 كاش', '💳 فيزا', '📲 إنستا باي'], array_map(fn($g) => [$g['d'], N($g['n']), N($g['v']), N($g['f']), N($g['pc']), N($g['pv']), N($g['pn'])], $Dy)) ?>

<?php
$dw=["o.status='done'","o.out_t<>''","o.ret_t<>''"]; $da=[];
if(valid_date($_GET['from']??'')){$dw[]='o.d>=?';$da[]=$_GET['from'];} if(valid_date($_GET['to']??'')){$dw[]='o.d<=?';$da[]=$_GET['to'];} shift_where($dw,$da,'o.shift');
$perf=$db->prepare("SELECT dr.id,dr.name,COUNT(*) n,AVG((CASE WHEN time(o.ret_t)>=time(o.out_t) THEN (julianday(o.d||' '||o.ret_t)-julianday(o.d||' '||o.out_t))*86400 ELSE (julianday(o.d||' '||o.ret_t,'+1 day')-julianday(o.d||' '||o.out_t))*86400 END)) avg_sec, SUM(CASE WHEN time(o.ret_t)>=time(o.out_t) THEN (julianday(o.d||' '||o.ret_t)-julianday(o.d||' '||o.out_t))*86400 ELSE (julianday(o.d||' '||o.ret_t,'+1 day')-julianday(o.d||' '||o.out_t))*86400 END) total_sec FROM orders o JOIN drivers dr ON dr.id=o.driver_id WHERE ".implode(' AND ',$dw)." GROUP BY dr.id HAVING total_sec>0 ORDER BY avg_sec");
$perf->execute($da); $DP=$perf->fetchAll();
$allHours=array_sum(array_column($DP,'total_sec'))/3600; $allOrders=array_sum(array_column($DP,'n')); $fast=$DP[0]??null; $slowest=$DP?end($DP):null;
?>
<h3>أداء المناديب (الأوردرات المكتملة ذات وقت خروج وعودة مسجل)</h3>
<div class="k"><div><b><?= $fast?h($fast['name']):'—' ?></b><span>أسرع مندوب (متوسط <?= $fast?round($fast['avg_sec']/60):0 ?> دقيقة)</span></div><div><b><?= $slowest?h($slowest['name']):'—' ?></b><span>أبطأ مندوب (متوسط <?= $slowest?round($slowest['avg_sec']/60):0 ?> دقيقة)</span></div><div><b><?= $allHours?round($allOrders/$allHours,2):0 ?></b><span>أوردر / ساعة (إجمالي)</span></div></div>
<?= table(['المندوب','الأوردرات المكتملة','متوسط وقت التوصيل','إجمالي ساعات التوصيل','أوردر/ساعة'],array_map(fn($r)=>[$r['name'],(int)$r['n'],round($r['avg_sec']/60).' دقيقة',round($r['total_sec']/3600,2),round($r['total_sec']>0?$r['n']/($r['total_sec']/3600):0,2)],$DP)) ?>
<p class="mu">الحساب يعتمد على وقت الخروج والعودة المسجلين في الأوردر. الأوردرات التي لا تحتوي الوقتين لا تدخل في متوسطات الأداء.</p>
