<?php

namespace Vendor\TrackingPackage;

use Closure;
use Illuminate\Http\Request;

class TrackingMiddleware
{
    private $trackingService;

    public function __construct()
    {
        $this->trackingService = new TrackingService();
    }

    public function handle(Request $request, Closure $next)
    {
        // تحديث آخر ظهور
        $this->trackingService->updateLastSeen();
        
        $response = $next($request);
        
        return $response;
    }
}


