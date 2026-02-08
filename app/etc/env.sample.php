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
        
        // WLS 模式 Session 托管配置
        // 在 WLS 常驻内存模式下，是否使用内存 Session 驱动（WlsMemorySession）
        // - true（默认）：使用 WlsMemorySession，内存 + 文件双写，性能最佳
        //   优点：读写走进程内存，响应速度快；同时写文件保证 Worker 重启后可恢复
        //   缺点：跨 Worker 进程的 Session 需要通过文件同步，首次读取会读文件
        // - false：使用原生 PHP Session 机制（session_start）
        //   优点：兼容性好，Session 数据立即持久化
        //   缺点：每个请求都要读写文件，性能较低
        // 开发阶段建议设为 false，避免频繁重启 WLS 导致 Session 丢失
        'wls_managed' => true,
        
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
    
    // 备份数据库配置（可选）
    // 用途：字段/表结构备份、卸载备份等数据写入此库，避免污染主业务库。
    // 不配置时，所有备份数据将写入主库（db）。
    'backup_db' => [
        // 和主 db 配置结构一致：default/master/slaves
        // 默认示例：单独的 MySQL 备份库
        // 'default' => 'mysql',
        // 'master' => [
        //     'type' => 'mysql',
        //     'hostname' => 'localhost',
        //     'database' => 'weline_backup',   // 备份库库名
        //     'username' => 'weline_backup',   // 备份库用户名
        //     'password' => 'weline_backup',   // 备份库密码
        //     'hostport' => '3306',
        //     'prefix' => 'bak_',              // 建议使用独立前缀
        //     'charset' => 'utf8mb4',
        //     'collate' => 'utf8mb4_general_ci',
        //     'persistent' => true,
        //     'pool_size' => 5,
        // ],
        // 'slaves' => [],
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
    
    // 区域路由前缀配置
    // 用于定义各区域的 URL 前缀
    // - backend: 后台管理区域，使用随机密钥作为前缀增强安全性
    // - rest_frontend: 前端 REST API，公开接口，使用简短易记的前缀
    // - rest_backend: 后台 REST API，管理接口，使用随机密钥
    // - frontend: 前端页面区域，无需配置（默认无前缀）
    // 访问路径示例：
    //   后台管理：https://your-domain.com/{backend.prefix}/dashboard
    //   前端API：https://your-domain.com/{rest_frontend.prefix}/products
    //   后台API：https://your-domain.com/{rest_backend.prefix}/users
    'area_routes' => [
        'backend' => [
            'prefix' => 'Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ',  // 生产环境请使用强密钥
            'description' => '后台管理',
        ],
        'rest_frontend' => [
            'prefix' => 'api',  // 公开 API，使用简短前缀
            'description' => '前端 REST API',
        ],
        'rest_backend' => [
            'prefix' => '7r1XLapP8oNBJc6grWtUlUqA42e6GZWQ',  // 生产环境请使用强密钥
            'description' => '后台 REST API',
        ],
    ],
    
    // 用户标识 运行命令会检测用户，防止框架命令被非运行用户执行，导致文件权限问题
    'user' => 'your_username',
    
    // 部署环境
    'deploy' => 'dev',  // dev, test, prod
    
    // 服务器配置（Weline Server 高性能 HTTP 服务器）
    'server' => [
        // 基础配置
        'host' => '127.0.0.1',      // 监听地址（127.0.0.1=本地, 0.0.0.0=所有网卡）
        'port' => 443,              // 监听端口（默认 80/443 省 Nginx；HTTPS 用 443；改 9981 等可自定义）
        'https' => true,            // 是否 HTTPS（WLS server:start 默认启用 HTTPS；http:request 据此构建请求 URL）
        
        // HTTP 重定向配置（HTTPS 模式专用）
        // HTTPS 模式下会自动启动一个 HTTP Redirect Worker，将 HTTP 请求 301 重定向到 HTTPS
        // 该 Worker 不加载框架代码，资源占用极低
        // 'http_redirect_port' => 80,  // HTTP 重定向监听端口（可选）
                                        // - 不配置时：自动计算（HTTPS端口 - 463，如 443→80, 9443→9980）
                                        // - 配置 0：禁用 HTTP 重定向
                                        // - 配置其他端口：使用指定端口
        
        // Worker 进程配置
        'worker_count' => 'auto',   // Worker 进程数：
                                    // - 'auto': 智能模式，根据服务器性能自动推算
                                    //   公式：CPU核心数 * 2（I/O密集型）或 CPU核心数（CPU密集型）
                                    // - 数字（如 4, 8）: 固定进程数
        
        // 性能调优（可选）
        'mode' => 'io',             // 工作模式：
                                    // - 'io': I/O密集型（默认，Worker = CPU * 2）
                                    // - 'cpu': CPU密集型（Worker = CPU）
        'max_connections' => 10000, // 单进程最大连接数（默认 10000）
        'max_request' => 100000,    // 单进程最大请求数后重启（0=不重启）
        
        // 热重载配置（开发模式专用）
        'hot_reload' => false,      // 是否启用热重载（仅在非守护进程模式下生效）
        'watch_dirs' => [           // 监控的目录（相对于项目根目录）
            'app/code',
            'app/etc',
        ],
        'watch_interval' => 1,      // 文件变更检查间隔（秒）
        
        // 维护模式检查间隔（WLS 性能优化）
        // 在 WLS 模式下，维护模式状态会缓存在进程内存中
        // 此参数控制多久重新检查一次文件标志
        // - 值越小：响应越快（设置维护模式后几乎立即生效），但文件检查频率越高
        // - 值越大：响应越慢，但性能越好
        // - 默认 1 秒：平衡响应速度和性能
        'maintenance_check_interval' => 1.0,
        
        // CLI 命令执行后触发 WLS 重载的前缀（白名单）
        // 仅此处列出的命令前缀会触发重载；code=代码重载（Worker 重启），cache=仅清缓存
        // 'reload_prefixes' => [
        //     'code' => ['setup:', 'command:'],
        //     'cache' => ['cache:'],
        // ],
        
        // ========== WLS 内存缓存配置 ==========
        // Worker 进程内的内存缓存，用于加速静态文件响应
        // 采用冷热淘汰策略：根据访问频率和最近访问时间智能淘汰
        'cache' => [
            // 静态文件缓存总上限
            // - 'auto'：智能模式，根据系统内存自动计算（系统内存的 2%，最小 32MB，最大 256MB）
            // - '100M'/'100MB'：指定大小（支持 K/KB/M/MB/G/GB 单位）
            // - 数字：直接指定字节数
            'static_file_max_total' => 'auto',
            
            // 单个静态文件最大缓存大小（超过此大小的文件不缓存，直接从磁盘读取）
            // 默认 1MB，避免大文件占用过多缓存空间
            'static_file_max_size' => '1M',
            
            // 淘汰阈值：当缓存剩余空间低于此值时，开始冷热淘汰
            // 默认 5MB，确保有足够空间容纳新缓存项
            'eviction_threshold' => 5242880,  // 5MB
            
            // 启动时内存检查
            // 如果系统可用内存低于缓存配置 + 50MB 预留，将自动缩减缓存大小
            // 如果严重不足（低于需求的 50%），Worker 将拒绝启动
        ],
        
        // 运行时状态（自动管理，无需手动配置）
        'pid' => null,              // 主进程 PID
        'start_time' => null,       // 启动时间戳
        'status' => 'stopped',      // 状态：stopped, running
    ],
    
    // 多实例服务器配置（可选）
    // 用于同时运行多个服务器实例（如 API 服务器、WebSocket 服务器）
    'servers' => [
        // 'api' => [
        //     'host' => '127.0.0.1',
        //     'port' => 9001,
        //     'worker_count' => 4,
        //     'mode' => 'io',
        // ],
        // 'websocket' => [
        //     'host' => '0.0.0.0',
        //     'port' => 9002,
        //     'worker_count' => 2,
        //     'mode' => 'cpu',
        // ],
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
    
    // 系统配置
    'system' => [
        // 进程管理器配置（Processer）
        'processer' => [
            // 是否启用进程管理日志（默认 false，关闭日志）
            // 包含：进程生命周期日志（lifecycle.log）、进程输出日志（*.log）
            // 开发调试时可设为 true，生产环境建议保持 false 以减少磁盘 I/O
            'log' => false,
        ],
    ],
];