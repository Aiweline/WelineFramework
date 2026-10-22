<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedPublicSnapshot
{
    public function __construct(
        public bool $enabled,
        public bool $artifactReady,
        public bool $fileExists,
        public string $body,
        public string $contentType,
        public string $storagePath,
    ) {
    }
}
