<?php

return [
    "name" => 'Weline_Shipping',
    "version" => '2.9.14',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Currency' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Frontend' => '*',
        'Weline_Inventory' => '*',
        'Weline_Order' => '*',
        'Weline_Product' => '*',
        'Weline_Theme' => '*',
    ],
    "provides" => [
        'template_cache_policy.Weline_Shipping' => \Weline\Shipping\Api\View\TemplateCachePolicyProvider::class,
        'view_warmup_contribution.Weline_Shipping' => \Weline\Shipping\Api\View\ViewWarmupContributionProvider::class,
        \Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface::class
            => \Weline\Shipping\Service\ScopedShippingQuoteService::class,
        \Weline\Shipping\Api\Quote\SplitShippingQuoteServiceInterface::class
            => \Weline\Shipping\Service\SplitShippingQuoteService::class,
        \Weline\Shipping\Api\WarehouseShippingOriginInterface::class
            => \Weline\Shipping\Service\WarehouseShippingOriginService::class,
        'shipping.carrier_coverage.default' => \Weline\Shipping\Service\DefaultCarrierCoverageProvider::class,
        \Weline\Order\Api\OrderShippingMethodCatalogInterface::class
            => \Weline\Shipping\Integration\Order\OrderShippingMethodCatalog::class,
        \Weline\Order\Api\OrderShippingFulfillmentGatewayInterface::class
            => \Weline\Shipping\Integration\Order\OrderShippingFulfillmentGateway::class,
    ],
];
