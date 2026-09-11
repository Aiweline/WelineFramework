<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;

interface DropshipCatalogProviderInterface extends DropshipProviderInterface
{
    /**
     * @param array<string, mixed> $query
     * @return list<DropshipCatalogSnapshot>
     */
    public function searchProducts(array $query): array;

    /**
     * @param array<string, mixed> $identity pid/vid/sku keys
     */
    public function getProduct(array $identity): ?DropshipCatalogSnapshot;

    /**
     * Fresh remote snapshot for an existing listing sync.
     *
     * @param array<string, mixed> $listingRow
     */
    public function syncCatalogSnapshot(array $listingRow): ?DropshipCatalogSnapshot;
}
