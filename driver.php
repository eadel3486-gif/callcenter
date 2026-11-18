<?php
// صفحة المندوب لإرسال موقعه من المتصفح (بديل Live Location في تليجرام). تحتاج HTTPS.
require __DIR__ . '/lib.php';
$s = db()->prepare('SELECT name FROM drivers WHERE token=? AND active=1');
$s->execute([(string)($_GET['t'] ?? '')]);
$name = $s->fetchColumn();
if (!$name) { http_response_code(404); echo html_head('غير متاح') . '<p style="padding:30px">الرابط غير صالح. اطلب رابطاً جديداً من المشرف.</p></body></html>'; exit; }
echo html_head('إرسال الموقع — ' . $name);
?>
<div class="lg">
  <h2>أهلاً <?= h($name) ?></h2>
  <p class="mu">اضغط «ابدأ» وسيب الصفحة مفتوحة أثناء الشغل، علشان المشرف يشوف مكانك.</p>
  <button class="btn" id="go" onclick="start()">ابدأ إرسال الموقع</button>
  <button class="btn r" id="stop" onclick="stop()" hidden>إيقاف</button>
  <div id="st" class="mu"></div>
</div>
<script>
const TOKEN = <?= json_encode($_GET['t']) ?>;
let wid = null, lastSent = 0, wl = null;
const st = t => document.getElementById('st').textContent = t;
async function start() {
  if (!navigator.geolocation) { st('المتصفح لا يدعم تحديد الموقع'); return; }
  if (!window.isSecureContext) { st('لازم الصفحة تُفتح عبر HTTPS'); return; }
  try { wl = await navigator.wakeLock.request('screen'); } catch (e) {}
  wid = navigator.geolocation.watchPosition(send, e => st('تعذر تحديد الموقع: ' + e.message), {enableHighAccuracy: true, maximumAge: 5000, timeout: 30000});
  document.getElementById('go').hidden = true; document.getElementById('stop').hidden = false;
  st('جارٍ تحديد موقعك…');
}
function stop() {
  if (wid !== null) navigator.geolocation.clearWatch(wid);
  if (wl) wl.release();
  wid = null; document.getElementById('go').hidden = false; document.getElementById('stop').hidden = true; st('تم الإيقاف');
}
async function send(p) {
  const now = Date.now();
  if (now - lastSent < 15000) return;
  lastSent = now;
  try {
    const r = await fetch('api.php?a=ping', {method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({t: TOKEN, lat: p.coords.latitude, lng: p.coords.longitude, acc: p.coords.accuracy})});
    const j = await r.json();
    st(j.ok ? 'آخر إرسال: ' + new Date().toLocaleTimeString('ar-EG') + ' ✅' : 'فشل الإرسال');
  } catch (e) { st('لا يوجد اتصال — سيُعاد المحاولة'); }
}
document.addEventListener('visibilitychange', async () => {
  if (wid !== null && document.visibilityState === 'visible') { try { wl = await navigator.wakeLock.request('screen'); } catch (e) {} }
});
</script>
</body></html>
