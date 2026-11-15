<?php
defined('APP') || exit;
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $s = db()->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([trim($_POST['u'] ?? '')]);
    $row = $s->fetch();
    if ($row && password_verify((string)($_POST['pw'] ?? ''), $row['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$row['id'];
        redirect('index.php');
    }
    sleep(1);
    $err = 'اسم المستخدم أو كلمة المرور غير صحيحة';
}
echo html_head('دخول — ' . cfg('app_name'));
?>
<form class="lg" method="post">
  <h2><?= h(cfg('app_name')) ?></h2>
  <?= csrf_field() ?>
  <div class="qk">
    <button type="button" data-u="morning">☀️ شيفت صباحي</button>
    <button type="button" data-u="evening">🌙 شيفت مسائي</button>
    <button type="button" data-u="admin">المدير</button>
  </div>
  <label>اسم المستخدم<input name="u" id="lu" autofocus autocomplete="username"></label>
  <label>كلمة المرور<input name="pw" type="password" autocomplete="current-password"></label>
  <div class="er"><?= h($err) ?></div>
  <button class="btn">دخول</button>
</form>
<script>
document.querySelectorAll('.qk button').forEach(b => b.onclick = () => {
  document.getElementById('lu').value = b.dataset.u;
  document.querySelector('input[name=pw]').focus();
});
</script>
</body></html>
