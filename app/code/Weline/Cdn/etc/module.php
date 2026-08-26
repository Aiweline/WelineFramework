<?php

return [
    "name" => 'Weline_Cdn',
    "version" => '1.0.3',
    "requires" => [
        'Weline_Cron' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
    ],
    "provides" => [
        'cache.edge_adapter.100.cloudflare' => \Weline\Cdn\Adapter\Cloudflare::class,
        \Weline\Cdn\Api\ScopedAccountBindingRepositoryInterface::class
            => \Weline\Cdn\Service\OrmScopedAccountBindingRepository::class,
        \Weline\Cdn\Api\MailDnsManagerInterface::class
            => \Weline\Cdn\Service\CloudflareMailDnsManager::class,
    ],
];
