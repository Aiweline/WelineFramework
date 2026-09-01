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

    /** @return array<string, mixed> */
    public function attributeCatalog(int $websiteId, string $globalProductUuid): array;

    /**
     * Frontend URL Handle availability for create/edit forms.
     *
     * @return array{
     *   slug:string,
     *   available:bool,
     *   reason:string,
     *   conflict_product_id:int
     * }
     */
    public function slugAvailability(int $websiteId, string $slug, int $excludeProductId = 0): array;
}
