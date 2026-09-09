<?php

declare(strict_types=1);

namespace Weline\Search\Api;

/** 将最终投影与被其覆盖的事件证据放在同一个事务中写入。 */
interface SearchIndexEventCoverageStorageInterface extends SearchIndexStorageInterface
{
    /** @param list<array{event_id:string,event_seq:int}> $coveredEvents */
    public function applyCoveredChange(
        int $websiteId,
        int $eventSeq,
        string $idempotencyKey,
        array $documents,
        array $deleteKeys,
        array $coveredEvents,
    ): array;
}
