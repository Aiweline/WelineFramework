<?php

return [
    "name" => 'Weline_Customer',
    "version" => '1.0.1',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '^2.4',
        'Weline_Frontend' => '*',
    ],
    "optional" => [
        'Weline_Captcha' => '*',
        'Weline_Currency' => '*',
        'Weline_I18n' => '*',
        'Weline_Order' => '*',
        'Weline_Seo' => '*',
        'Weline_Shipping' => '*',
        'Weline_Theme' => '*',
    ],
    "provides" => [
        \Weline\Customer\Api\Auth\CustomerAccountFacadeInterface::class => \Weline\Customer\Service\CustomerAccountFacade::class,
        \Weline\Customer\Api\Auth\CustomerIdentityProviderInterface::class => \Weline\Customer\Service\CustomerIdentityProvider::class,
        \Weline\Customer\Api\View\AccountSidebarProjectionProviderInterface::class => \Weline\Customer\Service\AccountSidebarProjectionProvider::class,
        \Weline\Theme\Api\PreviewAccountProviderInterface::class => \Weline\Customer\Integration\Theme\PreviewAccountProvider::class,
        'template_cache_policy.Weline_Customer' => \Weline\Customer\Api\View\TemplateCachePolicyProvider::class,
        'view_warmup_contribution.Weline_Customer' => \Weline\Customer\Api\View\ViewWarmupContributionProvider::class,
    ],
];
