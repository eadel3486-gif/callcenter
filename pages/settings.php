<?php
defined('APP') || exit;
$tokenFromEnv = cfg('tg_token') !== '';
$webhook = base_url() . '/webhook.php';
if (($_GET['download'] ?? '') === 'backup' && me() && me()['admin']) {
    $db=db(); $src=cfg('db_path'); $tmp=sys_get_temp_dir().'/callcenter-backup-'.bin2hex(random_bytes(5)).'.sqlite';
    $safe=str_replace("'","''",$tmp); $db->exec("VACUUM INTO '$safe'");
    while(ob_get_level()) ob_end_clean(); header('Content-Type: application/vnd.sqlite3'); header('Content-Disposition: attachment; filename=callcenter-backup-'.date('Y-m-d-His').'.sqlite'); header('Content-Length: '.filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}


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
    } elseif ($act === 'zones') {
        $names=$_POST['zone_name']??[]; $fees=$_POST['zone_fee']??[]; $z=[];
        foreach($names as $i=>$name){$name=trim($name);$fee=(float)($fees[$i]??0);if($name!=='' && $fee>=0)$z[$name]=$fee;}
        set_setting('delivery_zones',json_encode($z,JSON_UNESCAPED_UNICODE)); flash('تم حفظ أسعار التوصيل حسب المنطقة');
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

<div class="u">
  <h3 style="margin-top:0">أسعار التوصيل حسب المنطقة</h3>
  <p class="mu">أضف المناطق وسعر التوصيل لكل منطقة. عند اختيار المنطقة في الأوردر يتحدد السعر تلقائياً ويمكن تعديله يدوياً.</p>
  <?php $zones=json_decode(get_setting('delivery_zones'),true) ?: []; if(!$zones)$zones=['وسط البلد'=>15]; ?>
  <form method="post" id="zonesForm"><?= csrf_field() ?><input type="hidden" name="act" value="zones"><div id="zoneRows"><?php foreach($zones as $zn=>$zf): ?><div class="fb"><input name="zone_name[]" placeholder="اسم المنطقة" value="<?= h($zn) ?>"><input type="number" step="any" min="0" name="zone_fee[]" placeholder="السعر" value="<?= h($zf) ?>"><button type="button" class="btn r" onclick="this.parentNode.remove()">حذف</button></div><?php endforeach; ?></div><button type="button" class="btn g" onclick="document.getElementById('zoneRows').insertAdjacentHTML('beforeend','<div class=\"fb\"><input name=\"zone_name[]\" placeholder=\"اسم المنطقة\"><input type=\"number\" step=\"any\" min=\"0\" name=\"zone_fee[]\" placeholder=\"السعر\"><button type=\"button\" class=\"btn r\" onclick=\"this.parentNode.remove()\">حذف</button></div>')">+ منطقة</button> <button class="btn">حفظ الأسعار</button></form>
</div>
<div class="u"><h3 style="margin-top:0">نسخة احتياطية من قاعدة البيانات</h3><p class="mu">ينزّل نسخة SQLite متسقة من قاعدة البيانات الحالية. احتفظ بالنسخة خارج الاستضافة، خصوصاً على Railway لأن التخزين المحلي قد يكون مؤقتاً حسب إعدادات الـ Volume.</p><a class="btn" href="index.php?p=settings&download=backup">⬇️ تنزيل نسخة احتياطية الآن</a></div>
