<?php
declare(strict_types=1);

return [
    'router' => 'server',
    'backend_router' => 'server',
    'wls' => [
        // 监听地址：默认沿用 host 推断；线上对外公开必须设为 '0.0.0.0'（IPv4）或 '::'（IPv4+IPv6）。
        // 仅 dispatcher 单独覆盖时使用 'dispatcher' => ['bind_host' => '0.0.0.0']。
        'bind_host' => '0.0.0.0',
        'http' => [
            // The PHP Worker currently exposes HTTP/1.1 directly. HTTP/2/3
            // stay fail-closed until the WLS-owned Transport Adapter is ready.
            'protocols' => ['h1'],
            'preferred' => 'h1',
            'protocol_edge' => 'disabled',
            'protocol_edge_binary' => '',
            'tls_session_resumption' => true,
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
        // 边缘协议终结：未在 app/etc/env.php 覆盖时即为 nginx（也可整段省略，Resolver 同样默认 nginx）。
        'edge' => [
            'adapter' => 'nginx', // nginx|wls
            'reload_command' => '', // 宿主机 Nginx：systemctl reload nginx / nginx -s reload
            'reload_timeout_sec' => 30,
            'nginx' => [
                // auto：检测宿主机 Nginx（宝塔/系统 PATH）；有则 managed=false，无则托管本项目实例
                // true/false：强制托管或强制宿主机模式
                'managed' => 'auto',
                'auto_start' => true,
                'listen_http' => null,
                'listen_https' => null,
                'server_names' => [],
                'install_root' => 'extend/server/nginx',
                'runtime_root' => 'var/server/nginx',
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
