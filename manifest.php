<?php
// ملف تعريف التطبيق (PWA) — يخلي المتصفح يعرض «تثبيت التطبيق»
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'id' => './',
    'name' => 'نظام إدارة الكول سنتر',
    'short_name' => 'الكول سنتر',
    'description' => 'إدارة الأوردرات والعملاء والمناديب',
    'lang' => 'ar',
    'dir' => 'rtl',
    'start_url' => './',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#e8eef0',
    'theme_color' => '#10262e',
    'icons' => [
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
