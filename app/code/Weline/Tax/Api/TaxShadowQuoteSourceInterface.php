<?php

declare(strict_types=1);

namespace Weline\Tax\Api;

/**
 * Read-only boundary for normalized, persisted checkout quote facts.
 *
 * The producer owns its storage and must remove customer identity and raw
 * address fields before returning Tax requests.
 */
interface TaxShadowQuoteSourceInterface
{
    /**
     * @return array{
     *     requests:list<array<string,mixed>>,
     *     scanned_count:int,
     *     rejected_count:int,
     *     duplicate_count:int,
     *     request_hashes:list<string>
     * }
     */
    public function observationWindow(
        int $websiteId,
        int $storeId,
        int $channelId,
        int $limit,
    ): array;
}
