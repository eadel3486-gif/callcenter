// Service worker بسيط: الصفحات ديناميكية ومحمية بتسجيل دخول، فمفيش تخزين لبيانات.
// مهمته تفعيل «تثبيت التطبيق» وعرض رسالة لطيفة لو مفيش إنترنت.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET' || e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => new Response(
    '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px 20px;background:#e8eef0;color:#10262e">' +
    '<h2>لا يوجد اتصال بالإنترنت</h2><p>تأكد من الاتصال ثم أعد المحاولة.</p><button onclick="location.reload()" style="padding:10px 24px;font-size:16px">إعادة المحاولة</button></body></html>',
    {headers: {'Content-Type': 'text/html; charset=utf-8'}})));
});
