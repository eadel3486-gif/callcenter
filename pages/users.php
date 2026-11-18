<?php
defined('APP') || exit;
$db = db();
$me = me();
$permKeys = array_column(PERMS, 0);

function admins_count() { return (int)db()->query('SELECT COUNT(*) FROM users WHERE admin=1')->fetchColumn(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    unset($_SESSION['pwchk']);
    if ($act === 'add') {
        $n = trim($_POST['name'] ?? ''); $un = trim($_POST['username'] ?? ''); $pw = (string)($_POST['pw'] ?? '');
        $c = $db->prepare('SELECT COUNT(*) FROM users WHERE username=?'); $c->execute([$un]);
        if ($n === '' || $un === '' || strlen($pw) < 4) flash('أكمل كل الحقول (كلمة المرور 4 أحرف على الأقل)', 'err');
        elseif ($c->fetchColumn() > 0) flash('اسم المستخدم مستخدم', 'err');
        else {
            $ush = in_array($_POST['shift'] ?? '', ['صباحي', 'مسائي'], true) ? $_POST['shift'] : '';
            $db->prepare('INSERT INTO users(name,username,pass_hash,admin,perms,shift) VALUES(?,?,?,0,?,?)')->execute([$n, $un, password_hash($pw, PASSWORD_DEFAULT), 'dash,orders,drivers,customers,period,edit,pay,track', $ush]);
            flash('تمت إضافة المستخدم');
        }
    } elseif ($act === 'save') {
        $s = $db->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$id]); $t = $s->fetch();
        if ($t) {
            $isAdmin = !empty($_POST['admin']) ? 1 : 0;
            if (!$isAdmin && $t['admin'] && admins_count() < 2) { flash('يجب بقاء مدير واحد على الأقل', 'err'); redirect('index.php?p=users'); }
            $perms = implode(',', array_values(array_intersect($permKeys, (array)($_POST['perms'] ?? []))));
            $ush = in_array($_POST['shift'] ?? '', ['صباحي', 'مسائي'], true) ? $_POST['shift'] : '';
            $db->prepare('UPDATE users SET admin=?, perms=?, shift=? WHERE id=?')->execute([$isAdmin, $perms, $isAdmin ? '' : $ush, $id]);
            $np = (string)($_POST['new_pw'] ?? '');
            if ($np !== '') {
                if (strlen($np) < 4) { flash('كلمة المرور 4 أحرف على الأقل', 'err'); redirect('index.php?p=users'); }
                $db->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($np, PASSWORD_DEFAULT), $id]);
            }
            flash('تم حفظ التعديلات');
        }
    } elseif ($act === 'delete') {
        $s = $db->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$id]); $t = $s->fetch();
        if (!$t) flash('المستخدم غير موجود', 'err');
        elseif ($id === (int)$me['id']) flash('لا يمكنك حذف حسابك الحالي', 'err');
        elseif ($t['admin'] && admins_count() < 2) flash('يجب بقاء مدير واحد على الأقل', 'err');
        else { $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); flash('تم حذف المستخدم'); }
    }
    redirect('index.php?p=users');
}
$U = $db->query('SELECT * FROM users ORDER BY id')->fetchAll();
?>
<form class="fb" method="post">
  <?= csrf_field() ?><input type="hidden" name="act" value="add">
  <input name="name" placeholder="الاسم"><input name="username" placeholder="اسم المستخدم"><input name="pw" type="password" placeholder="كلمة المرور">
  <label>الشيفت<select name="shift"><option value="">الكل (بدون تقييد)</option><option>صباحي</option><option>مسائي</option></select></label>
  <button class="btn">إضافة مستخدم</button>
</form>
<?php foreach ($U as $x): $has = explode(',', $x['perms']); ?>
<form class="u" method="post">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
  <div class="fb">
    <b><?= h($x['name']) ?></b><span class="mu"><?= h($x['username']) ?></span>
    <label>الشيفت<select name="shift"><option value="">الكل</option><?php foreach (['صباحي', 'مسائي'] as $sx): ?><option <?= $sx === $x['shift'] ? 'selected' : '' ?>><?= $sx ?></option><?php endforeach; ?></select></label>
    <label>كلمة مرور جديدة (اتركها فارغة لو بلا تغيير)<input type="password" name="new_pw" autocomplete="new-password"></label>
    <label class="pm"><input type="checkbox" name="admin" value="1" <?= $x['admin'] ? 'checked' : '' ?>> مدير (كل الصلاحيات)</label>
  </div>
  <div class="pm">
    <?php foreach (PERMS as [$k, $l]): ?><label><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= in_array($k, $has, true) ? 'checked' : '' ?>><?= h($l) ?></label><?php endforeach; ?>
  </div>
  <p>
    <button class="btn s" name="act" value="save">حفظ</button>
    <button class="btn r s" name="act" value="delete" onclick="return confirm('حذف المستخدم؟')">حذف</button>
  </p>
</form>
<?php endforeach; ?>
<p class="mu">مستخدم الشيفت (صباحي/مسائي) يشوف ويعدّل بيانات شيفته فقط (أوردرات، عملاء، مناديب، دفعات) ولا يظهر له مبدّل الشيفت. المدير والمستخدم «الكل» يشوفوا كل البيانات ويقدروا يبدّلوا الشيفت من القائمة الجانبية.</p>
