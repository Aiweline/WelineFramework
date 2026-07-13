<?php

return [
    "name" => 'Weline_Websites',
    "version" => '1.6.4',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_Component' => '*',
        'Weline_Currency' => '*',
        'Weline_Cron' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
        'Weline_Ai' => '*',
        'Weline_Server' => '*',
    ],
    "provides" => [
        \Weline\Websites\Api\WebsiteTargetLookupInterface::class => \Weline\Websites\Api\WebsiteTargetLookup::class,
        \Weline\Websites\Api\Catalog\WebsiteCatalogInterface::class => \Weline\Websites\Service\WebsiteCatalog::class,
        \Weline\Websites\Api\Localization\WebsiteCurrencyCatalogInterface::class => \Weline\Websites\Service\CurrentWebsiteCurrencyCatalog::class,
        \Weline\Backend\Api\Runtime\FrontendStartPageRouteProviderInterface::class => \Weline\Websites\Integration\Backend\FrontendStartPageRouteProvider::class,
        \Weline\Server\Api\Tls\AcmeDnsTxtPollPolicyProviderInterface::class => \Weline\Websites\Integration\Server\AcmeDnsTxtPollPolicyProvider::class,
        \Weline\Server\Api\Tls\ActiveCertificateDomainSourceInterface::class => \Weline\Websites\Integration\Server\ActiveCertificateDomainSource::class,
        'localization_provider.Weline_Websites' => \Weline\Websites\Api\Localization\LocalizationProvider::class,
        'request_resetter.Weline_Websites' => \Weline\Websites\Api\Runtime\RequestResetter::class,
    ],
];
