<?php

declare(strict_types=1);

namespace Weline\Search\Api;

interface SearchDegradeMarkerStoreInterface
{
    /**
     * @return array{
     *   website_id:int,
     *   active:bool,
     *   reason:string,
     *   required_source_watermark:int,
     *   index_watermark_at_mark:int,
     *   marker_version:int,
     *   marked_at:string,
     *   cleared_at:?string
     * }|null
     */
    public function get(int $websiteId): ?array;

    /**
     * @return array<string,mixed>
     */
    public function mark(
        int $websiteId,
        string $reason,
        int $requiredSourceWatermark,
        int $indexWatermarkAtMark,
    ): array;

    /**
     * @return array<string,mixed>
     */
    public function clearIfRecovered(
        int $websiteId,
        int $currentIndexWatermark,
        int $currentSourceWatermark,
    ): array;

    /**
     * Roll back only the marker created by the named controlled failure.
     */
    public function clearForRollback(int $websiteId, string $expectedReason): bool;
}
