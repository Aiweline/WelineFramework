<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedGenerationReport
{
    /**
     * @param list<array<string, mixed>> $diagnostics
     */
    public function __construct(
        public bool $completed,
        public int $itemCount,
        public int $skippedCount,
        public string $connectionStatus,
        public array $diagnostics,
        public string $errorCode,
        public string $body,
    ) {
    }
}
