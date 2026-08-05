<?php

declare(strict_types=1);

return [
    'event' => [
        'async' => [
            'producer_enabled' => false,
            'relay_enabled' => false,
        ],
    ],
    'cache' => [
        'namespace' => [
            'publisher_enabled' => false,
            'legacy_full_clear_fallback' => true,
        ],
    ],
];
