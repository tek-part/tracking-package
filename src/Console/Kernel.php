<?php

namespace Vendor\TrackingPackage\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Vendor\TrackingPackage\TrackingService;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // تشغيل التتبع كل 5 دقائق
        $schedule->call(function () {
            $trackingService = new TrackingService();
            $trackingService->updateLastSeen();
        })->everyFiveMinutes();
        
        // إرسال نبضة قلب كل دقيقة
        $schedule->call(function () {
            $trackingService = new TrackingService();
            $trackingService->startHeartbeat();
        })->everyMinute();
    }
}



