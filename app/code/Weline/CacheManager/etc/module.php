<?php

return [
    "name" => 'Weline_CacheManager',
    "version" => '1.2.0',
    "requires" => [
        'Weline_Admin' => '*',
    ],
    "optional" => [
        'Weline_Cron' => '*',
    ],
    "provides" => [
        \Weline\Framework\Cache\Contract\CacheStatusProviderInterface::class => \Weline\CacheManager\Api\CacheStatusProvider::class,
    ],
];
