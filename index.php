<?php
define('APP', 1);
require __DIR__ . '/lib.php';
session_boot();
$p = $_GET['p'] ?? 'dash';

if ($p === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}

$u = me();
if (!$u) {
    include __DIR__ . '/pages/login.php';
    exit;
}

// تبديل الشيفت (الكل / صباحي / مسائي) يُحفظ في الجلسة ويطبَّق على كل الصفحات
if (isset($_GET['shift'])) {
    $v = $_GET['shift'];
    $_SESSION['shift'] = in_array($v, ['صباحي', 'مسائي'], true) ? $v : '';
    $q = $_GET;
    unset($q['shift'], $q['pg']);
    redirect('index.php?' . http_build_query($q));
}
$curShift = cur_shift();

// الصفحة => [العنوان, الصلاحية المطلوبة (null = للمدير فقط)]
$PAGES = [
    'dash'      => ['الرئيسية', 'dash'],
    'orders'    => ['الأوردرات', 'orders'],
    'drivers'   => ['المندوبون وحساباتهم', 'drivers'],
    'tracking'  => ['تتبع المناديب', 'track'],
    'customers' => ['العملاء', 'customers'],
    'period'    => ['تقرير الفترة', 'period'],
    'users'     => ['المستخدمون والصلاحيات', null],
    'settings'  => ['إعدادات تليجرام', null],
];
$allowed = function ($k) use ($PAGES) {
    if (!isset($PAGES[$k])) return false;
    $perm = $PAGES[$k][1];
    return $perm === null ? (bool)me()['admin'] : can($perm);
};
if (!$allowed($p)) {
    $first = null;
    foreach (array_keys($PAGES) as $k) if ($allowed($k)) { $first = $k; break; }
    if ($first === null) { echo html_head('لا توجد صلاحيات') . '<p style="padding:30px">ليس لحسابك صلاحية على أي صفحة. راجع المدير.</p> <a href="?p=logout">خروج</a>'; exit; }
    redirect('index.php?p=' . $first);
}

ob_start();
include __DIR__ . '/pages/' . $p . '.php';
$content = ob_get_clean();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$warnPass = false;
if ($u['admin']) {
    if (empty($_SESSION['pwchk']) || $_SESSION['pwchk'][0] < time() - 300) {
        $bad = [];
        foreach (db()->query("SELECT name,username,pass_hash FROM users WHERE username IN ('admin','morning','evening')")->fetchAll() as $x)
            if (password_verify($x['username'] === 'admin' ? 'admin123' : '1234', $x['pass_hash'])) $bad[] = $x['name'];
        $_SESSION['pwchk'] = [time(), $bad];
    }
    $warnPass = $_SESSION['pwchk'][1];
}

echo html_head($PAGES[$p][0] . ' — ' . cfg('app_name'));
?>
<div class="shell">
<aside class="no">
  <h1>الكول سنتر</h1>
  <?php if (user_shift() === ''): ?>
  <div class="sh">
    <small>الشيفت</small>
    <?php foreach (['' => 'الكل', 'صباحي' => 'صباحي', 'مسائي' => 'مسائي'] as $sv => $sl): ?>
      <a class="<?= $curShift === $sv ? 'on' : '' ?>" href="<?= h(qs(['shift' => $sv === '' ? 'all' : $sv, 'pg' => null])) ?>"><?= h($sl) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php foreach ($PAGES as $k => $v) if ($allowed($k)): ?>
    <a class="<?= $k === $p ? 'on' : '' ?>" href="index.php?p=<?= h($k) ?>"><?= h($v[0]) ?></a>
  <?php endif; ?>
  <a href="index.php?p=logout" style="margin-top:20px">خروج (<?= h($u['name']) ?>)</a>
</aside>
<main>
  <h2><?= h($PAGES[$p][0]) ?><?php if ($curShift !== ''): ?> <span class="bdg">شيفت <?= h($curShift) ?></span><?php endif; ?></h2>
  <?php if ($flash): ?><div class="fl <?= h($flash[1] === 'ok' ? '' : $flash[1]) ?>"><?= h($flash[0]) ?></div><?php endif; ?>
  <?php if ($warnPass): ?><div class="fl warn no">كلمة المرور ما زالت الافتراضية للحسابات: <?= h(implode('، ', $warnPass)) ?> — غيّرها من «المستخدمون والصلاحيات».</div><?php endif; ?>
  <?= $content ?>
</main>
</div>
</body></html>
