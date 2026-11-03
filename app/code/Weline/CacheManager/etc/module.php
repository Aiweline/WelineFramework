<?php

return [
    "name" => 'Weline_CacheManager',
    "version" => '1.2.2',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Admin' => '*',
    ],
    "optional" => [
        'Weline_Cron' => '*',
        'Weline_Theme' => '*',
    ],
    "provides" => [
        \Weline\Framework\Cache\Contract\CacheStatusProviderInterface::class => \Weline\CacheManager\Api\CacheStatusProvider::class,
    ],
];
