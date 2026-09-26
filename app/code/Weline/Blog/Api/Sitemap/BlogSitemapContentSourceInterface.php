<?php

declare(strict_types=1);

namespace Weline\Blog\Api\Sitemap;

use Weline\Blog\Api\Data\BlogArticle;

/**
 * Narrow read surface for Blog sitemap URL building (testable without doubling final resolver).
 */
interface BlogSitemapContentSourceInterface
{
    /**
     * @return list<BlogArticle>
     */
    public function listPublishedArticles(int $websiteId, string $locale, int $limit = 50, string $baseUrl = ''): array;

    public function publicSlugForLocale(string $slug, string $locale): string;
}
