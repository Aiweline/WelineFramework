<?php
/**
 * WelineFramework 环境配置示例文件
 * 
 * 此文件包含所有可用的配置选项和示例值
 * 复制此文件为 env.php 并根据您的环境进行配置
 * 
 * 注意：
 * - 请根据实际环境修改配置值
 * - 生产环境请使用安全的密码和密钥
 * - 示例配置项可以删除，不影响系统运行
 */

return [
    // 环境类型：local, dev, test, prod
    'env' => 'local',
    
    // 数据库日志配置
    'db_log' => [
        'enabled' => false,  // 是否启用数据库日志
        'file' => 'var/log/db.log',  // 日志文件路径
    ],
    
    // 缓存配置
    'cache' => [
        'default' => 'file',  // 默认缓存驱动：file, redis
        'drivers' => [
            'file' => [
                'path' => 'var/cache/',  // 文件缓存路径
            ],
            'redis' => [
                'tip' => 'Redis缓存配置示例',
                'server' => '127.0.0.1',  // Redis服务器地址
                'port' => 6379,           // Redis端口
                'database' => 1,          // Redis数据库编号
            ],
        ],
        // 缓存状态配置（1=启用，0=禁用）
        'status' => [
            'config' => 1,                    // 配置缓存
            'framework_controller' => 1,      // 控制器缓存
            'database' => 1,                  // 数据库缓存
            'database_model' => 1,            // 模型缓存
            'framework_event' => 1,           // 事件缓存
            'framework_object' => 1,          // 对象缓存
            'framework_phrase' => 1,          // 语言包缓存
            'framework_plugin' => 1,          // 插件缓存
            'router_cache' => 1,              // 路由缓存
            'framework_view' => 1,            // 视图缓存
            'frontend_cache' => 1,            // 前端缓存
        ],
    ],
    
    // 会话配置
    'session' => [
        'default' => 'file',  // 默认会话驱动：file, mysql, redis
        'drivers' => [
            'file' => [
                'path' => 'var/session/',  // 会话文件存储路径
                'class' => 'Weline\\Framework\\Session\\Driver\\File',
            ],
            'mysql' => [
                'tip' => 'MySQL会话存储配置示例',
                'class' => 'Weline\\SessionManager\\Session\\Driver\\Mysql',
            ],
            'redis' => [
                'tip' => 'Redis会话存储配置示例',
            ],
        ],
    ],
    
    // 日志配置
    'log' => [
        'error' => 'var/log/error.log',        // 错误日志
        'exception' => 'var/log/exception.log', // 异常日志
        'notice' => 'var/log/notice.log',       // 通知日志
        'warning' => 'var/log/warning.log',     // 警告日志
        'debug' => 'var/log/debug.log',         // 调试日志
    ],
    
    // PHP代码规范检查
    'php-cs' => false,  // 是否启用PHP代码规范检查
    
    // 语言设置
    'lang' => 'zh_Hans_CN',  // 默认语言：zh_Hans_CN, en_US, zh_Hant_TW
    
    // 货币设置
    'currency' => 'CNY',  // 默认货币：CNY, USD, EUR
    
    // 主数据库配置
    'db' => [
        'default' => 'mysql',  // 默认数据库类型：mysql, sqlite
        'master' => [
            'type' => 'mysql',
            'hostname' => 'localhost',  // 数据库主机
            'database' => 'weline',     // 数据库名
            'username' => 'weline',     // 数据库用户名
            'password' => 'weline',     // 数据库密码
            'hostport' => '3306',       // 数据库端口
            'prefix' => 'm_',           // 表前缀
            'charset' => 'utf8mb4',     // 字符集
            'collate' => 'utf8mb4_general_ci',  // 排序规则
        ],
        // 从数据库配置（读写分离）
        'slaves' => [
            // 示例从数据库配置
            // [
            //     'type' => 'mysql',
            //     'hostname' => 'slave1.example.com',
            //     'database' => 'weline',
            //     'username' => 'weline_read',
            //     'password' => 'weline_read',
            //     'hostport' => '3306',
            //     'prefix' => 'm_',
            //     'charset' => 'utf8mb4',
            //     'collate' => 'utf8mb4_general_ci',
            // ],
        ],
        
        // SQLite数据库配置示例（注释掉，仅供参考）
        // 'sqlite' => [
        //     'default' => 'sqlite',
        //     'master' => [
        //         'type' => 'sqlite',
        //         'path' => 'app/etc/db.sqlite',  // SQLite数据库文件路径
        //     ],
        //     'slaves' => [],
        // ],
        
        // MySQL数据库配置示例（注释掉，仅供参考）
        // 'mysql' => [
        //     'default' => 'mysql',
        //     'master' => [
        //         'type' => 'mysql',
        //         'hostname' => 'demo.example.com',
        //         'database' => 'demo_database',
        //         'username' => 'demo_user',
        //         'password' => 'demo_password',
        //         'hostport' => '3306',
        //         'prefix' => 'demo_',
        //         'charset' => 'utf8mb4',
        //         'collate' => 'utf8mb4_general_ci',
        //     ],
        //     'slaves' => [],
        // ],
    ],
    
    
    // 沙盒数据库配置（用于测试环境）
    'sandbox_db' => [
        'tip' => '沙盒数据库配置，用于测试环境',
        'default' => 'sqlite',
        'master' => [
            'type' => 'sqlite',
            'path' => 'app/etc/sandbox_db.sqlite',  // 沙盒数据库文件路径
        ],
        'slaves' => [],
    ],
    
    // 管理后台密钥（生产环境请使用强密钥）
    'admin' => 'Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ',
    
    // API管理密钥（生产环境请使用强密钥）
    'api_admin' => '7r1XLapP8oNBJc6grWtUlUqA42e6GZWQ',
    
    // 用户标识 运行命令会检测用户，防止框架命令被非运行用户执行，导致文件权限问题
    'user' => 'your_username',
    
    // 部署环境
    'deploy' => 'dev',  // dev, test, prod
    
    // 服务器配置（开发服务器）
    'server' => [
        'host' => '127.0.0.1',  // 服务器地址
        'port' => 9981,         // 服务器端口
        'pid' => null,          // 进程ID（运行时自动设置）
        'start_time' => null,   // 启动时间（运行时自动设置）
        'status' => 'stopped',  // 服务器状态：stopped, running
    ],
    
    // 调试配置（可选）
    'debug' => false,           // 是否启用调试模式
    'debug_key' => null,        // 调试密钥（用于URL调试）
];