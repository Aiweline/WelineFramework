<?php

declare(strict_types=1);

namespace Weline\Search\Api;

/**
 * Public Product current-source boundary consumed by the Search indexer.
 */
interface ProductSearchProjectionSourceInterface
{
    public function currentWatermark(int $websiteId): int;

    /** @return array<string,mixed> */
    public function snapshotWebsite(int $websiteId): array;

    /**
     * Request-scoped Product current snapshot for Search direct/degrade reads.
     *
     * @return array<string,mixed>
     */
    public function snapshotScope(int $websiteId, int $storeId, int $channelId): array;

    /** @param array<string,mixed> $change @return array<string,mixed> */
    public function projectChange(array $change): array;
}
