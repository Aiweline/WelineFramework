<?php

return [
    "name" => 'Weline_Admin',
    "version" => '1.0.1',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Admin\Api\Notification\SystemNotificationDirectoryInterface::class => \Weline\Admin\Service\SystemNotificationDirectory::class,
        \Weline\Acl\Api\Auth\RememberLoginProviderInterface::class => \Weline\Admin\Integration\Acl\RememberLoginProvider::class,
        \Weline\Backend\Api\Routing\LoginReturnUrlProviderInterface::class => \Weline\Admin\Integration\Backend\LoginReturnUrlProvider::class,
        \Weline\Backend\Api\Runtime\BackendThemeCacheInvalidatorInterface::class => \Weline\Admin\Integration\Backend\BackendThemeCacheInvalidator::class,
        'view_warmup_contribution.Weline_Admin' => \Weline\Admin\Api\View\ViewWarmupContributionProvider::class,
        'request_resetter.Weline_Admin' => \Weline\Admin\Api\Runtime\RequestResetter::class,
        'process_cache_resetter.Weline_Admin' => \Weline\Admin\Api\Runtime\ProcessCacheResetter::class,
    ],
];
