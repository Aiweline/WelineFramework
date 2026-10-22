<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\CatalogFeedOfferCandidate;
use Weline\Product\Api\Data\CatalogFeedReadQuery;

/**
 * Published-offer facts before price assembly and feed inclusion rules.
 */
interface CatalogFeedOfferCandidateSourceInterface
{
    /**
     * @return iterable<CatalogFeedOfferCandidate>
     */
    public function collect(CatalogFeedReadQuery $query): iterable;
}
