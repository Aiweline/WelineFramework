<?php
declare(strict_types=1);

return [
    'router' => 'server',
    'backend_router' => 'server',
    'wls' => [
        // WLS 只作为 Nginx 私网回源，禁止直接暴露公网监听。
        'bind_host' => '127.0.0.1',
        'http' => [
            // Public TLS/HTTP negotiation belongs exclusively to Nginx.
            // WLS is an authenticated private HTTP/1.1 backend.
            'protocols' => ['h1'],
            'preferred' => 'h1',
            'protocol_edge' => 'disabled',
            'protocol_edge_binary' => '',
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
        // Nginx 是唯一公网边缘。普通 start 绝不下载或编译；缺少项目隔离
        // 二进制时会失败并提示显式执行 server:nginx:install。
        'edge' => [
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
    ],
];
