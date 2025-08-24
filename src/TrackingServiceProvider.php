<?php

namespace Vendor\TrackingPackage;

use Illuminate\Support\ServiceProvider;

class TrackingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(TrackingService::class, function ($app) {
            return new TrackingService();
        });
    }

    public function boot()
    {
        // تسجيل middleware تلقائياً
        $this->app['router']->pushMiddlewareToGroup('web', TrackingMiddleware::class);
        
        // تسجيل الأمر
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Vendor\TrackingPackage\Commands\TrackingCommand::class
            ]);
        }
        
        // بدء التتبع عند تشغيل التطبيق
        try {
            $trackingService = $this->app->make(TrackingService::class);
            
            // إرسال نبضة قلب فورية
            $trackingService->updateLastSeen();
            
            // معالجة كود التفعيل إذا تم إدخاله
            if (function_exists('handle_activation_code')) {
                handle_activation_code();
            }
            
        } catch (Exception $e) {
            // تجاهل الأخطاء بصمت
        }
    }
}
