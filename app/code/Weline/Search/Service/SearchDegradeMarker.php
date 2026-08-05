<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\SearchDegradeMarkerStoreInterface;

/**
 * Per-website durable degrade marker + recovery gate（TEST-P3C-03/04）.
 *
 * Recovery requires Search incremental watermark to equal Product current.
 */
final class SearchDegradeMarker
{
    public function __construct(
        private readonly SearchDegradeMarkerStoreInterface $store,
    ) {
    }

    public static function forTesting(): self
    {
        return new self(new ArraySearchDegradeMarkerStore());
    }

    /** @return array<string,mixed> */
    public function mark(
        int $websiteId,
        string $reason,
        int $requiredSourceWatermark,
        int $indexWatermarkAtMark,
    ): array {
        return $this->store->mark(
            $websiteId,
            $reason,
            $requiredSourceWatermark,
            $indexWatermarkAtMark,
        );
    }

    public function isActive(int $websiteId): bool
    {
        return ($this->store->get($websiteId)['active'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $websiteId): ?array
    {
        return $this->store->get($websiteId);
    }

    /** @return array<string,mixed> */
    public function clearIfRecovered(
        int $websiteId,
        int $currentIndexWatermark,
        int $currentSourceWatermark,
    ): array {
        return $this->store->clearIfRecovered(
            $websiteId,
            $currentIndexWatermark,
            $currentSourceWatermark,
        );
    }

    public function clearForRollback(int $websiteId, string $expectedReason): bool
    {
        return $this->store->clearForRollback($websiteId, $expectedReason);
    }
}
