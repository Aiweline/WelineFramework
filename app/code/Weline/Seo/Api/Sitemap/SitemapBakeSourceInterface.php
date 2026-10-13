<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Sitemap;

/**
 * Narrow façade for storefront FPC bake orchestration (Websites soft-pulls this only).
 */
interface SitemapBakeSourceInterface
{
    /**
     * Sync provider URLs then attempt sitemap file generation.
     *
     * @return array{ok:bool,hard_gate:bool,message:string,website_id:int}
     */
    public function refreshForBake(int $websiteId, string $module = ''): array;

    /**
     * Active absolute loc strings for the website (table authority; not disk XML).
     *
     * @return list<string>
     */
    public function listActiveLocs(int $websiteId): array;
}
