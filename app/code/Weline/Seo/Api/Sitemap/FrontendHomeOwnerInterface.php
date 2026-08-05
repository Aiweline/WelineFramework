<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Sitemap;

/**
 * Optional SitemapUrlProvider capability: this provider owns frontend `/`
 * for the returned website IDs.
 *
 * Weline_Index HomePageProvider must not emit fallback homepage URLs for
 * claimed websites. Claim detection is based on business ownership (site
 * identity / published home entity), not on rows already written to
 * weline_sitemap_url.
 */
interface FrontendHomeOwnerInterface
{
    /**
     * Website IDs (>= 0) whose frontend homepage (`/`) this provider owns.
     * website_id=0 is the legal system default site and may be claimed.
     *
     * @return list<int>
     */
    public function getFrontendHomeWebsiteIds(): array;
}
