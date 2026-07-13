<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Protocol\Data;

/** Immutable current-website context for protocol renderers. */
final readonly class WebsiteContext
{
    public function __construct(
        public int $id,
        public string $url,
        public string $name,
    ) {
    }
}
