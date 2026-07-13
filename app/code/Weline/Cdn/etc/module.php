<?php

return [
    "name" => 'Weline_Cdn',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Cron' => '*',
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
    ],
    "provides" => [
        'cache.edge_adapter.100.cloudflare' => \Weline\Cdn\Adapter\Cloudflare::class,
    ],
];
