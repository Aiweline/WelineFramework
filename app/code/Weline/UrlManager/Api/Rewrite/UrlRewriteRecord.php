<?php

declare(strict_types=1);

namespace Weline\UrlManager\Api\Rewrite;

/** Immutable URL rewrite projection for cross-module readers. */
final readonly class UrlRewriteRecord
{
    public function __construct(
        public int $id,
        public ?int $websiteId,
        public bool $websiteIdSpecified,
        public string $path,
        public string $rewrite,
    ) {
    }
}
