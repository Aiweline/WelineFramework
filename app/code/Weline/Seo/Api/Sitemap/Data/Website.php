<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Sitemap\Data;

/** Immutable website projection used by sitemap providers. */
final readonly class Website
{
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public string $url,
    ) {
    }
}
