<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedCategoryPlan
{
    /**
     * @param list<FeedItem> $items
     * @param list<array<string, mixed>> $diagnostics
     */
    public function __construct(
        public array $items,
        public array $diagnostics,
        public int $skippedCount,
    ) {
    }
}
