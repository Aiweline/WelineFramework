<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

class ProductIndexer
{
    public function __construct(
        private readonly SearchIndexer $searchIndexer
    ) {
    }

    public function indexProduct(?int $productId = null, bool $forceReindex = false): bool
    {
        if ($productId !== null) {
            return $this->searchIndexer->indexEntity('product', $productId);
        }

        return $this->searchIndexer->rebuild('product', $forceReindex);
    }

    public function deleteProduct(int $productId): bool
    {
        return $this->searchIndexer->deleteEntity('product', $productId);
    }

    public function configureIndex(): bool
    {
        return $this->searchIndexer->configure();
    }
}
