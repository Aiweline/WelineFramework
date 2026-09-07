<?php

declare(strict_types=1);

namespace Weline\Search\Api;

use Weline\Search\Queue\SearchIndexIncrementalQueue;

/**
 * In-process sibling drain for Search projection queue Workers.
 */
interface SearchProjectionPendingDrainerInterface
{
    /**
     * @return array{drained:int,failed:int,remaining:int}
     */
    public function drainSiblings(
        SearchIndexIncrementalQueue $consumer,
        int $excludeQueueId,
        int $limit,
    ): array;
}
