<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\CatalogFeedOffer;
use Weline\Product\Api\Data\CatalogFeedReadQuery;

/**
 * Product-owned catalog offer read for a later feed adapter.
 *
 * Returns plain offer facts. This module does not serialize XML, TSV, or YML.
 */
interface CatalogFeedOfferReaderInterface
{
    /**
     * @return iterable<CatalogFeedOffer>
     */
    public function read(CatalogFeedReadQuery $query): iterable;
}
