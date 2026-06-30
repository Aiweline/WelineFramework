<?php

declare(strict_types=1);

return [
    'router' => '__bench',
    'session' => [
        'eager_start_excluded_paths' => [
            '/__bench/raw',
            '/__bench/framework',
            '/__bench/di',
            '/__bench/json',
            '/__bench/template',
            '/__bench/event',
        ],
    ],
    'cookie' => [
        'suppress_response_paths' => [
            '/__bench/raw',
            '/__bench/framework',
            '/__bench/di',
            '/__bench/json',
            '/__bench/template',
            '/__bench/event',
        ],
    ],
];
