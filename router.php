<?php
// راوتر للسيرفر المدمج في PHP (يُستخدم مع Dockerfile / Railway) ويمنع الوصول للملفات الحساسة.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(data|pages)(/|$)#', $path)
    || preg_match('#\.(sqlite|sqlite-wal|sqlite-shm|json|md)$#', $path)
    || in_array(basename($path), ['config.php', 'lib.php', 'router.php', 'Dockerfile'], true)) {
    http_response_code(403);
    exit('Forbidden');
}
return false;
