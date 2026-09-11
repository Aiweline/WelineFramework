<?php

return [
    "name" => 'Weline_Shipping',
    "version" => '2.4.81',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Frontend' => '*',
        'Weline_Product' => '*',
        'Weline_Theme' => '*',
    ],
    "provides" => [
        'template_cache_policy.Weline_Shipping' => \Weline\Shipping\Api\View\TemplateCachePolicyProvider::class,
        'view_warmup_contribution.Weline_Shipping' => \Weline\Shipping\Api\View\ViewWarmupContributionProvider::class,
        \Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface::class
            => \Weline\Shipping\Service\ScopedShippingQuoteService::class,
        'shipping.carrier_coverage.default' => \Weline\Shipping\Service\DefaultCarrierCoverageProvider::class,
    ],
];
