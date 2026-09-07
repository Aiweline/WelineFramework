<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Product',
    'version' => '1.0.165',
    'requires' => [
        'Weline_Catalog' => '*',
        'Weline_DataTable' => '*',
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
        'Weline_Eav' => '*',
        'Weline_FileManager' => '*',
    ],
    'optional' => [
        'Weline_Cart' => '*',
        'Weline_Customer' => '*',
        'Weline_Inventory' => '*',
        'Weline_MediaManager' => '*',
        'Weline_Order' => '*',
        'Weline_Seo' => '*',
        'Weline_Promotion' => '*',
        'Weline_Marketing' => '*',
        'Weline_Captcha' => '*',
        'Weline_Mail' => '*',
        'Weline_Theme' => '*',
    ],
    'provides' => [
        'view_warmup_contribution.Weline_Product'
            => \Weline\Product\Api\View\ViewWarmupContributionProvider::class,
        \Weline\Product\Api\ProductAdminCommandInterface::class
            => \Weline\Product\Service\ProductAdminCommandService::class,
        \Weline\Product\Api\ProductAdminReadInterface::class
            => \Weline\Product\Service\ProductAdminReadService::class,
        \Weline\Product\Api\ProductIdentityV2ResolverInterface::class
            => \Weline\Product\Service\ProductIdentityV2Service::class,
        \Weline\Product\Api\ProductIdentityCutoverPolicyInterface::class
            => \Weline\Product\Service\ProductIdentityCutoverService::class,
        \Weline\Product\Api\ProductIdentityResolverInterface::class
            => \Weline\Product\Service\CompatibleProductIdentityResolver::class,
        \Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface::class
            => \Weline\Product\Service\ProductSearchProjectionMutationCoordinator::class,
        \Weline\Product\Api\ProductDownloadEntitlementInterface::class
            => \Weline\Product\Service\ProductDownloadEntitlementService::class,
        \Weline\Product\Api\StorefrontOfferPriceAssemblerInterface::class
            => \Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler::class,
        \Weline\Product\Api\ProductQuoteRequestSubmitInterface::class
            => \Weline\Product\Service\ProductQuoteRequestService::class,
        \Weline\Cart\Api\CartPriceSellabilityProviderInterface::class
            => \Weline\Product\Integration\Cart\ProductCartPriceSellabilityProvider::class,
    ],
];
