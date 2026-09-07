<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/** Optional selection projection for ProductAdminReadInterface implementations. */
interface ProductSelectionReadInterface
{
    /**
     * Apply the same filters and ordering as ProductAdminReadInterface::search(),
     * without hydrating display-only product details.
     *
     * @param array<string, mixed> $filters
     * @return list<array{product_id:int, updated_at:string}>
     */
    public function searchSelectionRows(int $websiteId, array $filters = []): array;
}
