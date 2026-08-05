<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Framework\Database\ConnectionFactory;

/**
 * Makes one Product catalog mutation and its Search projection event atomic.
 */
interface ProductSearchProjectionMutationCoordinatorInterface
{
    public const RESOURCE_TYPE = 'product_search_projection';
    public const CONTRACT = 'product.search_projection_changed.v1';
    public const TARGET_PRODUCT = 'product';
    public const TARGET_STORE_PRODUCT = 'store_product';

    public function execute(
        ConnectionFactory $connection,
        int $websiteId,
        string $targetType,
        int $targetId,
        ?int $storeId,
        callable $mutation,
    ): mixed;
}
