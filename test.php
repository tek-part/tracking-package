<?php

// اختبار الحزمة المخفية
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/src/autoload.php';

use Vendor\TrackingPackage\TrackingService;

// محاكاة بيئة Laravel
if (!function_exists('config')) {
    function config($key, $default = null) {
        return $default;
    }
}

if (!function_exists('app')) {
    function app() {
        return new class {
            public function version() {
                return '10.0.0';
            }
        };
    }
}

try {
    $trackingService = new TrackingService();
    echo "✅ الحزمة تعمل بنجاح!\n";
    echo "📡 تم تسجيل المشروع في نظام التتبع\n";
    echo "🔄 سيتم إرسال نبضات القلب تلقائياً\n";
} catch (Exception $e) {
    echo "❌ خطأ في الحزمة: " . $e->getMessage() . "\n";
}
