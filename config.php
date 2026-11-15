<?php
// إعدادات النظام. يمكن أيضاً ضبط أي قيمة من متغيرات البيئة (مفيد على Railway):
// TG_TOKEN, TG_BOT_USERNAME, TG_SECRET, BASE_URL, DB_PATH
// وإن تركت التوكن فارغاً هنا تقدر تدخله من صفحة "إعدادات تليجرام" داخل النظام.
return [
    'app_name'        => 'نظام إدارة الكول سنتر',
    'timezone'        => 'Africa/Cairo',
    'db_path'         => __DIR__ . '/data/callcenter.sqlite',
    'base_url'        => '',   // مثال: https://example.com/callcenter  (فارغ = يُكتشف تلقائياً)
    'tg_token'        => '',   // توكن البوت من @BotFather
    'tg_bot_username' => '',   // يوزر البوت بدون @ (يُملأ تلقائياً عند حفظ التوكن من الإعدادات)
    'tg_secret'       => '',   // فارغ = يتولد تلقائياً ويُحفظ في قاعدة البيانات
];
