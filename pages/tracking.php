<?php
defined('APP') || exit;
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<p class="mu no">المواقع تصل من تليجرام (Live Location) أو من رابط المتصفح الخاص بكل مندوب. التحديث تلقائي كل 10 ثوانٍ. اضغط على مندوب لعرض مساره آخر 3 ساعات.</p>
<div class="trk">
  <div class="tl" id="tl"><p class="mu" style="padding:12px">جارٍ التحميل…</p></div>
  <div id="map"></div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('map').setView([31.2001, 29.9187], 11);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '© OpenStreetMap'}).addTo(map);
const markers = {}, STS = {new: 'بانتظار المندوب', out: 'خرج للتوصيل'};
let sel = null, trail = null, first = true, last = [];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function cls(age) { return age == null ? 'none' : age < 300 ? 'ok' : age < 1200 ? 'warn' : 'old'; }
function ago(age) {
  if (age == null) return 'لا يوجد موقع';
  if (age < 45) return 'الآن';
  if (age < 3600) return 'منذ ' + Math.round(age / 60) + ' دقيقة';
  if (age < 86400) return 'منذ ' + Math.round(age / 3600) + ' ساعة';
  return 'منذ ' + Math.round(age / 86400) + ' يوم';
}
function popup(d, age) {
  let h = '<b>' + esc(d.name) + '</b><br>' + ago(age);
  if (d.orders.length) h += '<hr style="margin:6px 0">' + d.orders.map(o => '#' + esc(o.bon) + ' — ' + esc(o.addr) + ' (' + STS[o.status] + ')').join('<br>');
  return h;
}
async function load() {
  let j;
  try { const r = await fetch('api.php?a=locations', {cache: 'no-store'}); j = await r.json(); } catch (e) { return; }
  if (!j.drivers) return;
  last = j.drivers;
  const list = document.getElementById('tl'), bounds = [];
  list.innerHTML = '';
  j.drivers.forEach(d => {
    const age = d.at ? j.now - d.at : null, c = cls(age);
    if (d.lat != null) {
      bounds.push([d.lat, d.lng]);
      const icon = L.divIcon({className: '', html: '<div class="pin ' + c + '"><span>' + esc(d.name) + '</span><i></i></div>', iconSize: [0, 0]});
      if (markers[d.id]) { markers[d.id].setLatLng([d.lat, d.lng]).setIcon(icon); }
      else { markers[d.id] = L.marker([d.lat, d.lng], {icon}).addTo(map); }
      markers[d.id].bindPopup(popup(d, age));
    } else if (markers[d.id]) { map.removeLayer(markers[d.id]); delete markers[d.id]; }
    const el = document.createElement('div');
    el.className = 'dv' + (sel === d.id ? ' sel' : '');
    el.innerHTML = '<b><span class="dot ' + c + '"></span>' + esc(d.name) + (d.tg ? ' <span class="tg-on" title="مربوط بتليجرام">✈️</span>' : '') + '</b>'
      + '<small>' + ago(age) + (d.acc ? ' · دقة ' + Math.round(d.acc) + ' م' : '') + '</small>'
      + '<small>' + (d.orders.length ? d.orders.length + ' أوردر جاري' : 'لا أوردرات جارية') + '</small>';
    el.onclick = () => pick(d);
    list.appendChild(el);
  });
  if (first && bounds.length) { map.fitBounds(bounds, {padding: [40, 40], maxZoom: 15}); first = false; }
}
async function pick(d) {
  sel = d.id;
  if (trail) { map.removeLayer(trail); trail = null; }
  if (d.lat != null) {
    map.flyTo([d.lat, d.lng], Math.max(map.getZoom(), 15));
    if (markers[d.id]) markers[d.id].openPopup();
    try {
      const r = await fetch('api.php?a=trail&driver=' + d.id + '&hours=3', {cache: 'no-store'}), j = await r.json();
      if (j.points && j.points.length > 1) trail = L.polyline(j.points.map(p => [p[0], p[1]]), {color: '#0e6b82', weight: 4, opacity: .7}).addTo(map);
    } catch (e) {}
  }
  load();
}
load();
setInterval(load, 10000);
</script>
