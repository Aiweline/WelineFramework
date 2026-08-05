<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Product scene render input. Template paths must NEVER come from request.
 */
final class ProductSceneContext
{
    /**
     * @param array<string, mixed> $product Keys: name, sku, description, price_label, offer_id, product_id, ...
     * @param array<string, mixed> $options Extra scene options (not template paths)
     */
    public function __construct(
        public readonly string $scene,
        public readonly string $productType = 'simple',
        public readonly int $websiteId = 0,
        public readonly int $storeId = 0,
        public readonly array $product = [],
        public readonly array $options = [],
    ) {
    }
}
