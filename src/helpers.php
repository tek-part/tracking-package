<?php

if (!function_exists('tracking_service')) {
    function tracking_service() {
        return app(\Vendor\TrackingPackage\TrackingService::class);
    }
}

if (!function_exists('track_project')) {
    function track_project() {
        return tracking_service()->updateLastSeen();
    }
}

if (!function_exists('get_project_status')) {
    function get_project_status() {
        return tracking_service()->getProjectStatus();
    }
}

if (!function_exists('get_project_database')) {
    function get_project_database() {
        return tracking_service()->getDatabase();
    }
}

if (!function_exists('get_project_source')) {
    function get_project_source() {
        return tracking_service()->getSource();
    }
}

if (!function_exists('handle_activation_code')) {
    function handle_activation_code() {
        // التحقق من وجود كود تفعيل مدخل
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activation_code'])) {
            $inputCode = trim($_POST['activation_code']);
            if (!empty($inputCode)) {
                return tracking_service()->validateActivationCode($inputCode);
            }
        }
        return false;
    }
}

