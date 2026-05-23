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
 * - console: CLI 控制台编码（bin/w）
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
        // WLS 模式下默认由 Session Server 托管，保证多 Worker 间会话一致性。
        'wls_managed' => true,
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
            // WLS Session 连接与会话服务参数见顶层 wls.session
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
    ],
    
    // ==================== WLS（常驻服务：监听、Worker、编排、Session 服务、WLS 日志等）====================
    'wls' => [
        'host' => '0.0.0.0',
        'port' => 443,
        // HTTP→HTTPS 重定向固定规则：仅当 HTTPS 主端口=443 时，自动启动 HTTP:80 的独立重定向进程。
        // 若 HTTPS 使用非 443（如 9981），则不启动独立重定向进程。
        'https' => true,
        'performance' => [
            // 慢请求阈值（毫秒）
            'slow_request_threshold_ms' => 500,
            // 是否输出 X-WLS-Performance-* 头
            'response_headers_enabled' => true,
            // 是否写入 var/log/wls/timing.log
            'file_log_enabled' => true,
            // 是否写 debug 级性能摘要/明细日志
            'debug_log_enabled' => true,
            // 超慢请求分析日志
            'analysis_log_enabled' => true,
            // DEV 模式是否记录全部请求
            'log_all_in_dev' => true,
            // 捕获业务直接输出（echo/print/var_dump）时是否输出提示日志（默认关闭）
            'capture_output_verbose' => false,
            // 请求日志开关；null 表示沿用现有 DEV/frontend 判断
            'request_log_enabled' => null,
            // 错误日志开关；null 表示沿用现有 DEV 判断
            'error_log_enabled' => null,
            // Persistent WLS FPC stampede guard: only wait briefly for the worker holding the build lock to publish.
            'fpc_build_wait_timeout_ms' => 80,
            // Serve stale public FPC during rebuild lock contention instead of blocking readers.
            'fpc_stale_ttl_seconds' => 86400,
            'fpc_serve_stale_before_build' => true,
            'runtime_log_file' => 'var/log/wls/runtime.log',
            'timing_log_file' => 'var/log/wls/timing.log',
        ],
        // Dispatcher startup must not cold-render many frontend pages. Keep this
        // explicit for manual FPC prebuild flows; default dispatcher warmup is
        // cheap TLS/health only.
        'homepage_warmup_paths' => ['/'],
        'homepage_warmup_variants' => [
            ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
            ['lang' => 'en_US', 'currency' => 'USD'],
            ['lang' => 'hi_IN', 'currency' => 'INR'],
        ],
        // WLS worker READY 后错峰预载 router/hook/event/extends/query/i18n 等进程级注册表。
        // 显式设为 false/0/off 可关闭；默认开启，避免首个用户请求承担扫描成本。
        // 设为 sync 才会在 READY 前同步预热；默认不允许任何预热卡住启动。
        'worker_bootstrap_warmup' => true,
        'worker_bootstrap_sync_warmup' => false,
        // Worker READY 后错峰协程执行主题/分类/store 等 observer 预热；显式 false/0/off 可关闭。
        'worker_deferred_bootstrap_warmup' => true,
        'worker_bootstrap_observer_warmup' => false,
        'worker_deferred_bootstrap_roles' => ['maintenance'],
        // Worker #1 READY 前先构建少量首访关键 FPC，避免 reload 后第一位用户承担分类/产品冷渲染。
        'worker' => [
            'fpc_ready_gate_enabled' => true,
            'fpc_ready_gate_owner_worker_id' => 1,
            'fpc_ready_gate_hosts' => ['127.0.0.1'],
            'fpc_ready_gate_max_paths' => 8,
            'fpc_buildahead_roles' => ['maintenance'],
        ],
        'worker_count' => 'auto',
        // Worker/维护 Worker 子进程 PHP memory_limit。纯数字按 MB 处理；支持 512M、1G、-1。
        'worker_memory_limit' => '256M',
        // Dispatcher 子进程 PHP memory_limit；不配置时默认跟随 worker_memory_limit。
        'dispatcher_memory_limit' => '256M',
        'ssl' => [
            'engine' => 'stream', // stream|event_buffer; native Windows only supports stream
            'event_buffer_enabled' => false,
            'event_buffer_max_connections_per_worker' => 0,
            'event_buffer_read_high_watermark' => 1048576,
            'event_buffer_write_high_watermark' => 1048576,
            'handshake_max_advance_per_loop' => 16,
            'handshake_queue_high_watermark' => 512,
            'idle_select_timeout_usec' => 5000,
        ],
        // EventLoop 后端：auto 当前保持稳定 select；event 需显式开启并通过压测后再进入 auto。
        'loop' => [
            'driver' => 'auto', // auto|select|event
        ],
        'dispatcher' => [
            'fast_tls_path_enabled' => true,
            'max_accept_per_loop' => 16,
            'worker_connect_select_timeout_sec' => 0.02,
            'ssl_backend_preconnect_per_worker' => 0,
            'homepage_warmup_enabled' => false,
        ],
        'mode' => 'io',
        'max_connections' => 10000,
        // Worker Keep-Alive 空闲连接超时（秒），<=0 则使用内置默认 60。
        'keep_alive_timeout' => 60,
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
        'memory_guard' => [
            // WorkerResponseMemoryGuard 在内存占比达到 soft 阈值时，开始清理可重建的进程内热点缓存
            'runtime_cache_pressure_threshold' => 0.70,
            // 达到 hard 阈值时，会额外清理更激进的进程级缓存（如内存页缓存、进程探测缓存）
            'runtime_cache_hard_pressure_threshold' => 0.85,
        ],
        'pid' => null,
        'start_time' => null,
        'status' => 'stopped',
        // WLS 进程日志：主链 wls-*.log 按 level 输出（默认不再整体抬升到 WARNING）；verbose 用于开发态额外行为（见 LogConfig/WlsLogger）
        'log' => [
            'enabled' => true,
            'path' => 'var/log/wls/',
            'level' => 'DEBUG',              // 主链最低级别（生产环境另受 production_level 约束）
            'verbose' => false,              // true：与 server:start -log / 实例 enable_log 等对齐的开发态增强（非压低主链级别）
            'production_level' => 'INFO',    // 生产环境强制最低级别（deploy=prod 时生效）
            'stdout' => 'auto',              // 控制台输出：auto（前台=true，后台=false）, true, false
            'rotate' => 'daily',             // 日志轮转策略：daily（按日期分割）
            'max_files' => 7,                // 保留最近 N 天的日志文件（自动清理过期日志）
            'max_size' => 52428800,          // 单个日志文件最大大小（字节，默认 50MB）
        ],
        // Session Server 与 Worker 侧 Session 客户端（原 session.drivers.wls）
        'session' => [
            'port' => 19970,
            'session_ttl' => 86400,
            'max_sessions' => 50000,
            'persist_interval' => 30,
            'persist_on_writes' => 100,
            'gc_interval' => 300,
            'wls_server' => [
                'host' => '127.0.0.1',
                'port' => 19970,
            ],
        ],
        // Session/Memory 共享服务：实例停机只卸载本实例令牌；令牌为空后由共享服务自治退出。
        'shared_service' => [
            'empty_token_exit_grace_sec' => 30,
            'empty_token_check_interval_sec' => 120.0,
        ],
        // Fiber 调度器配置
        'fiber' => [
            'heartbeat_timeout' => 60,       // Fiber 心跳超时（秒），超时未续约则强制回收
                                             // 建议：短连接 30s，长连接 120s（配合客户端 30-60s 心跳）
            'idle_ttl' => 0,                 // 非长连接 Fiber 闲置超时（秒），0=禁用
            'max_active' => 0,               // 最大活跃 Fiber 数，0=无限制
        ],
        // Worker 自动扩缩容配置
        'scaling' => [
            'enabled' => false,              // 是否启用自动扩缩容
            'min_workers' => 2,              // 最小 Worker 数
            'max_workers' => 8,              // 最大 Worker 数
            'scale_up_threshold' => 0.8,     // 扩容阈值（CPU/内存/队列）
            'scale_down_threshold' => 0.3,   // 缩容阈值
            'cooldown_sec' => 60,            // 扩缩容冷却时间（秒）
        ],
        // 多实例：命名实例覆盖默认 wls.*（含 health_allow_remote 等）
        // 'servers' => [ 'api' => [ 'port' => 8443, 'health_allow_remote' => true ], ],
        'orchestrator' => [
            'ha_mode' => true,
            'single_restart_first' => true,      // IPC 断开时优先单实例重启（可恢复角色）
            // Session/Memory IPC 断开后 Master 本地拉起次数（每次断开独立计数），用尽后再整组重启；1–10
            'infra_service_resurrect_attempts' => 3,
            'escalation_window_sec' => 60.0,     // 升级窗口（秒），窗口内超阈值则整组重启
            'escalation_threshold' => 3,         // 窗口内断开次数阈值
            'stabilization_sec' => 15.0,         // 滚动重启后稳定期（秒），稳定期内新实例断开仅单实例重启
            'critical_roles' => ['dispatcher', 'session_server', 'redirect'],  // 核心角色，断开直接整组重启
            // --- Worker 存活与槽位补齐（与 ServiceOrchestrator 周期任务配合）---
            // worker_liveness_interval_sec：编排器按该间隔做「存活审计」（向各 Worker 发 ping/统计在线数）。
            //   0 = 关闭存活审计（不跑该定时逻辑；紧急拉起、无 HA 补槽等依赖审计结果的能力会受影响）。
            'worker_liveness_interval_sec' => 8,
            // worker_emergency_restart：审计发现「当前组内 Worker 存活数为 0」时，是否允许紧急整组拉起 Worker，
            //   避免 Dispatcher 等全挂后服务长期无可用 Worker。false 则仅记录告警，由人工/外部拉起。
            'worker_emergency_restart' => true,
            // worker_emergency_cooldown_sec：两次「零存活紧急整组拉起」之间的最短间隔（秒），防止异常循环疯狂 fork。
            'worker_emergency_cooldown_sec' => 20,
            // Windows startup port checks use a bind probe instead of netstat/Get-NetTCPConnection.
            'fast_bind_probe_port_check' => true,
            // master_self_audit_interval_sec：Master 周期性自检（控制面、各角色 READY+IPC+PID、缺槽/僵死则补齐或回收重启）。
            //   0 = 关闭。默认 20。
            'master_self_audit_interval_sec' => 20,
            // reconcile_workers_without_ha：当 ha_mode=false（非高可用多机）时，是否仍按 worker_liveness 间隔
            //   对照配置的 worker 槽位数补齐子进程。true = 单机下 Worker 意外全退也会自动补满；false = 仅 HA 模式下做槽位协调。
            'reconcile_workers_without_ha' => true,
            // worker_three_batch_min_count：Worker 槽位数 ≥ 此值时，滚动重启/代码重载均分为三批并行摘流量；
            //   每批内全部 READY 后再通知 Dispatcher 加回端口；低于此值则逐个槽位重启（每批 1 个）。
            'worker_three_batch_min_count' => 7,
            // worker_reload_batch_count：达到 worker_three_batch_min_count 后的 reload 批次数；默认 1 批，避免本地 reload 被串行批次拖到几十秒。
            'worker_reload_batch_count' => 1,
            // drain_timeout_sec：滚动重启/单实例 DRAIN 时 Master 等待 draining_complete 的上限（秒）；下发给 Worker 作强制收尾上限。
            'drain_timeout_sec' => 5,
            // reload_drain_timeout_sec：代码重载专用 DRAIN 上限。长连接会主动断开重连，不允许把滚动重载拖到分钟级。
            'reload_drain_timeout_sec' => 1,
            // maintenance_connection_drain_timeout_sec：启用维护时，Dispatcher 已切至维护 Worker 后，等待各业务 Worker 排空存量 TCP 再 ACK 的上限（秒）。
            'maintenance_connection_drain_timeout_sec' => 300,
            // maintenance_ready_timeout_sec：维护 Worker 子进程全部 READY 的等待上限（秒）。
            'maintenance_ready_timeout_sec' => 90,
            // zero_downtime_rolling_restart：零停机滚动重启开关。
            //   true = 先启动新 Worker（临时 ID）再停旧 Worker，全程无服务中断（内存占用翻倍）
            //   false = 传统模式：先停旧 Worker 再启新 Worker（有短暂服务中断）
            'zero_downtime_rolling_restart' => true,
            // stop_all_ipc_disconnect_wait_sec：stopAll 阶段 4 等待「子服务 IPC」断开的上限（秒），不含 control/CLI。
            'stop_all_ipc_disconnect_wait_sec' => 2.0,
            // stop_ipc_flush_before_close_sec：关闭 IPC 监听前冲刷出站写缓冲的上限（秒）。
            'stop_ipc_flush_before_close_sec' => 0.2,
            // force_stop_ipc_flush_sec / force_stop_ipc_post_kill_wait_sec：二次 Ctrl+C 等强制停机路径上，杀进程前/后的短 IPC 窗口（秒）。
            'force_stop_ipc_flush_sec' => 0.5,
            'force_stop_ipc_post_kill_wait_sec' => 0.35,
            // control_poll_slice_usec：启动验收等控制面等待时 stream_select 超时（微秒），替代叠 sleep。
            'control_poll_slice_usec' => 100000,
            // skip_bulk_launch_port_reprobe：Start CLI 已完成端口预检后，Master 批量拉起阶段不再逐端口重复探测。
            'skip_bulk_launch_port_reprobe' => true,
        ],
        // WLS 常驻 Worker 下开发面板默认不注入（避免大段 HTML）；本地调试 ?dev_tool=1 时仍须此项为 true
        'debug' => [
            // 闈炲父楂樺紑閿€锛氫細璁板綍 Session / Router / URL 瑙ｆ瀽绛夌儹璺粏绮掑害鏃ュ織锛屽彧搴旂煭鏃舵墜鍔ㄦ墦寮€
            'hot_path_logs' => false,
            'dev_tool_panel' => false,
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

    // ==================== 安全策略配置 ====================
    'security' => [
        'csrf' => [
            // off: 维持当前行为；session: 统一启用 session token；inherit: 预留给后续渐进策略
            'pc_controller_mode' => 'off',
            'rest_mode' => 'off',
        ],
        'request_filter' => [
            // 默认关闭 PHP 原生 unserialize 请求过滤，避免把高危原语暴露给输入面
            'allow_php_unserialize' => false,
        ],
        'headers' => [
            // 留空表示不输出；后续可按环境接入 Report-Only / 正式 CSP
            'csp_report_only' => '',
            'csp' => '',
        ],
        'view' => [
            // 兼容当前主题行为；后续可逐步收紧为 none / prefix 等策略
            'assign_params_mode' => 'all',
            // prefix 模式下仅注入这些前缀开头的请求参数；支持数组或逗号分隔字符串
            'assign_params_prefixes' => [],
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
    
    // ==================== CLI 控制台（php bin/w …）====================
    'console' => [
        // true：Windows 下将控制台设为 UTF-8（65001），并尝试启用 VT100；Linux/Mac 下设置 UTF-8 locale。避免中文、表格线乱码。
        // false：不修改控制台编码（若出现乱码可尝试手动 chcp 65001 或关闭此项后自行处理）。
        'utf8_output' => true,
    ],

    // PHP 内置 Web 服务器（php bin/w server:start --cli），与 WLS（wls）无关
    'cli_server' => [
        'host' => '127.0.0.1',
        'port' => 9981,
        'pid' => null,
        'start_time' => null,
        'status' => 'stopped',
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
        // WLS 模式另见顶层 wls.debug.dev_tool_panel
    ],
];
