<?php
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jout($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$a = $_GET['a'] ?? '';

// استقبال موقع المندوب من المتصفح (driver.php) — بالتوكن الخاص بالمندوب
if ($a === 'ping') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['error' => 'POST only'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $s = db()->prepare('SELECT id FROM drivers WHERE token=? AND active=1');
    $s->execute([(string)($in['t'] ?? '')]);
    $id = $s->fetchColumn();
    if (!$id) jout(['error' => 'bad token'], 403);
    if (!isset($in['lat'], $in['lng']) || !is_numeric($in['lat']) || !is_numeric($in['lng'])) jout(['error' => 'bad data'], 400);
    $acc = isset($in['acc']) && is_numeric($in['acc']) ? (float)$in['acc'] : null;
    jout(['ok' => store_location((int)$id, $in['lat'], $in['lng'], $acc)]);
}

// بحث العملاء للتعبئة التلقائية في فورم الأوردر (يحتاج تسجيل دخول وصلاحية الأوردرات)
if ($a === 'customer' || $a === 'customers') {
    if (!me() || !(can('orders') || can('edit'))) jout(['error' => 'forbidden'], 403);
    $db = db();
    if ($a === 'customer') {
        $ph = trim((string)($_GET['phone'] ?? ''));
        $s = $db->prepare("SELECT id,code,name,phone,phone2,address,address2,notes,problem FROM customers WHERE phone=? OR (phone2<>'' AND phone2=?)");
        $s->execute([$ph, $ph]);
        $c = $s->fetch();
        if ($c) {
            $h = $db->prepare('SELECT id,d,bon,val,fee,status,addr FROM orders WHERE customer_id=? ORDER BY d DESC,id DESC LIMIT 3');
            $h->execute([(int)$c['id']]); $last = $h->fetchAll();
            $st = $db->prepare('SELECT COUNT(*) n, MAX(d) last_order FROM orders WHERE customer_id=?');
            $st->execute([(int)$c['id']]); $stats = $st->fetch();
            jout(['customer'=>$c,'stats'=>$stats,'last_orders'=>$last]);
        }
        jout(['customer'=>null,'stats'=>null,'last_orders'=>[]]);
    }
    $q = '%' . trim((string)($_GET['q'] ?? '')) . '%';
    $s = $db->prepare('SELECT code,name,phone,phone2,address,address2 FROM customers WHERE phone LIKE ? OR phone2 LIKE ? OR name LIKE ? OR code LIKE ? ORDER BY name, phone LIMIT 8');
    $s->execute([$q, $q, $q, $q]);
    jout(['customers' => $s->fetchAll()]);
}

// باقي الطلبات تحتاج تسجيل دخول وصلاحية التتبع
if (!me() || !can('track')) jout(['error' => 'forbidden'], 403);
$db = db();

if ($a === 'locations') {
    $drivers = $db->query('SELECT id,name,phone,(tg_chat_id IS NOT NULL) AS tg,last_lat AS lat,last_lng AS lng,last_acc AS acc,last_at AS at FROM drivers WHERE active=1 ORDER BY name')->fetchAll();
    $byDriver = [];
    $ow = ["status IN ('new','out')", 'driver_id IS NOT NULL']; $oa = [];
    shift_where($ow, $oa);
    $oq = $db->prepare('SELECT driver_id,bon,addr,status FROM orders WHERE ' . implode(' AND ', $ow) . ' ORDER BY id');
    $oq->execute($oa);
    foreach ($oq->fetchAll() as $o)
        $byDriver[$o['driver_id']][] = ['bon' => $o['bon'], 'addr' => $o['addr'], 'status' => $o['status']];
    $out = [];
    foreach ($drivers as $d) {
        $out[] = [
            'id' => (int)$d['id'], 'name' => $d['name'], 'tg' => (bool)$d['tg'],
            'lat' => $d['lat'] !== null ? (float)$d['lat'] : null, 'lng' => $d['lng'] !== null ? (float)$d['lng'] : null,
            'acc' => $d['acc'] !== null ? (float)$d['acc'] : null, 'at' => $d['at'] !== null ? (int)$d['at'] : null,
            'orders' => $byDriver[$d['id']] ?? [],
        ];
    }
    jout(['now' => time(), 'drivers' => $out]);
}

if ($a === 'trail') {
    $hours = max(1, min(48, (int)($_GET['hours'] ?? 3)));
    $s = $db->prepare('SELECT lat,lng,at FROM locations WHERE driver_id=? AND at>=? ORDER BY at');
    $s->execute([(int)($_GET['driver'] ?? 0), time() - $hours * 3600]);
    jout(['points' => array_map(fn($r) => [(float)$r['lat'], (float)$r['lng'], (int)$r['at']], $s->fetchAll())]);
}

jout(['error' => 'unknown action'], 404);
