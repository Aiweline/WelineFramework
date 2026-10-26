<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Crawler;

/**
 * Optional edge-sync status for the website crawler policy admin UI.
 */
interface CrawlerPolicySyncStatusProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function statusForWebsite(int $websiteId): array;
}
