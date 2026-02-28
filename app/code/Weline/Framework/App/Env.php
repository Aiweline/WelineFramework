<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Output\PrintInterface;
use Weline\Framework\System\File\Io\File;

class Env extends DataObject
{
    public const vendor_path = BP . 'vendor' . DS;

    public const framework_name = 'Weline';
    public const framework_path = self::vendor_path . 'weline' . DS . 'framework' . DS;
    public const framework_code_path = APP_CODE_PATH . 'Weline' . DS . 'Framework' . DS;

    public const path_framework_generated = BP . 'generated' . DS;
    public const path_bin = BP . 'bin' . DS;

    public const path_framework_generated_code = self::path_framework_generated . 'code' . DS;

    # 框架模板文件位置
    public const path_framework_generated_complicate = self::path_framework_generated . 'complicate' . DS;

    // -----------------路径--------------------
    public const path_ENV_FILE = APP_ETC_PATH . 'env.php';

    public const path_SYSTEM_META_DATA = self::path_framework_generated . 'configs.php'; //FIXME 元数据等待开发

    public const path_MODULES_FILE = APP_ETC_PATH . 'modules.php';

    public const path_MODULE_DEPENDENCIES_FILE = APP_ETC_PATH . 'module_dependencies.php';

    public const path_COMMANDS_FILE = self::path_framework_generated . 'commands.php';

    // 注册register路径

    public const path_VENDOR_CODE = self::vendor_path;

    public const path_CODE_DESIGN = BP . 'app' . DS . 'design' . DS;

    public const path_LANGUAGE_PACK = BP . 'app' . DS . 'i18n' . DS;

    public const register_FILE_PATHS = [
        'app_code' => APP_CODE_PATH,
        'vendor_code' => self::path_VENDOR_CODE,
        'theme_design' => self::path_CODE_DESIGN,
        'language_pack' => self::path_LANGUAGE_PACK,
    ];

    public const default_theme_DATA = [
        'id' => 0,
        'name' => 'default',
        'path' => 'Weline' . DS . 'default',
        'parent_id' => null,
        'is_active' => 1,
        'create_time' => '2021-04-05 16:49:58',
    ];

    # 助手函数文件位置
    public const path_FUNCTIONS_FILE = self::path_framework_generated . 'functions.php';
    // 路由
    public const path_ROUTERS_DIR = self::path_framework_generated . 'routers' . DS;

    public const path_BACKEND_REST_API_ROUTER_FILE = self::path_ROUTERS_DIR . 'backend_rest_api.php';

    public const path_BACKEND_PC_ROUTER_FILE = self::path_ROUTERS_DIR . 'backend_pc.php';

    public const path_FRONTEND_REST_API_ROUTER_FILE = self::path_ROUTERS_DIR . 'frontend_rest_api.php';

    public const path_FRONTEND_PC_ROUTER_FILE = self::path_ROUTERS_DIR . 'frontend_pc.php';

    public const router_files_PATH = [
        self::path_BACKEND_REST_API_ROUTER_FILE,
        self::path_FRONTEND_REST_API_ROUTER_FILE,
        self::path_BACKEND_PC_ROUTER_FILE,
        self::path_FRONTEND_PC_ROUTER_FILE,
    ];

    // 生成的var目录
    public const VAR_DIR = BP . 'var' . DS;

    // 生成文件的目录
    public const GENERATED_DIR = BP . 'generated';

    // 编译生成文件目录
    public const path_COMPLICATE_GENERATED_DIR = self::GENERATED_DIR . DS . 'complicate' . DS;

    // 翻译词典 目录
    public const path_TRANSLATE_FILES_PATH = self::GENERATED_DIR . DS . 'language' . DS;

    public const default_LANGUAGE_CODE = 'zh_Hans_CN';
    public const path_TRANSLATE_DEFAULT_FILE = self::GENERATED_DIR . DS . 'language' . DS . self::default_LANGUAGE_CODE . '.php';

    public const path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE = self::GENERATED_DIR . DS . 'language' . DS . 'words.php';

    // 日志
    public const log_path_ERROR = 'error';

    public const log_path_EXCEPTION = 'exception';

    public const log_path_NOTICE = 'notice';

    public const log_path_WARNING = 'warning';

    public const log_path_DEBUG = 'debug';

    // 拓展目录
    public const extend_dir = BP . 'extend' . DS;

    // 拓展目录
    public const backup_dir = self::VAR_DIR . DS . 'backup' . DS;

    // 卸载备份目录（默认在项目根目录的上级目录的 storage 目录）
    public const path_UNINSTALL_BACKUP_DIR = null; // 将在 getUninstallBackupDir() 中动态获取

    // 主题设计
    public const path_THEME_DESIGN_DIR = BP . 'app' . DS . 'design' . DS;
    // 主题设计
    public const path_UPLOAD_DIR = PUB . 'upload' . DS;

    // 变量

    /**
     * @var Env
     */
    private static Env $instance;
    /**
     * 是否由业务逻辑强制开启沙盒模式
     */
    private bool $sandboxOverride = false;

    public const default_CONFIG = [
        'env' => 'local',
        'event'=>[
            'debug' => false,
            'scan_enabled' => false, // 是否启用事件扫描回退机制，默认关闭以提升性能
        ],
        'cache' => self::default_CACHE,
        'session' => self::default_SESSION,
        'log' => self::default_LOG,
        'php-cs' => false,
        'lang' => 'zh_Hans_CN',
        'currency' => 'CNY',
        'uninstall' => [
            'backup_dir' => null, // 如果为 null，则使用项目根目录上级的 storage 目录
        ],
        'db' => [
            'default' => 'sqlite',
            'master' => [
                'type' => 'sqlite',
                'path' => APP_PATH . 'etc/db.sqlite',
//                'hostname' => 'demo',
//                'database' => 'demo',
//                'username' => 'demo',
//                'password' => 'demo',
//                'type' => 'sqlite',
//                'hostport' => '3306',
//                'prefix' => 'm_',
//                'charset' => 'utf8mb4',
//                'collate' => 'utf8mb4_general_ci',
            ],
            'slaves' => [

            ],
        ],
        'sandbox_db' => [
            'default' => 'sqlite',
            'master' => [
                'type' => 'sqlite',
                'path' => APP_PATH . 'etc/sandbox_db.sqlite'
            ],
            'slaves' => [
            ],
        ],
    ];

    // 日志
    public const default_LOG = [
        'error' => 'var' . DS . 'log' . DS . 'error.log',
        'exception' => 'var' . DS . 'log' . DS . 'exception.log',
        'notice' => 'var' . DS . 'log' . DS . 'notice.log',
        'warning' => 'var' . DS . 'log' . DS . 'warning.log',
        'debug' => 'var' . DS . 'log' . DS . 'debug.log',
        'db' => [
            'enabled' => false,
            'file' => 'db',
        ],
        'dev_sql' => [
            'enabled' => false,
            'file' => 'dev_sql',
        ],
    ];

    // 缓存
    public const default_CACHE = [
        'default' => 'file',
        'drivers' => [
            'file' => [
                'path' => 'var/cache/',
            ],
            'redis' => [
                'tip' => '开发中...',
                'server' => '127.0.0.1',
                'port' => 6379,
                'database' => 1,
            ],
        ],
        'status' => [
            'config' => 1,
            'framework_controller' => 1,
            'database' => 1,
            'database_model' => 1,
            'framework_event' => 1,
            'framework_object' => 1,
            'framework_phrase' => 1,
            'framework_plugin' => 1,
            'router_cache' => 1,
            'framework_view' => 1,
            'frontend_cache' => 1,
        ]
    ];

    // Session
    public const default_SESSION = [
        'default' => 'file',
        'drivers' => [
            'file' => [
                'path' => 'var/session/',
            ],
            'mysql' => [
                'tip' => '开发中...',
            ],
            'redis' => [
                'tip' => '开发中...',
            ],
        ],
    ];

    private array $config = [];

    private array $module_list = [];
    private static array $module_configs = [];
    private array $active_module_list = [];

    private array $hasGetConfig;

    private array $dependencies = [];

    private static $user = '';

    /** 合并 DB 后的 cache 配置缓存，进程/请求内只合并一次，setConfig('cache') 时清空；WLS 下由 StateManager 按请求重置 */
    private static $mergedCacheConfig = null;

    /**
     * @DESC         |私有化克隆函数
     *
     * 参数区：
     */
    private function __clone()
    {
    }

    /**
     * Env 私有化 初始函数...
     */
    private function __construct()
    {
        parent::__construct();
        try {
            $this->reload();
        } catch (Exception $e) {
            throw new Exception(__('系统加载错误：%{1}', $e->getMessage()));
        }
    }

    public static function check_user(): void
    {
        $current_user = Env::user();
        if (empty(Env::get('user'))) {
            $etc = str_replace(BP, '', Env::path_ENV_FILE);
            $msg = '[' . PHP_OS . ']' . __('运行失败： 非站点运行用户禁止执行！请前往 %{1} 配置user键指定网站运行用户。示例\'user\'=>\'www\'。', $etc);
            if (CLI) {
                /** @var Printing $printing */
                $printing = ObjectManager::getInstance(Printing::class);
                $printing->error($msg);
                exit(1);
            } else {
                die($msg);
            }
        }
        if ($current_user !== Env::get('user')) {
            if (CLI) {
                /** @var Printing $printing */
                $printing = ObjectManager::getInstance(Printing::class);
                $msg = '[' . PHP_OS . ']' . __('运行失败： 非站点运行用户禁止执行！请检查当前用户：%{1} 是否与站点运行用户：%{2} 相同！', [
                        $current_user, Env::get('user'),
                    ]);
                $printing->error($msg);
                exit(1);
            } else {
                die('[' . PHP_OS . ']' . __('运行失败： 非站点运行用户禁止执行！请检查当前用户：%{1} 是否与站点运行用户：%{2} 相同！', [
                        $current_user, Env::get('user'),
                    ]));
            }
        }
    }

    public static function user(): string
    {
        if (self::$user) {
            return self::$user;
        }
        // 读取当前脚本运行用户
        self::$user = get_current_user();
        return self::$user;
    }

    static function real_config(string $key, mixed $value = null): string|null
    {
        if (null !== $value) {
            self::set($key, $value);
        }
        return ((array)include self::path_ENV_FILE)[$key] ?? null;
    }

    public function reload(): static
    {
        // 清空依赖本实例的缓存，保证后续 getModuleList/getActiveModules/getDependencies/module_env 读取到最新文件
        $this->module_list = [];
        $this->active_module_list = [];
        $this->dependencies = [];
        self::$module_configs = [];

        // 检查环境配置文件是否存在，不存在则创建
        if (!is_file(self::path_ENV_FILE)) {
            $file = new File();
            try {
                $file->open(self::path_ENV_FILE, $file::mode_w_add);
                $text = '<?php return ' . w_var_export([], true) . '; ?>';
                $file->write($text);
                $file->close();
            } catch (Exception $e) {
                $file->close();
                throw new Exception(__('错误：%{1}', $e->getMessage()));
            }
        }
        // 合并默认配置与环境配置文件内容
        $envConfig = [];
        $envFile = self::path_ENV_FILE;
        if (is_file($envFile)) {
            $envConfig = include $envFile;
            if (!is_array($envConfig)) {
                $envConfig = [];
            }
        }
        $this->config = array_merge(self::default_CONFIG, $envConfig);
        $this->setData($this->config);
        return $this;
    }

    /**
     * @DESC         |获得实例
     *
     * 参数区：
     *
     * @return Env
     */
    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function get(string $name = '', mixed $defaultOrModule = '', string $module = ''): mixed
    {
        // 如果第三个参数存在，说明第二个参数是默认值，第三个是模块名
        if ($module) {
            $result = self::module_env($module, $name);
            return $result ?? $defaultOrModule;
        }
        
        // 判断第二个参数是模块名还是默认值
        // 如果是字符串且首字母大写或包含反斜杠命名空间，认为是模块名
        if (is_string($defaultOrModule) && $defaultOrModule !== '' && (
            ctype_upper($defaultOrModule[0]) || 
            str_contains($defaultOrModule, '\\') ||
            str_contains($defaultOrModule, '/')
        )) {
            // 当作模块名处理
            return self::module_env($defaultOrModule, $name);
        }
        
        // 否则当作默认值处理
        return self::getInstance()->getConfig($name, $defaultOrModule);
    }

    public static function module_env(string $module, string $name = ''): mixed
    {
        if (isset(self::$module_configs[$module]) and $module_env = self::$module_configs[$module]) {
            if (empty($name)) {
                return $module_env;
            }
            return $module_env[$name] ?? null;
        }
        $module = Env::getInstance()->getModuleInfo($module);
        $local_env = [];
        if (is_file($module['base_path'] . 'etc' . DS . 'env.php')) {
            $local_env = include $module['base_path'] . 'etc' . DS . 'env.php';
            if (!is_array($local_env)) {
                return [];
            }
        }
        self::$module_configs[$module['name']] = $local_env;
        if (empty($name)) {
            return $local_env;
        }
        return $local_env[$name] ?? null;
    }

    public static function set(string $name, $value): bool
    {
        return self::getInstance()->setConfig($name, $value);
    }

    /**
     * Universal logging method with support for standard log formats
     * 
     * @param string $filename Log filename (without path, with or without .log extension)
     * @param string $content Log message content
     * @param string $level Log level: INFO, ERROR, WARNING, NOTICE, DEBUG, QUERY, etc.
     * @param bool $compact Use compact single-line format (true) or verbose multi-line format (false)
     * @param bool $append Append to file (true) or overwrite (false)
     * @param int $debug_level Backtrace depth for file info (0 = immediate caller)
     * @return bool Success status
     */
    public static function log(
        string $filename, 
        string $content, 
        string $level = 'INFO',
        bool $compact = true,
        bool $append = true,
        int $debug_level = 0
    ): bool {
        // Normalize line endings
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        
        $timestamp = date('Y-m-d H:i:s');
        
        if ($compact) {
            // Standard compact format: [timestamp] [LEVEL] source - message
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $debug_level + 2);
            $caller = $backtrace[$debug_level + 1] ?? $backtrace[0];
            $sourceFile = basename($caller['file'] ?? 'unknown');
            $sourceLine = $caller['line'] ?? 0;
            
            // For multi-line content, make it single line or format as JSON
            if (str_contains($content, "\n")) {
                // Multi-line content: compress whitespace
                $content = preg_replace('/\s+/', ' ', trim($content));
            }
            
            $logEntry = "[{$timestamp}] [{$level}] {$sourceFile}:{$sourceLine} - {$content}\n";
        } else {
            // Verbose format for backward compatibility
            $header_line = '-------------------------' . $timestamp . ' ' . __('开始') . '------------------' . "\n";
            $end_line = '-------------------------' . $timestamp . ' ' . __('结束') . '------------------' . "\n";
            
            $backtrace = debug_backtrace();
            $caller = $backtrace[$debug_level];
            $file = $caller['file'] ?? 'unknown';
            $line = $caller['line'] ?? 0;
            $header_line .= '-------------------' . $file . ' line ' . $line . '------------------------' . "\n" . $header_line;
            $end_line .= '-------------------' . $file . ' line ' . $line . '------------------------' . "\n" . $end_line;
            
            $logEntry = $header_line . "\n" . $content . "\n" . $end_line . "\n\n";
        }
        
        // Ensure proper file path
        if (!str_contains($filename, BP)) {
            // Remove .log extension if present (will be added below)
            $filename = preg_replace('/\.log$/', '', $filename);
            // Sanitize filename: replace illegal characters for Windows filesystem
            // Illegal chars: \ / : * ? " < > | and also replace spaces with underscores
            $filename = preg_replace('/[\\\\\/:\*\?"<>\|\s]+/', '_', $filename);
            // Remove leading/trailing underscores and collapse multiple underscores
            $filename = preg_replace('/_+/', '_', trim($filename, '_'));
            $filename = Env::VAR_DIR . 'log' . DS . $filename . '.log';
        }
        
        // Create directory if needed
        if (!is_file($filename)) {
            if (!is_dir(dirname($filename))) {
                mkdir(dirname($filename), 0777, true);
            }
        }
        
        // Write to file
        if ($append) {
            return file_put_contents($filename, $logEntry, FILE_APPEND) !== false;
        } else {
            return file_put_contents($filename, $logEntry) !== false;
        }
    }

    /**
     * SQL query logging with formatted output
     * 
     * @param string $filename Log filename
     * @param string $sql SQL query to log
     * @param bool $compact Use compact format (default: true for standard format)
     * @return bool Success status
     */
    public static function sql_log(string $filename, string $sql, bool $compact = true): bool
    {
        // Format SQL for readability if verbose mode
        if (!$compact) {
            $sql = \SqlFormatter::format($sql);
        }
        return self::log($filename, $sql, 'QUERY', $compact, true, 1);
    }

    /**
     * Convenience methods for different log levels (PSR-3 inspired)
     */
    public static function log_error(string $filename, string $message): bool
    {
        return self::log($filename, $message, 'ERROR', true, true, 0);
    }

    public static function log_warning(string $filename, string $message): bool
    {
        return self::log($filename, $message, 'WARNING', true, true, 0);
    }

    public static function log_info(string $filename, string $message): bool
    {
        return self::log($filename, $message, 'INFO', true, true, 0);
    }

    public static function log_debug(string $filename, string $message): bool
    {
        return self::log($filename, $message, 'DEBUG', true, true, 0);
    }

    public static function log_notice(string $filename, string $message): bool
    {
        return self::log($filename, $message, 'NOTICE', true, true, 0);
    }

    /**
     * 维护模式标志文件路径（用于 WLS 跨进程通信）
     */
    private const MAINTENANCE_FLAG_FILE = 'var/maintenance.flag';
    
    /**
     * WLS 维护模式缓存（进程内缓存，避免每次请求都检查文件）
     * @var bool|null
     */
    private static ?bool $maintenanceCached = null;
    
    /**
     * 维护模式缓存的最后检查时间
     * @var float
     */
    private static float $maintenanceLastCheck = 0.0;
    
    /**
     * 维护模式缓存刷新间隔（秒）
     * 默认 1 秒，可通过 env.server.maintenance_check_interval 配置
     */
    private const MAINTENANCE_CHECK_INTERVAL = 1.0;
    
    /**
     * 检查维护模式状态（带缓存优化）
     * 
     * 性能说明：
     * - 使用进程内缓存，每秒最多只检查一次文件
     * - file_exists() 本身很快（~1微秒），但缓存后可降至 ~0.1 微秒
     * - 在 10000 QPS 场景下，从 10000 次/秒降至 1 次/秒的文件检查
     * 
     * @return bool
     */
    private function checkMaintenanceMode(): bool
    {
        $now = \microtime(true);
        
        // 获取配置的刷新间隔（允许通过配置自定义）
        $interval = ($this->config['server'] ?? [])['maintenance_check_interval'] 
                    ?? self::MAINTENANCE_CHECK_INTERVAL;
        
        // 如果缓存有效（未超过刷新间隔），直接返回缓存值
        if (self::$maintenanceCached !== null 
            && ($now - self::$maintenanceLastCheck) < $interval
        ) {
            return self::$maintenanceCached;
        }
        
        // 缓存过期，检查文件并更新缓存
        self::$maintenanceLastCheck = $now;
        self::$maintenanceCached = \file_exists(BP . self::MAINTENANCE_FLAG_FILE);
        
        return self::$maintenanceCached;
    }
    
    /**
     * 强制刷新维护模式缓存（用于接收到 SIGUSR2 信号时）
     * 
     * @return void
     */
    public static function refreshMaintenanceCache(): void
    {
        self::$maintenanceCached = null;
        self::$maintenanceLastCheck = 0.0;
    }
    
    /**
     * @DESC         |获取环境参数
     *
     * 参数区：
     *
     * @param string $name
     * @param        $default
     *
     * @return mixed
     */
    public function getConfig(string $name = '', $default = null): mixed
    {
        // 维护模式特殊处理：在常驻内存模式下使用带缓存的检查（支持跨进程通信）
        if ($name === 'maintenance' && \Weline\Framework\Runtime\Runtime::isPersistent()) {
            return $this->checkMaintenanceMode();
        }
        
        # 使用.获取数组数据
        if (str_contains($name, '.')) {
            $config = $this->config;
            $name = explode('.', $name);
            foreach ($name as $key) {
                if (isset($config[$key])) {
                    $config = $config[$key];
                } else {
                    return $default;
                }
            }
            return $config;
        }
        if (isset($this->hasGetConfig[$name])) {
            return $this->hasGetConfig[$name];
        }
        if ('' === $name) {
            return $this->config;
        }

        $value = $this->config[$name] ?? $default;
        // 缓存配置以数据库为准：一次性合并 DB 后缓存，避免重复读取（reload 等场景会多次 getConfig('cache')）
        if ($name === 'cache' && is_array($value)) {
            if (self::$mergedCacheConfig !== null) {
                return self::$mergedCacheConfig;
            }
            self::$mergedCacheConfig = $this->mergeCacheStatusFromDb($value);
            return self::$mergedCacheConfig;
        }
        return $value;
    }

    /**
     * 从数据库合并缓存开关状态（后台 admin/system/cache 配置为准）
     * 仅当 CacheManager 模块存在且表可读时合并，避免强依赖
     *
     * @param array $cacheConfig 当前 cache 配置（来自 env.php）
     * @return array 合并后的 cache 配置，status 以 DB 为准
     */
    private function mergeCacheStatusFromDb(array $cacheConfig): array
    {
        if (!class_exists(\Weline\CacheManager\Model\Cache::class)) {
            return $cacheConfig;
        }
        static $merging = false;
        if ($merging) {
            return $cacheConfig;
        }
        $merging = true;
        try {
            /** @var \Weline\CacheManager\Model\Cache $cacheModel */
            $cacheModel = ObjectManager::getInstance(\Weline\CacheManager\Model\Cache::class);
            $collection = $cacheModel->select()->fetch();
            $items = $collection ? $collection->getItems() : [];
            if (!$collection || !$items) {
                return $cacheConfig;
            }
            $status = $cacheConfig['status'] ?? [];
            foreach ($items as $item) {
                $identity = $item->getData('identity');
                if ($identity !== null && $identity !== '') {
                    $status[$identity] = (int)$item->getData('status');
                }
            }
            $cacheConfig['status'] = $status;
        } catch (\Throwable $e) {
            // 表不存在或未安装时忽略，继续使用 env 配置
        } finally {
            $merging = false;
        }
        return $cacheConfig;
    }

    /**
     * 获取数据，对 cache/status/* 以数据库为准（后台配置覆盖 env）
     */
    public function getData(string $key = '', $index = null): mixed
    {
        if ($key !== '' && str_starts_with($key, 'cache/status/')) {
            $identity = substr($key, strlen('cache/status/'));
            if ($identity !== '') {
                if (self::$mergedCacheConfig !== null && array_key_exists($identity, self::$mergedCacheConfig['status'] ?? [])) {
                    $v = (int)self::$mergedCacheConfig['status'][$identity];
                    return $index !== null ? [$v][$index] ?? null : $v;
                }
                $fromDb = $this->getCacheStatusByIdentityFromDb($identity);
                if ($fromDb !== null) {
                    return $index !== null ? [$fromDb][$index] ?? null : $fromDb;
                }
            }
        }
        return parent::getData($key, $index);
    }

    /**
     * 从数据库读取单个 identity 的缓存开关状态
     *
     * @return int|null 1 启用 0 禁用，不存在或未安装时返回 null
     */
    private function getCacheStatusByIdentityFromDb(string $identity): ?int
    {
        if (!class_exists(\Weline\CacheManager\Model\Cache::class)) {
            return null;
        }
        try {
            /** @var \Weline\CacheManager\Model\Cache $cacheModel */
            $cacheModel = ObjectManager::getInstance(\Weline\CacheManager\Model\Cache::class);
            $model = $cacheModel->where('identity', $identity)->find()->fetch();
            if ($model && $model->getId()) {
                return (int)$model->getData('status');
            }
        } catch (\Throwable $e) {
            // 表不存在或未安装时忽略
        }
        return null;
    }

    public function getTheme()
    {
        return $this->getConfig('theme', self::default_theme_DATA);
    }

    /**
     * @DESC         |设置环境参数
     *
     * 参数区：
     *
     * @param string $key
     * @param        $value
     *
     * @return bool
     */
    public function setConfig(string $key, $value = null): bool
    {
        // 维护模式特殊处理：同时管理文件标志（支持 WLS 跨进程通信）
        if ($key === 'maintenance') {
            $this->setMaintenanceFlag((bool) $value);
        }
        if ($key === 'cache') {
            self::$mergedCacheConfig = null;
        }
        // 如果键包含点，则处理嵌套设置
        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $config = &$this->config;

            // 遍历键路径，创建或设置中间层级数组
            foreach ($keys as $k) {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }

            // 设置最终值
            $config[$lastKey] = $value;
        } else {
            // 处理单一层级设置
            $this->config[$key] = $value;
        }

        // 更新缓存的配置文件
        try {
            $file = new File();
            $file->open(self::path_ENV_FILE, $file::mode_w);
            $text = '<?php return ' . w_var_export($this->config, true) . ';';
            $file->write($text);
            $file->close();
            return true;
        } catch (Exception $exception) {
            return false;
        }
    }
    
    /**
     * 设置维护模式文件标志（用于 WLS 跨进程通信）
     * 
     * @param bool $enabled 是否开启维护模式
     * @return void
     */
    private function setMaintenanceFlag(bool $enabled): void
    {
        $flagPath = BP . self::MAINTENANCE_FLAG_FILE;
        $flagDir = \dirname($flagPath);
        
        if ($enabled) {
            // 确保目录存在
            if (!\is_dir($flagDir)) {
                @\mkdir($flagDir, 0755, true);
            }
            // 创建标志文件
            @\file_put_contents($flagPath, \json_encode([
                'enabled' => true,
                'started_at' => \time(),
                'pid' => \getmypid(),
            ]));
        } else {
            // 删除标志文件
            if (\file_exists($flagPath)) {
                @\unlink($flagPath);
            }
        }
        
        // 同步刷新本进程缓存（对于当前进程立即生效）
        self::$maintenanceCached = $enabled;
        self::$maintenanceLastCheck = \microtime(true);
    }

    /**
     * @DESC         |读取log路径
     *
     * 参数区：
     *
     * @param string $type
     *
     * @return string
     */
    public function getLogPath(string $type): string
    {
        return BP . $this->config['log'][$type];
    }

    /**
     * @DESC         |获取数据库配置
     *
     * 参数区：
     *
     * @return array
     */
    public function getDbConfig(): array
    {
        $sandboxEnabled = $this->isSandboxMode();
        if ($sandboxEnabled || DEBUG) {
            $sandbox_db = $this->config['sandbox_db'] ?? [];
            if ($sandbox_db) {
                return $sandbox_db;
            } else {
                # 默认使用Sqlite
                $driver_type = 'sqlite';
                $path = BP . ($sandboxEnabled ? 'sandbox' : 'debug') . '.db.sqlite';
                $db_conf['type'] = $driver_type;
                $db_conf['path'] = $path;
                return $db_conf;
            }
        }
        $db_conf = $this->config['db'] ?? [];
        if ($db_conf) {
            return $db_conf;
        }
        # 默认使用Sqlite
        $driver_type = 'sqlite';
        $path = APP_PATH . 'etc/db.sqlite';
        $db_conf['type'] = $driver_type;
        $db_conf['path'] = $path;
        return $db_conf;
    }

    /**
     * 强制开启沙盒模式（针对当前请求）
     */
    public function enableSandboxMode(?string $source = null): void
    {
        $this->sandboxOverride = true;
        if ($source) {
            $this->setData('sandbox_source', $source);
        }
    }

    /**
     * 关闭沙盒模式强制（针对当前请求）
     */
    public function disableSandboxMode(): void
    {
        $this->sandboxOverride = false;
        $this->unsetData('sandbox_source');
    }

    /**
     * 判断当前是否处于沙盒模式（包含业务强制）
     */
    public function isSandboxMode(): bool
    {
        return $this->sandboxOverride || (defined('SANDBOX') && SANDBOX);
    }

    /**
     * @DESC         |读取模块列表
     *
     * 参数区：
     *
     * @param bool $reget
     *
     * @return array
     */
    public function getModuleList(bool $reget = false): array
    {
        if (!$reget && $this->module_list) {
            return $this->module_list;
        }
        if (!is_file(Env::path_MODULES_FILE)) {
            return [];
        }
        
        // 检查文件是否可读
        if (!is_readable(Env::path_MODULES_FILE)) {
            $error = error_get_last();
            throw new \Weline\Framework\Exception\Core(
                __('无法读取模块配置文件：%{1}。错误：%{2}。请检查文件权限或是否被其他程序占用。', [
                    Env::path_MODULES_FILE,
                    $error['message'] ?? 'Permission denied (errno=13)'
                ])
            );
        }
        
        try {
            $this->module_list = (array)require Env::path_MODULES_FILE;
        } catch (\Throwable $e) {
            // 如果是权限错误，提供更友好的提示
            if (strpos($e->getMessage(), 'Permission denied') !== false || 
                strpos($e->getMessage(), 'errno=13') !== false) {
                throw new \Weline\Framework\Exception\Core(
                    __('模块配置文件读取权限被拒绝：%{1}。\n\n可能的原因：\n1. 文件被其他程序（编辑器、杀毒软件等）锁定\n2. Web服务器进程没有读取权限\n3. 文件系统权限设置不正确\n\n解决方案：\n1. 关闭可能锁定该文件的程序\n2. 检查文件权限，确保Web服务器进程有读取权限\n3. 以管理员身份运行Web服务器', [
                        Env::path_MODULES_FILE
                    ])
                );
            }
            throw $e;
        }

        // 重载模块列表时一并清理依赖模块的缓存，避免新模块/卸载模块在 Env 侧仍为旧状态
        $this->active_module_list = [];
        self::$module_configs = [];

        return $this->module_list;
    }

    public function getDependencies(bool $reget = false): array
    {
        if (!$reget && $this->dependencies) {
            return $this->dependencies;
        }
        $this->dependencies = (array)require self::path_MODULE_DEPENDENCIES_FILE;

        return $this->dependencies;
    }

    public function saveDependencies(array $dependencies): bool
    {
        return file_put_contents(self::path_MODULE_DEPENDENCIES_FILE, '<?php  return ' . w_var_export($dependencies, true) . ';');
    }

    public function getActiveModules(bool $reget = false): array
    {
        if (!$reget && $this->active_module_list) {
            return $this->active_module_list;
        }
        $modules = $this->getModuleList($reget);
        $active_modules = [];
        foreach ($modules as $module) {
            if ($module['status']) {
                $active_modules[$module['name']] = $module;
            }
        }
        $this->active_module_list = $active_modules;
        return $active_modules;
    }

    public function getModuleByName(string $name): array
    {
        $modules = $this->getModuleList();
        return $modules[$name] ?? [];
    }

    public function getModuleStatus(string $module)
    {
        $module = $this->getModuleByName($module);
        return $module['status'] ?? false;
    }

    /**
     * @DESC          # 获取模块信息
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/17 9:13
     * 参数区：
     *
     * @param string $module_name
     *
     * @return mixed
     */
    public function getModuleInfo(string $module_name): mixed
    {
        if ($modules = $this->getModuleList()) {
            if (isset($modules[$module_name])) {
                return $modules[$module_name];
            }
        }
        return null;
    }

    public static function getCommands(): array
    {
        $commands = [];
        if (file_exists(Env::path_COMMANDS_FILE)) {
            $commands = (array)require self::path_COMMANDS_FILE;
        }
        if (isset($commands[0]) && $commands[0] === 1) {
            return [];
        }
        return $commands;
    }

    /**
     * @throws Exception
     */
    public static function write(string $filename, string $content): bool
    {
        try {
            $file = new File();
            $file->open($filename, $file::mode_w);
            $file->write($content);
            $file->close();
            return true;
        } catch (Exception $exception) {
            if (DEV) {
                throw $exception;
            }
            return false;
        }
    }

    public static function open(string $filename, string $content): bool
    {
        try {
            $file = new File();
            $file->open($filename, $file::mode_w);
            $file->write($content);
            $file->close();
            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    // ==================== 区域路由配置方法 ====================
    
    /**
     * 获取所有区域路由配置
     * 
     * @return array 区域路由配置数组
     */
    public static function getAreaRoutes(): array
    {
        return self::get('area_routes', []);
    }
    
    /**
     * 获取指定区域的路由前缀
     * 
     * @param string $area 区域名称：backend, rest_frontend, rest_backend
     * @return string|null 路由前缀，不存在返回 null
     */
    public static function getAreaRoutePrefix(string $area): ?string
    {
        $areaRoutes = self::getAreaRoutes();
        return $areaRoutes[$area]['prefix'] ?? null;
    }
    
    /**
     * 根据 URL 前缀判断对应的区域类型
     * 
     * @param string $prefix URL 路径的第一段
     * @return string|null 匹配的区域名称，无匹配返回 null
     */
    public static function getAreaByRoutePrefix(string $prefix): ?string
    {
        $areaRoutes = self::getAreaRoutes();
        foreach ($areaRoutes as $area => $config) {
            if (($config['prefix'] ?? '') === $prefix) {
                return $area;
            }
        }
        // 固定别名：/admin/ 始终视为后台入口（便于记忆，与密钥入口并存）
        if ($prefix === 'admin' && isset($areaRoutes['backend'])) {
            return 'backend';
        }
        return null;
    }
    
    /**
     * 检查给定前缀是否为有效的区域路由前缀
     * 
     * @param string $prefix URL 路径的第一段
     * @return bool
     */
    public static function isAreaRoutePrefix(string $prefix): bool
    {
        return self::getAreaByRoutePrefix($prefix) !== null;
    }
    
    // ==================== 其他工具方法 ====================
    
    /**
     * @DESC         |获取卸载备份目录
     *
     * 参数区：
     *
     * @return string
     */
    public static function getUninstallBackupDir(): string
    {
        $backupDir = self::get('uninstall.backup_dir');
        
        // 如果配置了备份目录，使用配置的目录
        if (!empty($backupDir) && is_string($backupDir)) {
            return rtrim($backupDir, DS) . DS;
        }
        
        // 否则使用项目根目录上级的 storage 目录
        $parentDir = dirname(BP);
        $storageDir = $parentDir . DS . 'storage' . DS;
        
        return $storageDir;
    }
}
