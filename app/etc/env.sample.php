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
        'db' => [
            'enabled' => false,  // 是否启用数据库日志（默认禁用以提高性能）
            'file' => 'db',      // 数据库日志文件名（自动添加 var/log/ 前缀和 .log 后缀）
        ],
        'dev_sql' => [
            'enabled' => false,  // 是否启用开发SQL日志（所有SQL查询，文件会持续增长，影响性能，生产环境请禁用）
            'file' => 'dev_sql',  // 开发SQL日志文件名 → var/log/dev_sql.log
        ],
    ],
    
    // PHP代码规范检查
    'php-cs' => false,  // 是否启用PHP代码规范检查
    
    // 模板配置
    'template' => [
        'show_comments' => false,  // 是否在网页源码中显示模板位置注释（默认不显示，用于调试时定位模板文件）
    ],
    
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
            'persistent' => true,       // 是否启用PDO持久连接（默认true，除非主动销毁否则保持连接）
            'pool_size' => 10,          // 连接池大小（默认10个连接）
            // PDO 性能优化选项（可选，使用默认值即可）
            'emulate_prepares' => false,  // MySQL: 是否模拟预处理语句（默认false，使用原生预处理提高性能）
            'use_buffered_query' => true, // MySQL: 是否使用缓冲查询（默认true，提高查询性能）
            'compress' => false,         // MySQL: 是否启用压缩（默认false，网络较慢时可启用）
            'timeout' => 30,              // 连接超时（秒，默认30秒）
            // 自定义 PDO 选项（高级用法，覆盖默认选项）
            // 'options' => [
            //     PDO::ATTR_TIMEOUT => 30,
            //     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // ],
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
    
    // 开发工具面板配置（DeveloperWorkspace模块）
    'dev_tool' => [
        // 是否在生产模式下默认启用开发工具面板（false=不启用，true=启用）
        // 注意：即使设置为false，仍可通过URL参数和Cookie启用
        'enable_in_prod' => false,
        
        // URL参数名，用于通过URL启用开发工具面板
        // 例如：访问 http://your-domain.com/page?dev_tool=1 可启用面板
        'key' => 'dev_tool',
        
        // Cookie名称，用于持久化开发工具面板的启用状态
        // 启用后会在浏览器中设置此Cookie，有效期30天
        'cookie_name' => 'w_dev_tool',
        
        // 密钥（可选），用于验证URL参数，增强安全性
        // 如果设置了密钥，URL参数值必须与密钥匹配才能启用面板
        // 例如：设置 'secret' => 'my_secret_key'，则需访问 ?dev_tool=my_secret_key
        // 留空则不验证，任何值都可以启用（不推荐生产环境使用）
        'secret' => '',
    ],
    
    // 翻译配置
    'translation' => [
        // ⚠️ 性能警告：online 模式会严重影响性能！
        // - online 模式：每次请求都会检测CSV文件变更并重新收集翻译（开发模式使用）
        // - default 模式：直接读取生成的词典文件，性能最优（生产环境推荐）
        'mode' => 'default',  // 翻译模式：default（推荐）, online（开发模式，影响性能）
        
        // 在线翻译配置（预留配置，目前未使用）
        'online' => [
            'enabled' => true,   // 是否启用在线翻译服务（预留，暂未实现）
            'api_key' => '',     // API密钥（如果使用付费翻译服务，预留）
            'provider' => 'google',  // 翻译服务提供商：google, baidu 等（预留）
        ],
        
        // 翻译缓存配置（预留配置，目前未使用）
        'cache' => [
            'enabled' => false,  // 是否启用翻译缓存（预留，暂未实现）
            'ttl' => 3600,       // 缓存有效期（秒，预留）
        ],
        
        // 自动注册配置（已实现）
        // 用途：当页面中使用 __() 函数或 <lang> 标签时，自动将翻译词注册到数据库
        // 使用位置：I18n/Controller/Backend/Dictionary.php::isAutoRegisterEnabled()
        // 注意：需要在 online 模式下，通过 footer.phtml hook 收集 JS 翻译词
        'auto_register' => true,  // 是否自动注册新翻译词到数据库（true=启用，false=禁用）
    ],
    
    // 国际化配置（旧版配置，建议使用 translation 配置）
    'i18n' => [
        'translate_mode' => 'default',  // 翻译模式：default, online（已废弃，请使用 translation.mode）
    ],
];