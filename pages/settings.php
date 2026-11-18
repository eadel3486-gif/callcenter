<?php
defined('APP') || exit;
$tokenFromEnv = cfg('tg_token') !== '';
$webhook = base_url() . '/webhook.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'token' && !$tokenFromEnv) {
        $t = trim($_POST['token'] ?? '');
        if ($t === '') { set_setting('tg_token', ''); set_setting('tg_bot', ''); flash('تم مسح التوكن'); }
        elseif (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $t)) flash('شكل التوكن غير صحيح', 'err');
        else {
            set_setting('tg_token', $t);
            $r = tg('getMe');
            if (!empty($r['ok'])) { set_setting('tg_bot', $r['result']['username'] ?? ''); flash('تم حفظ التوكن — البوت: @' . ($r['result']['username'] ?? '')); }
            else { set_setting('tg_token', ''); flash('التوكن مرفوض من تليجرام: ' . ($r['description'] ?? ''), 'err'); }
        }
    } elseif ($act === 'webhook') {
        if (!is_https()) flash('تليجرام يشترط HTTPS لعنوان الويبهوك. شغّل الموقع على HTTPS (أو اضبط BASE_URL).', 'err');
        else {
            $r = tg('setWebhook', ['url' => $webhook, 'secret_token' => tg_secret(), 'allowed_updates' => ['message', 'edited_message', 'callback_query']]);
            flash(!empty($r['ok']) ? 'تم تفعيل الويبهوك ✅ — جرّب إرسال /start للبوت' : 'فشل: ' . ($r['description'] ?? ''), !empty($r['ok']) ? 'ok' : 'err');
        }
    } elseif ($act === 'wipe') {
        if (trim($_POST['confirm'] ?? '') !== 'مسح') flash('اكتب كلمة «مسح» للتأكيد', 'err');
        else {
            $db = db();
            $db->beginTransaction();
            foreach (['orders', 'payments', 'locations', 'customers', 'drivers'] as $t) $db->exec("DELETE FROM $t");
            $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('orders','payments','locations','customers','drivers')");
            $db->commit();
            flash('تم مسح الأوردرات والعملاء والمناديب والدفعات والمواقع. المستخدمون وإعدادات البوت محفوظين.');
        }
    } elseif ($act === 'unhook') {
        $r = tg('deleteWebhook');
        flash(!empty($r['ok']) ? 'تم إيقاف الويبهوك' : 'فشل: ' . ($r['description'] ?? ''), !empty($r['ok']) ? 'ok' : 'err');
    }
    redirect('index.php?p=settings');
}

$info = tg_token() !== '' ? tg('getWebhookInfo') : null;
$wi = $info['result'] ?? null;
?>
<div class="u">
  <h3 style="margin-top:0">1) توكن البوت</h3>
  <?php if ($tokenFromEnv): ?>
    <p>التوكن مضبوط من ملف الإعدادات/متغيرات البيئة. البوت: <b><?= tg_bot() ? '@' . h(tg_bot()) : '—' ?></b></p>
  <?php else: ?>
    <p class="mu">أنشئ بوت من <b>@BotFather</b> في تليجرام (أمر /newbot) وانسخ التوكن هنا.</p>
    <form class="fb" method="post"><?= csrf_field() ?><input type="hidden" name="act" value="token">
      <label style="min-width:340px">التوكن<input name="token" placeholder="123456:ABC..." autocomplete="off" value=""></label>
      <button class="btn">حفظ والتحقق</button>
    </form>
    <p><?= tg_token() !== '' ? '✅ التوكن محفوظ — البوت: <b>@' . h(tg_bot()) . '</b>' : '❌ لا يوجد توكن محفوظ' ?></p>
  <?php endif; ?>
</div>
<div class="u">
  <h3 style="margin-top:0">2) تفعيل الويبهوك</h3>
  <p>عنوان الويبهوك: <span class="cd"><?= h($webhook) ?></span></p>
  <?php if (!is_https()): ?><div class="fl warn">الموقع يعمل الآن بدون HTTPS. تليجرام لا يقبل ويبهوك إلا على HTTPS.</div><?php endif; ?>
  <form method="post" class="fb"><?= csrf_field() ?>
    <button class="btn" name="act" value="webhook">تفعيل الويبهوك</button>
    <button class="btn g" name="act" value="unhook">إيقاف الويبهوك</button>
  </form>
  <?php if ($wi): ?>
    <p>الحالة: <?= !empty($wi['url']) ? '✅ مفعّل' : '❌ غير مفعّل' ?>
    <?php if (!empty($wi['pending_update_count'])): ?> — رسائل معلّقة: <?= (int)$wi['pending_update_count'] ?><?php endif; ?></p>
    <?php if (!empty($wi['last_error_message'])): ?><div class="fl err">آخر خطأ من تليجرام: <?= h($wi['last_error_message']) ?></div><?php endif; ?>
  <?php endif; ?>
</div>
<div class="u">
  <h3 style="margin-top:0">مسح البيانات التجريبية</h3>
  <p class="mu">يمسح كل الأوردرات والعملاء والمناديب والدفعات وسجل المواقع، ويبدأ الترقيم من جديد (العميل الأول C0001). <b>المستخدمون وتوكن البوت لا يتأثروا.</b> لا يمكن التراجع.</p>
  <form method="post" class="fb" onsubmit="return confirm('مسح كل البيانات نهائياً؟')"><?= csrf_field() ?><input type="hidden" name="act" value="wipe">
    <label>اكتب «مسح» للتأكيد<input name="confirm" autocomplete="off"></label>
    <button class="btn r">مسح كل البيانات</button>
  </form>
</div>
<div class="u">
  <h3 style="margin-top:0">3) ربط المناديب</h3>
  <p>من صفحة «المندوبون» اضغط «ربط تليجرام» عند كل مندوب، وسلّمه الكود أو الرابط. بعد ما يبعت الكود للبوت يظهر «مربوط ✈️».</p>
  <p>لإظهار موقعه على الخريطة: في محادثة البوت يضغط 📎 ← الموقع ← <b>مشاركة الموقع الحي</b> ← يختار المدة (8 ساعات). أو يستخدم رابط المتصفح الخاص به.</p>
</div>
