<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

interface CartItemSnapshotProviderInterface
{
    /**
     * Return null when this provider does not own the requested product.
     *
     * Supported snapshot keys include product_id, name, sku, image, price,
     * found, sellable, stock, qty, message, source_app, source_module,
     * business_module, business_code, business_name, and product_type.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function resolveCartItemSnapshot(int $productId, array $params = []): ?array;
}
