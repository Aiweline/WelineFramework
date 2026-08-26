<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\ProductAdminSnapshot;

/** Product-owned aggregate read boundary for backend pages and Resources. */
interface ProductAdminReadInterface
{
    /** @param array<string, mixed> $filters
     *  @return list<array<string, mixed>>
     */
    public function search(int $websiteId, array $filters = []): array;

    /** @return array<string, mixed> */
    public function creationContext(int $websiteId): array;

    public function snapshot(
        int $websiteId,
        string $globalProductUuid,
        ?int $storeId = null,
        string $locale = '',
        string $currency = 'CNY',
    ): ProductAdminSnapshot;
}
