<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Crawler;

/**
 * Read-only view of a website's crawler allow/block policy.
 *
 * Consumers (CDN edge publisher, robots renderer) must depend on this interface
 * instead of the concrete Weline_Websites service.
 */
interface CrawlerPolicyProviderInterface
{
    public function isEnabled(int $websiteId): bool;

    /**
     * @return list<CrawlerPolicyEntry>
     */
    public function entriesForWebsite(int $websiteId): array;

    /**
     * Stable hash of the normalized entries (order-independent).
     */
    public function revisionForWebsite(int $websiteId): string;
}
