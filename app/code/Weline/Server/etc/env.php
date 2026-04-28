<?php
declare(strict_types=1);

return [
    'router' => 'server',
    'backend_router' => 'server',
    'wls' => [
        'orchestrator' => [
            // 后台启动时等待“所有服务就绪”的常规时长（秒）。
            // 推荐默认值：12（可显著减少 4 秒误报，同时保持启动反馈及时）。
            'background_ready_wait_sec' => 12,
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
