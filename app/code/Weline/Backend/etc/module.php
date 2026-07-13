<?php

return [
    "name" => 'Weline_Backend',
    "version" => '1.3.0',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Backend\Api\Auth\BackendAccountFacadeInterface::class => \Weline\Backend\Service\BackendAccountFacade::class,
        \Weline\Backend\Api\Auth\BackendApiAuthenticationInterface::class => \Weline\Backend\Service\BackendApiAuthentication::class,
        \Weline\Backend\Api\Auth\BackendInteractiveAuthInterface::class => \Weline\Backend\Service\BackendInteractiveAuth::class,
        \Weline\Backend\Api\Auth\BackendUserDirectoryInterface::class => \Weline\Backend\Service\BackendUserDirectory::class,
        \Weline\Backend\Api\Auth\BackendUserContextProviderInterface::class => \Weline\Backend\Service\BackendUserContextProvider::class,
        \Weline\Backend\Api\User\BackendUserAdministrationInterface::class => \Weline\Backend\Service\BackendUserAdministration::class,
        \Weline\Backend\Api\UserData\BackendCurrentUserDataInterface::class => \Weline\Backend\Service\BackendCurrentUserData::class,
        \Weline\Backend\Api\Menu\MenuReaderInterface::class => \Weline\Backend\Service\MenuService::class,
        \Weline\Backend\Api\Notification\NotificationSeedServiceInterface::class => \Weline\Backend\Service\NotificationSeedService::class,
        \Weline\Backend\Api\View\BackendThemeConfigInterface::class => \Weline\Backend\Block\ThemeConfig::class,
        \Weline\Acl\Api\Auth\BackendIdentityContextProviderInterface::class => \Weline\Backend\Integration\Acl\BackendIdentityContextProvider::class,
        \Weline\Acl\Api\Resource\MenuSourceProviderInterface::class => \Weline\Backend\Integration\Acl\MenuSourceProvider::class,
        'view_warmup_contribution.Weline_Backend' => \Weline\Backend\Api\View\ViewWarmupContributionProvider::class,
        'deploy.flat_static.Weline_Backend' => \Weline\Backend\Api\Deploy\FlatStaticRuntimeFilesProvider::class,
        \Weline\Framework\Runtime\BackendWarmupProviderInterface::class => \Weline\Backend\Api\Runtime\BackendWarmupProvider::class,
        \Weline\Framework\Runtime\StartPageRouteProviderInterface::class => \Weline\Backend\Api\Runtime\StartPageRouteProvider::class,
        \Weline\Framework\Session\Auth\BackendSessionUserProviderInterface::class => \Weline\Backend\Api\Auth\BackendSessionUserProvider::class,
        'request_resetter.Weline_Backend' => \Weline\Backend\Api\Runtime\RequestResetter::class,
    ],
];
