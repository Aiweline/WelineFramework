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
    ],
];
