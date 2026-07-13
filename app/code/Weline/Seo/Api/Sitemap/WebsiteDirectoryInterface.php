<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Sitemap;

use Weline\Seo\Api\Sitemap\Data\Website;

interface WebsiteDirectoryInterface
{
    /** @return list<Website> */
    public function all(): array;

    public function get(int $websiteId): ?Website;
}
