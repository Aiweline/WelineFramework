<?php
/**
 * WelineFramework 环境配置示例文件
 * 
 * 此文件包含所有可用的配置选项和示例值
 * 复制此文件为 env.php 并根据您的环境进行配置
 * 
 * 配置结构说明：
 * - system: 系统核心配置（环境类型、部署模式、维护模式、语言、货币）
 * - db: 主数据库配置
 * - sandbox_db: 沙盒数据库配置
 * - cache: 缓存配置
 * - session: 会话配置
 * - log: 日志配置
 * - server: WLS 服务器配置
 * - router: 路由配置
 * - theme: 主题配置
 * - dev: 开发工具配置
 */

return [
    // ==================== 系统核心配置 ====================
    'system' => [
        'env' => 'local',           // 环境类型：local, dev, test, prod
        'deploy' => 'dev',          // 部署模式：dev, test, prod
        'maintenance' => false,     // 维护模式（true=显示维护页面）
        'lang' => 'zh_Hans_CN',     // 默认语言：zh_Hans_CN, en_US, zh_Hant_TW
        'currency' => 'CNY',        // 默认货币：CNY, USD, EUR
    ],
    
    // ==================== 主数据库配置 ====================
    'db' => [
        'default' => 'mysql',  // 默认数据库类型：mysql, sqlite, pgsql
        'master' => [
            'type' => 'mysql',
            'hostname' => 'localhost',
            'database' => 'weline',
            'username' => 'weline',
            'password' => 'weline',
            'hostport' => '3306',
            'prefix' => 'm_',
            'charset' => 'utf8mb4',
            'collate' => 'utf8mb4_general_ci',
            'persistent' => true,
            'pool_size' => 10,
            'timeout' => 30,
        ],
        'slaves' => [],
    ],
    
    // ==================== 沙盒数据库配置 ====================
    'sandbox_db' => [
        'default' => 'sqlite',
        'master' => [
            'type' => 'sqlite',
            'path' => 'app/etc/sandbox_db.sqlite',
        ],
        'slaves' => [],
    ],
    
    // ==================== 缓存配置 ====================
    'cache' => [
        'default' => 'file',
        'wls_default' => 'wls_memory',
        'drivers' => [
            'file' => [
                'path' => 'var/cache/',
            ],
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => '',
                'database' => 0,
                'timeout' => 2.0,
                'prefix' => 'weline:',
            ],
            'memcached' => [
                'servers' => [
                    ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100],
                ],
                'prefix' => 'weline_',
            ],
            'apcu' => [
                'prefix' => 'weline_',
            ],
            'wls_memory' => [
                'path' => 'var/cache/',
                'max_items' => 10000,
                'max_memory' => 67108864,
                'evict_ratio' => 0.1,
            ],
        ],
        'pools' => [
            'router' => ['ttl' => 86400, 'permanent' => true],
            'config' => ['ttl' => 0, 'permanent' => true],
            'database' => ['ttl' => 1800],
            'view' => ['ttl' => 3600],
            'phrase' => ['ttl' => 86400, 'permanent' => true],
            'plugin' => ['ttl' => 86400, 'permanent' => true],
            'event' => ['ttl' => 0, 'permanent' => true],
            'hook' => ['ttl' => 86400],
            'controller' => ['ttl' => 86400, 'permanent' => true],
            'session' => ['ttl' => 7200],
            'request' => ['ttl' => 300],
            'object' => ['ttl' => 86400, 'permanent' => true],
            'acl' => ['ttl' => 3600],
            'currency' => ['ttl' => 3600],
            'i18n' => ['ttl' => 86400, 'permanent' => true],
            'theme' => ['ttl' => 3600],
            'url_rewrite' => ['ttl' => 86400],
            'website' => ['ttl' => 3600],
            'module_router' => ['ttl' => 86400, 'permanent' => true],
            'taglib' => ['ttl' => 86400, 'permanent' => true],
            'eav' => ['ttl' => 1800],
            'queue' => ['ttl' => 300],
            'system_config' => ['ttl' => 3600],
            'product' => ['ttl' => 1800],
            'file_manager' => ['ttl' => 86400, 'permanent' => true],
            'editor' => ['ttl' => 86400, 'permanent' => true],
            'api_doc' => ['ttl' => 3600],
            'fpc' => ['ttl' => 3600, 'taggable' => true],
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
        ],
    ],
    
    // ==================== 会话配置 ====================
    'session' => [
        'default' => 'file',
        'wls_default' => 'wls',
        // Session 服务端 TTL（秒）。建议与 cookie_lifetime 对齐，避免浏览器 Cookie 仍在但服务端 Session 已过期。
        'lifetime' => 86400,
        // Session Cookie 生命周期（秒）。
        'cookie_lifetime' => 604800,
        'drivers' => [
            'file' => [
                'path' => 'var/session/',
            ],
            'mysql' => [
                'class' => 'Weline\\SessionManager\\Session\\Driver\\Mysql',
            ],
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'prefix' => 'sess:',
                'password' => '',
                'timeout' => 2.0,
            ],
            'wls' => [
                'port' => 19970,
                // WLS Session Server 内部 TTL（秒），建议不小于 session.lifetime。
                'session_ttl' => 86400,
                'max_sessions' => 50000,
                'persist_interval' => 30,
                'persist_on_writes' => 100,
                'gc_interval' => 300,
            ],
        ],
    ],
    
    // ==================== 日志配置 ====================
    'log' => [
        'min_level' => 'INFO',
        'path' => 'var/log',
        'rotate' => [
            'strategy' => 'daily',
            'max_files' => 7,
            'max_size' => 52428800,
        ],
        'channels' => [
            'error' => ['file' => 'error.log', 'min_level' => 'ERROR'],
            'exception' => ['file' => 'exception.log', 'min_level' => 'ERROR'],
            'sql' => ['enabled' => false, 'file' => 'sql.log', 'min_level' => 'DEBUG'],
            'dev_sql' => ['enabled' => false, 'file' => 'dev_sql.log', 'min_level' => 'DEBUG'],
        ],
        'include_process_id' => false,
        'include_memory' => false,
        'include_trace' => true,
        'error' => 'var/log/error.log',
        'exception' => 'var/log/exception.log',
        'notice' => 'var/log/notice.log',
        'warning' => 'var/log/warning.log',
        'debug' => 'var/log/debug.log',
        'db' => ['enabled' => false, 'file' => 'db'],
        'dev_sql' => ['enabled' => false, 'file' => 'dev_sql'],
        'wls' => [
            'enabled' => true,
            'path' => 'var/log/wls/',
            'level' => 'INFO',
            'stdout' => 'auto',
            'rotate' => 'daily',
            'max_files' => 7,
        ],
    ],
    
    // ==================== 服务器配置 (WLS) ====================
    'server' => [
        'host' => '0.0.0.0',
        'port' => 443,
        'https' => true,
        'worker_count' => 'auto',
        'mode' => 'io',
        'max_connections' => 10000,
        'max_request' => 100000,
        'hot_reload' => false,
        'watch_dirs' => ['app/code', 'app/etc'],
        'watch_interval' => 1,
        'maintenance_check_interval' => 1.0,
        'attack_detector' => [
            'ip_whitelist' => [
                'enabled' => true,
                'ips' => ['127.0.0.1', '::1'],
            ],
        ],
        'cache' => [
            'static_file_max_total' => 'auto',
            'static_file_max_size' => '1M',
            'eviction_threshold' => 5242880,
        ],
        'memory_cache' => [
            'max_size' => '64M',
            'max_entries' => 10000,
            'memory_pressure_threshold' => 0.8,
            'memory_check_interval' => 30,
        ],
        'pid' => null,
        'start_time' => null,
        'status' => 'stopped',
        // WLS 编排器策略（server.orchestrator.*）
        'orchestrator' => [
            'single_restart_first' => true,      // IPC 断开时优先单实例重启（可恢复角色）
            'escalation_window_sec' => 60.0,     // 升级窗口（秒），窗口内超阈值则整组重启
            'escalation_threshold' => 3,         // 窗口内断开次数阈值
            'stabilization_sec' => 15.0,         // 滚动重启后稳定期（秒），稳定期内新实例断开仅单实例重启
            'critical_roles' => ['dispatcher', 'session_server', 'redirect'],  // 核心角色，断开直接整组重启
        ],
    ],
    
    // ==================== 路由配置 ====================
    'router' => [
        'area_routes' => [
            'backend' => [
                'prefix' => 'Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ',
                'description' => '后台管理',
            ],
            'rest_frontend' => [
                'prefix' => 'api',
                'description' => '前端 REST API',
            ],
            'rest_backend' => [
                'prefix' => '7r1XLapP8oNBJc6grWtUlUqA42e6GZWQ',
                'description' => '后台 REST API',
            ],
        ],
    ],
    
    // ==================== 主题配置 ====================
    'theme' => [
        'id' => 1,
        'module_name' => 'Weline_Default',
        'name' => 'default',
        'path' => 'Weline\\default',
        'parent_id' => 0,
        'is_active' => 1,
        'create_time' => null,
        'update_time' => null,
        'config' => null,
        'static_version' => '1.0.0',
    ],
    
    // ==================== 开发配置 ====================
    'dev' => [
        'php_cs' => false,              // PHP 代码规范检查
        'static_rand_version' => false, // 静态资源随机版本号
        'event_debug' => false,         // 事件调试
        'event_scan' => false,          // 事件扫描
        'phpunit_server' => [
            'host' => '127.0.0.1',
            'port' => 9980,
            'pid' => null,
            'start_time' => null,
            'status' => 'stopped',
        ],
    ],
    
    // ==================== 其他配置 ====================
    'user' => 'your_username',
    
    'template' => [
        'show_comments' => false,
    ],
    
    'translation' => [
        'mode' => 'default',
        'auto_register' => true,
    ],
    
    'debug' => false,
    'debug_key' => null,
    
    'dev_tool' => [
        'enable_in_prod' => false,
        'key' => 'dev_tool',
        'cookie_name' => 'w_dev_tool',
        'secret' => '',
    ],
];
