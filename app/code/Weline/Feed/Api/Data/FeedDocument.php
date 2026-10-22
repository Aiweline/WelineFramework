<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedDocument
{
    /**
     * @param list<array<string, mixed>> $diagnostics
     */
    public function __construct(
        public string $contentType,
        public string $filenameSuffix,
        public string $body,
        public string $diagnosticCode = '',
        public int $itemCount = 0,
        public int $skippedCount = 0,
        public array $diagnostics = [],
    ) {
    }
}
