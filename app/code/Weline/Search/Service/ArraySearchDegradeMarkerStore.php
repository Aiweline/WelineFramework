<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\SearchDegradeMarkerStoreInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Isolated marker store for unit tests.
 */
final class ArraySearchDegradeMarkerStore implements SearchDegradeMarkerStoreInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $states = [];

    public function get(int $websiteId): ?array
    {
        SearchShardKey::fromWebsiteId($websiteId);

        return $this->states[$websiteId] ?? null;
    }

    public function mark(
        int $websiteId,
        string $reason,
        int $requiredSourceWatermark,
        int $indexWatermarkAtMark,
    ): array {
        SearchShardKey::fromWebsiteId($websiteId);
        $reason = \trim($reason);
        if ($reason === '' || $requiredSourceWatermark < 0 || $indexWatermarkAtMark < 0) {
            throw new \InvalidArgumentException('search_degrade_marker_invalid');
        }
        $current = $this->states[$websiteId] ?? null;
        $now = \gmdate('Y-m-d H:i:s');
        $state = [
            'website_id' => $websiteId,
            'active' => true,
            'reason' => $reason,
            'required_source_watermark' => \max(
                $requiredSourceWatermark,
                (int)($current['required_source_watermark'] ?? 0),
            ),
            'index_watermark_at_mark' => $indexWatermarkAtMark,
            'marker_version' => (int)($current['marker_version'] ?? 0) + 1,
            'marked_at' => $now,
            'cleared_at' => null,
        ];
        $this->states[$websiteId] = $state;

        return $state;
    }

    public function clearIfRecovered(
        int $websiteId,
        int $currentIndexWatermark,
        int $currentSourceWatermark,
    ): array {
        $current = $this->get($websiteId);
        if ($current === null || !$current['active']) {
            return $current ?? [
                'website_id' => $websiteId,
                'active' => false,
                'reason' => '',
                'required_source_watermark' => 0,
                'index_watermark_at_mark' => 0,
                'marker_version' => 0,
                'marked_at' => '',
                'cleared_at' => null,
            ];
        }
        $this->assertRecovered($current, $currentIndexWatermark, $currentSourceWatermark);
        $current['active'] = false;
        $current['marker_version'] = (int)$current['marker_version'] + 1;
        $current['cleared_at'] = \gmdate('Y-m-d H:i:s');
        $this->states[$websiteId] = $current;

        return $current;
    }

    public function clearForRollback(int $websiteId, string $expectedReason): bool
    {
        $current = $this->get($websiteId);
        if ($current === null || !$current['active']) {
            return true;
        }
        if (!\hash_equals((string)$current['reason'], \trim($expectedReason))) {
            return false;
        }
        $current['active'] = false;
        $current['marker_version'] = (int)$current['marker_version'] + 1;
        $current['cleared_at'] = \gmdate('Y-m-d H:i:s');
        $this->states[$websiteId] = $current;

        return true;
    }

    /** @param array<string,mixed> $state */
    private function assertRecovered(
        array $state,
        int $currentIndexWatermark,
        int $currentSourceWatermark,
    ): void {
        $required = (int)$state['required_source_watermark'];
        if ($currentIndexWatermark < $required
            || $currentSourceWatermark < $required
            || $currentIndexWatermark !== $currentSourceWatermark
        ) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_RECOVERY_WATERMARK,
                (string)__('Search 恢复水位未与 Product current 追平'),
                [
                    'website_id' => $state['website_id'],
                    'required' => $required,
                    'index' => $currentIndexWatermark,
                    'source' => $currentSourceWatermark,
                ],
            );
        }
    }
}
