<?php
declare(strict_types=1);

return [
    'router' => 'server',
    'backend_router' => 'server',
    'wls' => [
        'shared_state' => [
            'idle_shutdown_grace_sec' => 30,
            'ephemeral_consumer_ttl_sec' => 120,
        ],
    ],
];
