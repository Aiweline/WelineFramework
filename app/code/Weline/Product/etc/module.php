<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Product',
    'version' => '1.0.16',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
    ],
    'optional' => [
        'Weline_Cart' => '*',
        'Weline_Inventory' => '*',
    ],
    'provides' => [
        \Weline\Product\Api\ProductIdentityResolverInterface::class
            => \Weline\Product\Service\SkuRegistryService::class,
        \Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface::class
            => \Weline\Product\Service\ProductSearchProjectionMutationCoordinator::class,
        \Weline\Cart\Api\CartPriceSellabilityProviderInterface::class
            => \Weline\Product\Integration\Cart\ProductCartPriceSellabilityProvider::class,
    ],
];
