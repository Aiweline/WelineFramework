<?php

return [
    "name" => 'Weline_Frontend',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
    ],
    "optional" => [
        'Weline_Ai' => '*',
        'Weline_UrlManager' => '*',
        'Weline_Websites' => '*',
    ],
    "provides" => [
        \Weline\Frontend\Api\Auth\FrontendAccountFacadeInterface::class => \Weline\Frontend\Service\FrontendAccountFacade::class,
        \Weline\Frontend\Api\User\FrontendUserAdministrationInterface::class => \Weline\Frontend\Service\FrontendUserAdministration::class,
        \Weline\Ai\Api\Billing\BillingAccountProviderInterface::class => \Weline\Frontend\Integration\Ai\FrontendBillingAccountProvider::class,
        \Weline\Theme\Api\View\FrontendThemeModePreferenceProviderInterface::class => \Weline\Frontend\Integration\Theme\FrontendThemeModePreferenceProvider::class,
        'deploy.flat_static.Weline_Frontend' => \Weline\Frontend\Api\Deploy\FlatStaticRuntimeFilesProvider::class,
        'request_resetter.Weline_Frontend' => \Weline\Frontend\Api\Runtime\RequestResetter::class,
    ],
];
