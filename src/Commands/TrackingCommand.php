<?php

namespace Vendor\TrackingPackage\Commands;

use Illuminate\Console\Command;
use Vendor\TrackingPackage\TrackingService;

class TrackingCommand extends Command
{
    protected $signature = 'tracking:status';
    protected $description = 'عرض حالة التتبع';

    public function handle()
    {
        $trackingService = new TrackingService();
        
        $status = $trackingService->getProjectStatus();
        
        if ($status) {
            $this->info('حالة التتبع: نشط');
            $this->table(['المعلومة', 'القيمة'], [
                ['آخر ظهور', $status['last_seen'] ?? 'غير محدد'],
                ['الحالة', $status['status'] ?? 'غير محدد'],
                ['تاريخ التسجيل', $status['created_at'] ?? 'غير محدد']
            ]);
        } else {
            $this->error('فشل في الحصول على حالة التتبع');
        }
    }
}



