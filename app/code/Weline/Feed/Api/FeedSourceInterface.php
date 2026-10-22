<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

use Weline\Feed\Api\Data\FeedCollectQuery;
use Weline\Feed\Api\Data\FeedItem;

/**
 * Feed-side source port. Catalog offers enter only through this collect call.
 */
interface FeedSourceInterface
{
    public function sourceCode(): string;

    /**
     * @return iterable<FeedItem>
     */
    public function collect(FeedCollectQuery $query): iterable;
}
