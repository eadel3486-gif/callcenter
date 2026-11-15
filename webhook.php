<?php
// ويبهوك بوت تليجرام: ربط المناديب + أزرار الأوردرات + استقبال المواقع (Live Location)
require __DIR__ . '/lib.php';

$sec = tg_secret();
if (!hash_equals($sec, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
$upd = json_decode(file_get_contents('php://input'), true);
if (!is_array($upd)) exit('ok');
$db = db();

function driver_by_chat($chat) {
    $s = db()->prepare('SELECT * FROM drivers WHERE tg_chat_id=?');
    $s->execute([(string)$chat]);
    return $s->fetch() ?: null;
}

// ---------- ضغطة زر على أوردر ----------
if (isset($upd['callback_query'])) {
    $cq = $upd['callback_query'];
    $chat = $cq['message']['chat']['id'] ?? $cq['from']['id'];
    $dr = driver_by_chat($chat);
    $answer = fn($t) => tg('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $t, 'show_alert' => false]);
    if (!$dr) { $answer('حسابك غير مربوط'); exit('ok'); }
    if (preg_match('/^o:(out|done):(\d+)$/', $cq['data'] ?? '', $m)) {
        $s = $db->prepare('SELECT * FROM orders WHERE id=? AND driver_id=?');
        $s->execute([(int)$m[2], $dr['id']]);
        $o = $s->fetch();
        if (!$o) { $answer('الأوردر غير متاح'); exit('ok'); }
        if ($o['status'] === 'done') { $answer('الأوردر مسلَّم بالفعل'); exit('ok'); }
        $now = date('H:i');
        $out = $o['out_t'] !== '' ? $o['out_t'] : $now;
        if ($m[1] === 'out') {
            $db->prepare("UPDATE orders SET status='out', out_t=? WHERE id=?")->execute([$out, $o['id']]);
        } else {
            $db->prepare("UPDATE orders SET status='done', out_t=?, ret_t=? WHERE id=?")->execute([$out, $now, $o['id']]);
        }
        $s->execute([(int)$m[2], $dr['id']]);
        $o = $s->fetch();
        tg('editMessageText', [
            'chat_id' => $chat, 'message_id' => $cq['message']['message_id'],
            'text' => order_msg($o), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
            'reply_markup' => order_markup($o),
        ]);
        $answer($m[1] === 'out' ? 'تم تسجيل الخروج 🚴' : 'تم تسجيل التسليم ✅');
    }
    exit('ok');
}

$msg = $upd['message'] ?? $upd['edited_message'] ?? null;
if (!$msg || ($msg['chat']['type'] ?? '') !== 'private') exit('ok');
$chat = $msg['chat']['id'];
$isEdit = isset($upd['edited_message']);
$dr = driver_by_chat($chat);

// ---------- موقع (عادي أو Live) ----------
if (isset($msg['location'])) {
    if (!$dr) { if (!$isEdit) tg_send($chat, 'حسابك غير مربوط بعد. ابعت كود الربط اللي أخدته من المشرف أولاً.'); exit('ok'); }
    $loc = $msg['location'];
    store_location($dr['id'], $loc['latitude'], $loc['longitude'], $loc['horizontal_accuracy'] ?? null);
    if (!$isEdit) tg_send($chat, isset($loc['live_period']) ? 'تم تفعيل الموقع الحي 🟢 المشرف شايف مكانك.' : 'تم استلام موقعك ✅ (موقع لحظي — لو عايز يتحدث تلقائياً استخدم «مشاركة الموقع الحي»)');
    exit('ok');
}

$text = trim($msg['text'] ?? '');
if ($text === '') exit('ok');

// ---------- ربط بالكود: /start CODE أو CODE مباشرة ----------
$code = null;
if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/u', $text, $m)) $code = isset($m[1]) ? strtoupper($m[1]) : null;
elseif (preg_match('/^[A-Za-z0-9]{6}$/', $text) && !$dr) $code = strtoupper($text);

if ($code !== null) {
    $s = $db->prepare('SELECT * FROM drivers WHERE link_code=? AND active=1');
    $s->execute([$code]);
    $target = $s->fetch();
    if ($target) {
        $db->prepare('UPDATE drivers SET tg_chat_id=NULL, tg_username=NULL WHERE tg_chat_id=? AND id<>?')->execute([(string)$chat, $target['id']]);
        $db->prepare('UPDATE drivers SET tg_chat_id=?, tg_username=?, link_code=? WHERE id=?')
           ->execute([(string)$chat, $msg['from']['username'] ?? null, new_code(), $target['id']]);
        tg_send($chat, "أهلاً <b>" . h($target['name']) . "</b> 👋\nتم ربط حسابك ✅ هتوصلك الأوردرات هنا.\n\n"
            . "علشان المشرف يشوف مكانك على الخريطة:\n📎 ← الموقع ← <b>مشاركة الموقع الحي</b> ← اختار 8 ساعات.\n\n"
            . 'الأزرار تحت: «أرسل موقعي الآن» و«أوردراتي».', driver_keyboard());
    } elseif ($dr) {
        tg_send($chat, 'أهلاً ' . h($dr['name']) . ' 👋 حسابك مربوط. اضغط «أوردراتي» لعرض الأوردرات الجارية.', driver_keyboard());
    } else {
        tg_send($chat, $code !== null && $text !== '/start' ? 'الكود غير صحيح أو منتهي. اطلب كود جديد من المشرف.' : 'أهلاً! ابعت كود الربط اللي أخدته من المشرف علشان تبدأ.');
    }
    exit('ok');
}

if (!$dr) { tg_send($chat, 'حسابك غير مربوط. ابعت كود الربط اللي أخدته من المشرف.'); exit('ok'); }

// ---------- الأوردرات الجارية ----------
if ($text === '/orders' || $text === '📋 أوردراتي') {
    $s = $db->prepare("SELECT * FROM orders WHERE driver_id=? AND status IN ('new','out') ORDER BY id LIMIT 10");
    $s->execute([$dr['id']]);
    $L = $s->fetchAll();
    if (!$L) tg_send($chat, 'لا توجد أوردرات جارية حالياً ✅', driver_keyboard());
    foreach ($L as $o) tg_send($chat, order_msg($o), order_markup($o));
    exit('ok');
}

tg_send($chat, 'الأوامر: «📋 أوردراتي» لعرض الأوردرات، و«📍 أرسل موقعي الآن» لإرسال موقعك. ولإظهار حركتك باستمرار استخدم «مشاركة الموقع الحي».', driver_keyboard());
echo 'ok';
