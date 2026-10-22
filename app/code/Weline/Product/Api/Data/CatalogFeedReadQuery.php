<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Page key for catalog feed offer reads.
 *
 * `afterOfferId` is the last scanned offer id, not an emitted-row offset.
 */
final readonly class CatalogFeedReadQuery
{
    public function __construct(
        public int $websiteId,
        public int $storeId = 0,
        public string $locale = '',
        public string $currency = 'CNY',
        public string $sellingMode = '',
        public int $limit = 100,
        public int $afterOfferId = 0,
    ) {
    }
}
