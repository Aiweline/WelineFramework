<?php

declare(strict_types=1);

namespace Weline\Product\Integration\Cart;

use Weline\Cart\Api\ProductTypeRequiresShippingResolverInterface;
use Weline\Product\Api\ProductProviderInterface;
use Weline\Product\Api\ProductProviderV2Interface;
use Weline\Product\Service\ProductProviderRegistry;
use Weline\Product\Service\ProductProviderV1Adapter;

/**
 * Offer-missing fallback: ProductTypeDefinition.requiresShipping via provider SPI.
 * No site-specific product_type string hardcodes in this resolver.
 */
final class ProductTypeRequiresShippingResolver implements ProductTypeRequiresShippingResolverInterface
{
    public function __construct(
        private readonly ProductProviderRegistry $registry,
    ) {
    }

    public function requiresShippingForProductType(string $productType): ?bool
    {
        $type = strtolower(trim($productType));
        if ($type === '') {
            return null;
        }
        $provider = $this->registry->getByType($type, onlyEnabled: false);
        if ($provider === null) {
            return null;
        }

        return $this->definitionRequiresShipping($provider);
    }

    private function definitionRequiresShipping(ProductProviderInterface $provider): bool
    {
        if ($provider instanceof ProductProviderV2Interface) {
            return $provider->getDefinition()->requiresShipping;
        }

        return (new ProductProviderV1Adapter($provider))->getDefinition()->requiresShipping;
    }
}
