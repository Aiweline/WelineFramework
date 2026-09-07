<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

use Weline\Eav\Api\Entity\EntityDefinitionInterface;

/** Optional request-local prefetch; normal catalog reads retain their contract. */
interface AttributeMetadataPrefetchInterface
{
    /** @param list<int> $productIds All products to be projected, before pagination. */
    public function prefetchForProducts(
        EntityDefinitionInterface $entity,
        array $productIds,
        string $freeSetCode = '__product_free',
    ): void;
}
