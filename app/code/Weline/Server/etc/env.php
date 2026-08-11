<?php
declare(strict_types=1);

return [
    'router' => 'server',
    'backend_router' => 'server',
    'wls' => [
        // 默认作为 Nginx 私网回源；显式 server:start --no-nginx 时，
        // 由本次启动生成纯 WLS 公网监听与 TLS/HTTP 策略，不修改此默认配置。
        'bind_host' => '127.0.0.1',
        'http' => [
            // Nginx 模式固定为私网 HTTP/1.1 回源。
            // --no-nginx 会在运行时覆盖为 TLS 1.3 + HTTP/2/H1；
            // HTTP/3 只由 Nginx 提供。
            'protocols' => ['h1'],
            'preferred' => 'h1',
            'tls_session_resumption' => false,
            'alt_svc' => false,
        ],
        'orchestrator' => [
            // 后台启动时等待“所有服务就绪”的常规时长（秒）。
            // 推荐默认值：15（进一步减少慢机/冷启动时的误报）。
            'background_ready_wait_sec' => 15,
            // 后台启动等待上限（秒），超过仍未就绪会返回警告并提示后续排查。
            // 推荐默认值：60（避免异常时无限等待）。
            'background_ready_max_wait_sec' => 60,
        ],
        'shared_state' => [
            'idle_shutdown_grace_sec' => 30,
            'ephemeral_consumer_ttl_sec' => 120,
        ],
        'cache_namespace' => [
            // Release A compatibility gate. Release B requires every routing
            // Worker to advertise cache_namespace_invalidation_v1 before READY.
            'require_capability' => false,
            'ack_timeout_sec' => 5.0,
        ],
        // WLS 2.0 默认尝试加入/建立宿主级共享网关；无法安全建立时
        // 自动降级到稳定高端口纯 WLS。adapter 仍只描述 WLS Worker
        // 数据面（nginx=loopback H1，wls=原生 TLS），不把网关伪装成第三种 Worker。
        'edge' => [
            'mode' => 'auto',
            'adapter' => 'nginx',
            'nginx' => [
                // 只管理本项目隔离的 binary/runtime，绝不接管宿主机 Nginx。
                'managed' => true,
                'auto_start' => true,
                'listen_http' => null,
                'listen_https' => null,
                'server_names' => [],
                'install_root' => null,
                'runtime_root' => null,
                // 最佳性能默认：匿名边缘微缓存 + gzip + 大回源连接池
                'edge_cache' => true,
                'edge_cache_ttl_sec' => 60,
                'edge_cache_max_size_mb' => 1024,
                'edge_cache_keys_zone_mb' => 128,
                'gzip' => true,
                'gzip_comp_level' => 2,
                'upstream_keepalive' => 256,
                'worker_connections' => 32768,
            ],
        ],
        'gateway' => [
            'protocol' => 'wls-edge/2',
            'heartbeat_seconds' => 10,
            'stale_after_seconds' => 45,
            'drain_seconds' => 300,
            'stale_retention_seconds' => 86400,
            'snapshot_retention_seconds' => 604800,
            // 额外目录必须显式 enrollment；默认只授权 app/etc/ssl。
            'certificate_roots' => [],
        ],
    ],
];
