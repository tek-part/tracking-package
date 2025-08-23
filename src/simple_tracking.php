<?php
/**
 * ملف تتبع بسيط - ضعه في بداية public/index.php
 * 
 * كيفية الاستخدام:
 * 1. انسخ هذا الملف إلى المشروع المتبوع
 * 2. أضف هذا السطر في بداية public/index.php:
 *    require_once __DIR__ . '/../simple_tracking.php';
 */

// منع الوصول المباشر
if (!defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

// إعدادات الباكدج
$TRACKING_CONFIG = [
    'central_url' => 'http://127.0.0.1:8000/api', // URL الخادم المركزي
    'domain' => $_SERVER['HTTP_HOST'] ?? 'localhost'
];

/**
 * فحص حالة المشروع
 */
function checkProjectStatus() {
    global $TRACKING_CONFIG;
    
    try {
        // محاولة الحصول على معلومات المشروع من الخادم المركزي
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $TRACKING_CONFIG['central_url'] . '/project/info?domain=' . urlencode($TRACKING_CONFIG['domain']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Laravel-Tracking-Package/1.0'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if ($data && isset($data['data'])) {
                $project = $data['data'];
                
                // التحقق من حالة المشروع
                if (!$project['is_active']) {
                    // المشروع متوقف، عرض رسالة الإيقاف
                    displaySuspensionMessage($project);
                }
            }
        }
        
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }
}

/**
 * عرض رسالة إيقاف المشروع
 */
function displaySuspensionMessage($statusData) {
    $reason = $statusData['suspended_reason'] ?? 'تم إيقاف المشروع من لوحة التحكم';
    $projectName = $statusData['project_name'] ?? 'المشروع';
    
    // تعيين headers
    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Temporarily Unavailable');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    
    // عرض صفحة الإيقاف
    echo '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المشروع متوقف</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; }
        .container { max-width: 600px; margin: 50px auto; text-align: center; }
        .alert { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .icon { font-size: 48px; margin-bottom: 20px; }
        .title { color: #dc3545; font-size: 24px; margin-bottom: 15px; }
        .message { color: #6c757d; margin-bottom: 30px; line-height: 1.6; }
        .reason { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .form { background: #fff; padding: 20px; border-radius: 5px; border: 1px solid #ced4da; }
        .input { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 16px; margin-bottom: 15px; box-sizing: border-box; }
        .button { background: #007bff; color: white; border: none; padding: 12px 30px; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .button:hover { background: #0056b3; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="alert">
            <div class="icon">⚠️</div>
            <div class="title">المشروع متوقف</div>
            <div class="message">' . htmlspecialchars($projectName) . ' متوقف حالياً. يرجى إدخال كود التفعيل لإعادة تشغيله.</div>
            <div class="reason">
                <strong>سبب الإيقاف:</strong> ' . htmlspecialchars($reason) . '
            </div>';
    
    // عرض رسالة خطأ إذا وجدت
    if (isset($_GET['error'])) {
        echo '<div class="error">' . htmlspecialchars($_GET['error']) . '</div>';
    }
    
    // عرض رسالة نجاح إذا وجدت
    if (isset($_GET['success'])) {
        echo '<div class="success">' . htmlspecialchars($_GET['success']) . '</div>';
    }
    
    echo '<div class="form">
                <form method="POST" action="">
                    <input type="text" name="activation_code" class="input" placeholder="أدخل كود التفعيل هنا" required>
                    <button type="submit" class="button">إعادة تفعيل المشروع</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>';
    exit;
}

/**
 * معالجة كود التفعيل
 */
function handleActivationCode() {
    global $TRACKING_CONFIG;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activation_code'])) {
        $inputCode = trim($_POST['activation_code']);
        if (!empty($inputCode)) {
            // التحقق من الكود
            try {
                // أولاً، الحصول على معلومات المشروع
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $TRACKING_CONFIG['central_url'] . '/project/info?domain=' . urlencode($TRACKING_CONFIG['domain']),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'User-Agent: Laravel-Tracking-Package/1.0'
                    ]
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if ($data && isset($data['data'])) {
                        $project = $data['data'];
                        
                        // التحقق من أن كود التفعيل صحيح
                        if ($project['activation_code'] === $inputCode) {
                            // الكود صحيح، إعادة تفعيل المشروع
                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL => $TRACKING_CONFIG['central_url'] . '/project/' . $project['id'] . '/reactivate',
                                CURLOPT_POST => true,
                                CURLOPT_HTTPHEADER => [
                                    'Content-Type: application/json',
                                    'User-Agent: Laravel-Tracking-Package/1.0'
                                ],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15,
                                CURLOPT_SSL_VERIFYPEER => false
                            ]);
                            
                            $reactivateResponse = curl_exec($ch);
                            $reactivateHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($reactivateHttpCode === 200) {
                                // تم إعادة التفعيل بنجاح، إعادة توجيه مع رسالة نجاح
                                $redirectUrl = $_SERVER['REQUEST_URI'];
                                // إزالة أي query parameters موجودة
                                $redirectUrl = strtok($redirectUrl, '?');
                                // إضافة رسالة النجاح
                                $redirectUrl .= '?success=' . urlencode('تم إعادة تفعيل المشروع بنجاح!');
                                header('Location: ' . $redirectUrl);
                                exit;
                            } else {
                                // فشل في إعادة التفعيل، إعادة توجيه مع رسالة خطأ
                                $redirectUrl = $_SERVER['REQUEST_URI'];
                                $redirectUrl = strtok($redirectUrl, '?');
                                $redirectUrl .= '?error=' . urlencode('فشل في إعادة تفعيل المشروع. HTTP Code: ' . $reactivateHttpCode);
                                header('Location: ' . $redirectUrl);
                                exit;
                            }
                        } else {
                            // كود التفعيل غير صحيح، إعادة توجيه مع رسالة خطأ
                            $redirectUrl = $_SERVER['REQUEST_URI'];
                            $redirectUrl = strtok($redirectUrl, '?');
                            $redirectUrl .= '?error=' . urlencode('كود التفعيل غير صحيح');
                            header('Location: ' . $redirectUrl);
                            exit;
                        }
                    } else {
                        // لم يتم العثور على المشروع، إعادة توجيه مع رسالة خطأ
                        $redirectUrl = $_SERVER['REQUEST_URI'];
                        $redirectUrl = strtok($redirectUrl, '?');
                        $redirectUrl .= '?error=' . urlencode('لم يتم العثور على المشروع');
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                } else {
                    // فشل في الاتصال بالخادم المركزي، إعادة توجيه مع رسالة خطأ
                    $redirectUrl = $_SERVER['REQUEST_URI'];
                    $redirectUrl = strtok($redirectUrl, '?');
                    $redirectUrl .= '?error=' . urlencode('فشل في الاتصال بالخادم المركزي. HTTP Code: ' . $httpCode);
                    header('Location: ' . $redirectUrl);
                    exit;
                }
                
            } catch (Exception $e) {
                // خطأ في معالجة كود التفعيل، إعادة توجيه مع رسالة خطأ
                $redirectUrl = $_SERVER['REQUEST_URI'];
                $redirectUrl = strtok($redirectUrl, '?');
                $redirectUrl .= '?error=' . urlencode('خطأ في معالجة كود التفعيل: ' . $e->getMessage());
                header('Location: ' . $redirectUrl);
                exit;
            }
        } else {
            // لم يتم إدخال كود التفعيل، إعادة توجيه مع رسالة خطأ
            $redirectUrl = $_SERVER['REQUEST_URI'];
            $redirectUrl = strtok($redirectUrl, '?');
            $redirectUrl .= '?error=' . urlencode('يرجى إدخال كود التفعيل');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

// معالجة كود التفعيل أولاً
handleActivationCode();

// فحص حالة المشروع فقط إذا لم يتم إرسال كود التفعيل
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['activation_code'])) {
    checkProjectStatus();
}

// إذا وصلنا هنا، المشروع نشط، استمر في التحميل
?>

