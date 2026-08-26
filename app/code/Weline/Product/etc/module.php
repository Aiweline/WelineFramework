<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Product',
    'version' => '1.0.23',
    'requires' => [
        'Weline_Catalog' => '*',
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
        'Weline_Eav' => '*',
        'Weline_FileManager' => '*',
    ],
    'optional' => [
        'Weline_Cart' => '*',
        'Weline_Inventory' => '*',
        'Weline_MediaManager' => '*',
        'Weline_Order' => '*',
    ],
    'provides' => [
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
        \Weline\Cart\Api\CartPriceSellabilityProviderInterface::class
            => \Weline\Product\Integration\Cart\ProductCartPriceSellabilityProvider::class,
    ],
];
