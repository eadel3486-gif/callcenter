<?php
// ===================== الإعدادات =====================
$CFG = require __DIR__ . '/config.php';
foreach (['tg_token' => 'TG_TOKEN', 'tg_bot_username' => 'TG_BOT_USERNAME', 'tg_secret' => 'TG_SECRET', 'base_url' => 'BASE_URL', 'db_path' => 'DB_PATH'] as $k => $e) {
    $v = getenv($e);
    if ($v !== false && $v !== '') $CFG[$k] = $v;
}
date_default_timezone_set($CFG['timezone']);

function cfg($k) { global $CFG; return $CFG[$k] ?? ''; }

const PERMS = [
    ['dash', 'الرئيسية'], ['orders', 'الأوردرات'], ['drivers', 'المندوبون'], ['track', 'تتبع المناديب'],
    ['customers', 'العملاء'], ['period', 'تقرير الفترة'], ['edit', 'إضافة وتعديل'],
    ['del', 'حذف'], ['pay', 'تسجيل دفعات'], ['export', 'تصدير وطباعة'],
];
const STATUS = ['new' => 'بانتظار المندوب', 'out' => 'خرج للتوصيل', 'done' => 'تم التسليم'];
// طرق الدفع: كاش يحصّله المندوب من العميل، وفيزا/إنستا باي مدفوعة إلكترونياً (لا عهدة على المندوب)
const PAY = ['cash' => 'كاش', 'visa' => 'فيزا', 'instapay' => 'إنستا باي'];
const PAY_ICON = ['cash' => '💵', 'visa' => '💳', 'instapay' => '📲'];
function pay_ok($k) { return is_string($k) && isset(PAY[$k]); }
function pay_pick($k) { return pay_ok($k) ? $k : 'cash'; }
function pay_label($k) { return PAY[$k] ?? PAY['cash']; }
function pay_full($k) { $k = pay_pick($k); return PAY_ICON[$k] . ' ' . PAY[$k]; }
function pay_select($name, $cur, $all = false, $attrs = '') {
    $o = '<select name="' . h($name) . '"' . $attrs . '>' . ($all ? '<option value="">الكل</option>' : '');
    foreach (PAY as $k => $v) $o .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . PAY_ICON[$k] . ' ' . h($v) . '</option>';
    return $o . '</select>';
}

// ===================== أدوات عامة =====================
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function N($n) {
    $n = round((float)$n, 2);
    return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
}

class Raw { public $s; function __construct($s) { $this->s = $s; } }
function raw($s) { return new Raw($s); }

function table(array $heads, array $rows, $act = null) {
    if (!$rows) return '<p class="mu">لا توجد بيانات مطابقة.</p>';
    $o = '<div class="tw"><table><tr>';
    foreach ($heads as $x) $o .= '<th>' . h($x) . '</th>';
    if ($act) $o .= '<th class="no"></th>';
    $o .= '</tr>';
    foreach ($rows as $i => $r) {
        $o .= '<tr>';
        foreach ($r as $c) $o .= '<td>' . ($c instanceof Raw ? $c->s : h($c)) . '</td>';
        if ($act) $o .= '<td class="no">' . $act($i, $r) . '</td>';
        $o .= '</tr>';
    }
    return $o . '</table></div>';
}

function csv_out($name, array $heads, array $rows) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, $heads);
    foreach ($rows as $r) fputcsv($f, array_map(function ($c) { return $c instanceof Raw ? strip_tags($c->s) : $c; }, $r));
    fclose($f);
    exit;
}

function qs(array $over = []) {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) if ($v === '' || $v === null) unset($q[$k]);
    return 'index.php?' . http_build_query($q);
}

function pager($total, $per, $pg) {
    $n = (int)ceil($total / $per);
    if ($n <= 1) return '';
    $o = '<p class="no pg">';
    for ($i = max(1, $pg - 6); $i <= min($n, $pg + 6); $i++)
        $o .= $i === $pg ? '<b>' . $i . '</b>' : '<a href="' . h(qs(['pg' => $i])) . '">' . $i . '</a>';
    return $o . ' <span class="mu">من ' . $n . ' صفحة</span></p>';
}

function valid_date($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }

function date_where(array &$w, array &$a, $col = 'd') {
    if (valid_date($_GET['from'] ?? '')) { $w[] = "$col>=?"; $a[] = $_GET['from']; }
    if (valid_date($_GET['to'] ?? '')) { $w[] = "$col<=?"; $a[] = $_GET['to']; }
}

function dates_inputs() {
    return '<label>من<input type="date" name="from" value="' . h($_GET['from'] ?? '') . '"></label>'
         . '<label>إلى<input type="date" name="to" value="' . h($_GET['to'] ?? '') . '"></label>';
}

function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function base_url() {
    $b = cfg('base_url');
    if ($b !== '') return rtrim($b, '/');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

// ===================== قاعدة البيانات =====================
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = cfg('db_path');
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    init_schema($pdo);
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) seed($pdo);
    migrate($pdo);
    return $pdo;
}

function init_schema(PDO $db) {
    $db->exec("
    CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, username TEXT NOT NULL UNIQUE,
        pass_hash TEXT NOT NULL, admin INTEGER NOT NULL DEFAULT 0, perms TEXT NOT NULL DEFAULT '');
    CREATE TABLE IF NOT EXISTS drivers(
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, phone TEXT DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1, tg_chat_id TEXT, tg_username TEXT, link_code TEXT, token TEXT NOT NULL,
        last_lat REAL, last_lng REAL, last_acc REAL, last_at INTEGER);
    CREATE TABLE IF NOT EXISTS orders(
        id INTEGER PRIMARY KEY AUTOINCREMENT, d TEXT NOT NULL, shift TEXT NOT NULL DEFAULT 'صباحي',
        bon TEXT DEFAULT '', fee REAL DEFAULT 0, val REAL DEFAULT 0, phone TEXT NOT NULL, driver_id INTEGER,
        out_t TEXT DEFAULT '', ret_t TEXT DEFAULT '', addr TEXT DEFAULT '', status TEXT NOT NULL DEFAULT 'new',
        created_at INTEGER);
    CREATE INDEX IF NOT EXISTS ix_o_d ON orders(d);
    CREATE INDEX IF NOT EXISTS ix_o_drv ON orders(driver_id);
    CREATE INDEX IF NOT EXISTS ix_o_phone ON orders(phone);
    CREATE INDEX IF NOT EXISTS ix_o_status ON orders(status);
    CREATE TABLE IF NOT EXISTS payments(
        id INTEGER PRIMARY KEY AUTOINCREMENT, driver_id INTEGER NOT NULL, d TEXT NOT NULL,
        amount REAL NOT NULL, note TEXT DEFAULT '');
    CREATE TABLE IF NOT EXISTS locations(
        id INTEGER PRIMARY KEY AUTOINCREMENT, driver_id INTEGER NOT NULL, lat REAL, lng REAL, acc REAL, at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS ix_l ON locations(driver_id, at);
    CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS customers(
        id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL UNIQUE, address TEXT NOT NULL DEFAULT '', created_at INTEGER);
    ");
}

function new_token() { return bin2hex(random_bytes(16)); }
function new_code() { return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)); }


// ترقيات قاعدة البيانات للنسخ القديمة (تعمل تلقائياً ومرة واحدة فعلياً)
function migrate(PDO $db) {
    // عمود الشيفت في الدفعات
    $has = false;
    foreach ($db->query('PRAGMA table_info(payments)')->fetchAll() as $c) if ($c['name'] === 'shift') $has = true;
    if (!$has) $db->exec("ALTER TABLE payments ADD COLUMN shift TEXT NOT NULL DEFAULT ''");
    // شيفت المستخدم (فاضي = يشوف الكل)
    $has = false;
    foreach ($db->query('PRAGMA table_info(users)')->fetchAll() as $c) if ($c['name'] === 'shift') $has = true;
    if (!$has) $db->exec("ALTER TABLE users ADD COLUMN shift TEXT NOT NULL DEFAULT ''");
    // طريقة الدفع: في الأوردرات (كاش/فيزا/إنستا باي + مرجع العملية) وفي دفعات المناديب
    foreach ([['orders', 'pay_method', "TEXT NOT NULL DEFAULT 'cash'"], ['orders', 'pay_ref', "TEXT NOT NULL DEFAULT ''"],
              ['payments', 'method', "TEXT NOT NULL DEFAULT 'cash'"]] as [$t, $col, $def]) {
        $has = false;
        foreach ($db->query("PRAGMA table_info($t)")->fetchAll() as $c) if ($c['name'] === $col) $has = true;
        if (!$has) $db->exec("ALTER TABLE $t ADD COLUMN $col $def");
    }
    $db->exec('CREATE INDEX IF NOT EXISTS ix_o_pay ON orders(pay_method)');
    // ربط الأوردرات بسجل العملاء (customer_id)
    $has = false;
    foreach ($db->query('PRAGMA table_info(orders)')->fetchAll() as $c) if ($c['name'] === 'customer_id') $has = true;
    if (!$has) $db->exec('ALTER TABLE orders ADD COLUMN customer_id INTEGER');
    // تكويد العملاء الحاليين من الأوردرات (مرة واحدة لو الجدول فاضي)
    if ((int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 0
        && (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() > 0) {
        $db->beginTransaction();
        $db->exec("INSERT INTO customers(phone,address,created_at)
            SELECT o.phone, COALESCE((SELECT x.addr FROM orders x WHERE x.phone=o.phone AND x.addr<>'' ORDER BY x.d DESC, x.id DESC LIMIT 1),''), strftime('%s','now')
            FROM orders o WHERE o.phone<>'' GROUP BY o.phone ORDER BY MIN(o.d), MIN(o.id)");
        $db->exec("UPDATE customers SET code='C'||substr('0000'||id,-4) WHERE code IS NULL");
        $db->commit();
    }
    // أي أوردر مش مربوط بعميل: اربطه بالرقم (أو أنشئ العميل)
    if ($db->query("SELECT 1 FROM orders WHERE customer_id IS NULL AND phone<>'' LIMIT 1")->fetchColumn()) {
        $db->exec("INSERT INTO customers(phone,address,created_at)
            SELECT o.phone, '', strftime('%s','now') FROM orders o
            WHERE o.customer_id IS NULL AND o.phone<>'' AND o.phone NOT IN (SELECT phone FROM customers) GROUP BY o.phone");
        $db->exec("UPDATE customers SET code='C'||substr('0000'||id,-4) WHERE code IS NULL");
        $db->exec('UPDATE orders SET customer_id=(SELECT c.id FROM customers c WHERE c.phone=orders.phone) WHERE customer_id IS NULL');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS ix_o_cust ON orders(customer_id)');
    // مستخدما الشيفتين الافتراضيان (مرة واحدة فقط)
    $f = $db->prepare("SELECT v FROM settings WHERE k='shift_users'");
    $f->execute();
    if ($f->fetchColumn() === false) {
        $ins = $db->prepare('INSERT OR IGNORE INTO users(name,username,pass_hash,admin,perms,shift) VALUES(?,?,?,0,?,?)');
        $perms = 'dash,orders,drivers,track,customers,period,edit,pay,export';
        $ins->execute(['شيفت صباحي', 'morning', password_hash('1234', PASSWORD_DEFAULT), $perms, 'صباحي']);
        $ins->execute(['شيفت مسائي', 'evening', password_hash('1234', PASSWORD_DEFAULT), $perms, 'مسائي']);
        $db->exec("INSERT OR REPLACE INTO settings(k,v) VALUES('shift_users','1')");
    }
}

// ينشئ العميل (بكود تلقائي) لو مش موجود، ويحدّث الاسم/العنوان لو اتبعتوا. يرجع صف العميل.
function ensure_customer($phone, $name = '', $addr = '') {
    $phone = trim((string)$phone);
    if ($phone === '') return null;
    $db = db();
    $s = $db->prepare('SELECT * FROM customers WHERE phone=?');
    $s->execute([$phone]);
    $c = $s->fetch();
    $name = trim((string)$name); $addr = trim((string)$addr);
    if (!$c) {
        $db->prepare('INSERT INTO customers(phone,name,address,created_at) VALUES(?,?,?,?)')->execute([$phone, $name, $addr, time()]);
        $id = (int)$db->lastInsertId();
        $db->prepare("UPDATE customers SET code='C'||substr('0000'||id,-4) WHERE id=?")->execute([$id]);
    } else {
        if ($name !== '' && $name !== $c['name']) $db->prepare('UPDATE customers SET name=? WHERE id=?')->execute([$name, $c['id']]);
        if ($addr !== '' && $addr !== $c['address']) $db->prepare('UPDATE customers SET address=? WHERE id=?')->execute([$addr, $c['id']]);
    }
    $s->execute([$phone]);
    return $s->fetch();
}

// الشيفت الحالي المختار في الواجهة ('' = الكل)
function user_shift() {
    $u = me();
    $s = $u['shift'] ?? '';
    return in_array($s, ['صباحي', 'مسائي'], true) ? $s : '';
}
function cur_shift() {
    $f = user_shift();
    if ($f !== '') return $f;   // مستخدم الشيفت مقفول على شيفته
    session_boot();
    $v = $_SESSION['shift'] ?? '';
    return in_array($v, ['صباحي', 'مسائي'], true) ? $v : '';
}
function shift_where(array &$w, array &$a, $col = 'shift') {
    $sh = cur_shift();
    if ($sh !== '') { $w[] = "$col=?"; $a[] = $sh; }
}

function seed(PDO $db) {
    $f = __DIR__ . '/data/seed.json';
    $db->beginTransaction();
    if (!is_file($f)) {
        $db->prepare('INSERT INTO users(name,username,pass_hash,admin,perms) VALUES(?,?,?,1,?)')
           ->execute(['المدير', 'admin', password_hash('admin123', PASSWORD_DEFAULT), '']);
        $db->commit();
        return;
    }
    $d = json_decode(file_get_contents($f), true);
    $ins = $db->prepare('INSERT INTO drivers(name,token,link_code) VALUES(?,?,?)');
    $dmap = [];
    foreach ($d['drivers'] as $n) {
        $ins->execute([$n, new_token(), new_code()]);
        $dmap[$n] = (int)$db->lastInsertId();
    }
    $ins = $db->prepare('INSERT INTO orders(d,shift,bon,fee,val,phone,driver_id,out_t,ret_t,addr,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($d['orders'] as $o) {
        $ins->execute([$o['d'], $o['s'], (string)$o['b'], $o['f'], $o['v'], (string)$o['p'],
            $dmap[$o['m']] ?? null, $o['o'], $o['r'], $o['a'], 'done', time()]);
    }
    $ins = $db->prepare('INSERT INTO users(name,username,pass_hash,admin,perms) VALUES(?,?,?,?,?)');
    foreach ($d['users'] as $u) {
        $perm = $u['perm'] ?? [];
        if (in_array('drivers', $perm, true)) $perm[] = 'track';
        $ins->execute([$u['n'], $u['u'], password_hash($u['p'], PASSWORD_DEFAULT), $u['admin'] ? 1 : 0, implode(',', $perm)]);
    }
    $db->commit();
}

function get_setting($k) {
    $s = db()->prepare('SELECT v FROM settings WHERE k=?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? '' : (string)$v;
}
function set_setting($k, $v) {
    db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}

// ===================== الجلسة والصلاحيات =====================
function session_boot() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
        session_start();
    }
}

function me() {
    static $u = false;
    if ($u !== false) return $u;
    session_boot();
    $u = null;
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id) {
        $s = db()->prepare('SELECT * FROM users WHERE id=?');
        $s->execute([$id]);
        $u = $s->fetch() ?: null;
    }
    return $u;
}

function can($k) {
    $u = me();
    if (!$u) return false;
    if ($u['admin']) return true;
    return in_array($k, explode(',', $u['perms']), true);
}

function csrf() {
    session_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . h(csrf()) . '">'; }
function csrf_check() {
    if (!hash_equals(csrf(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('انتهت الجلسة، أعد تحميل الصفحة وحاول مرة أخرى.');
    }
}

function flash($m, $t = 'ok') { session_boot(); $_SESSION['flash'] = [$m, $t]; }
function redirect($url) { header('Location: ' . $url); exit; }

// ===================== تليجرام =====================
function tg_token() { $t = cfg('tg_token'); return $t !== '' ? $t : get_setting('tg_token'); }
function tg_bot() { $b = cfg('tg_bot_username'); return ltrim($b !== '' ? $b : get_setting('tg_bot'), '@'); }
function tg_secret() {
    $s = cfg('tg_secret');
    if ($s !== '') return $s;
    $s = get_setting('tg_secret');
    if ($s === '') { $s = bin2hex(random_bytes(16)); set_setting('tg_secret', $s); }
    return $s;
}

function tg($method, array $params = []) {
    $t = tg_token();
    if ($t === '') return ['ok' => false, 'description' => 'توكن البوت غير مضبوط'];
    $body = json_encode($params, JSON_UNESCAPED_UNICODE);
    $url = "https://api.telegram.org/bot$t/$method";
    $r = false; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        ]);
        $r = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        // بديل لو إضافة cURL غير مفعّلة (يحتاج allow_url_fopen و openssl)
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
            'content' => $body, 'timeout' => 12, 'ignore_errors' => true,
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) $err = 'تعذر الاتصال بتليجرام — فعّل extension=curl أو extension=openssl في php.ini';
    }
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : ['ok' => false, 'description' => $err ?: 'تعذر الاتصال بتليجرام'];
}

function tg_send($chat, $text, $markup = null) {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($markup) $p['reply_markup'] = $markup;
    return tg('sendMessage', $p);
}

function driver_keyboard() {
    return ['keyboard' => [[['text' => '📍 أرسل موقعي الآن', 'request_location' => true], ['text' => '📋 أوردراتي']]],
            'resize_keyboard' => true, 'is_persistent' => true];
}

function order_msg(array $o) {
    $t = '📦 <b>أوردر' . ($o['bon'] !== '' ? ' #' . h($o['bon']) : '') . "</b>\n";
    $t .= '📅 ' . h($o['d']) . ' — ' . h($o['shift']) . "\n";
    $t .= '📞 العميل: ' . h($o['phone']) . "\n";
    if ($o['addr'] !== '') $t .= '📍 العنوان: ' . h($o['addr']) . "\n";
    $tot = (float)$o['val'] + (float)$o['fee'];
    $t .= '💵 القيمة: ' . N($o['val']) . ' + توصيل ' . N($o['fee']) . ' = <b>' . N($tot) . "</b> ج\n";
    $pm = pay_pick($o['pay_method'] ?? 'cash');
    if ($pm === 'cash') {
        $t .= '💵 الدفع: <b>كاش</b> — حصّل من العميل <b>' . N($tot) . "</b> ج\n";
    } else {
        $t .= PAY_ICON[$pm] . ' الدفع: <b>' . h(PAY[$pm]) . '</b> — ✅ مدفوع مسبقاً، <b>لا تحصّل أي مبلغ</b> من العميل'
            . (($o['pay_ref'] ?? '') !== '' ? ' (مرجع: ' . h($o['pay_ref']) . ')' : '') . "\n";
    }
    $t .= 'الحالة: ' . (STATUS[$o['status']] ?? '');
    return $t;
}

function order_markup(array $o) {
    $rows = [];
    if ($o['addr'] !== '')
        $rows[] = [['text' => '🗺️ افتح العنوان في الخرائط', 'url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($o['addr'] . ' الإسكندرية')]];
    if ($o['status'] === 'new') $rows[] = [['text' => '🚴 خرجت بالأوردر', 'callback_data' => 'o:out:' . $o['id']]];
    if ($o['status'] !== 'done') $rows[] = [['text' => '✅ تم التسليم', 'callback_data' => 'o:done:' . $o['id']]];
    return ['inline_keyboard' => $rows];
}

// يرسل الأوردر للمندوب على تليجرام. يرجع true لو اتبعت.
function notify_order($orderId) {
    $s = db()->prepare('SELECT o.*, dr.tg_chat_id FROM orders o JOIN drivers dr ON dr.id=o.driver_id WHERE o.id=?');
    $s->execute([(int)$orderId]);
    $o = $s->fetch();
    if (!$o || empty($o['tg_chat_id'])) return false;
    $r = tg_send($o['tg_chat_id'], order_msg($o), order_markup($o));
    return !empty($r['ok']);
}

// ===================== المواقع =====================
function store_location($driverId, $lat, $lng, $acc = null) {
    $lat = (float)$lat; $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) return false;
    $db = db();
    $now = time();
    $db->prepare('UPDATE drivers SET last_lat=?,last_lng=?,last_acc=?,last_at=? WHERE id=?')->execute([$lat, $lng, $acc, $now, (int)$driverId]);
    $q = $db->prepare('SELECT MAX(at) FROM locations WHERE driver_id=?');
    $q->execute([(int)$driverId]);
    if ($now - (int)$q->fetchColumn() >= 20)
        $db->prepare('INSERT INTO locations(driver_id,lat,lng,acc,at) VALUES(?,?,?,?,?)')->execute([(int)$driverId, $lat, $lng, $acc, $now]);
    if (random_int(1, 100) === 1)
        $db->prepare('DELETE FROM locations WHERE at<?')->execute([$now - 7 * 86400]);
    return true;
}

// ===================== هيكل الصفحة =====================
function html_head($title) {
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title>'
        . '<link rel="manifest" href="manifest.php"><meta name="theme-color" content="#10262e">'
        . '<link rel="icon" type="image/png" href="assets/icons/icon-192.png"><link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">'
        . '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-title" content="الكول سنتر"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
        . '<script>if("serviceWorker" in navigator)addEventListener("load",function(){navigator.serviceWorker.register("sw.js").catch(function(){})})</script>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;700&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="assets/style.css"></head><body>';
}
