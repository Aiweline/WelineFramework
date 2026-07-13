<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Head\Data;

/** Immutable subset of SEO page context safe for optional integrations. */
final readonly class PageContext
{
    public function __construct(public string $siteName)
    {
    }
}
