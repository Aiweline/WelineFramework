<?php

declare(strict_types=1);

namespace Weline\Cdn\Api;

/**
 * Publish a website's crawler allow/block policy to the CDN edge.
 */
interface CrawlerPolicyEdgePublisherInterface
{
    /**
     * Pure plan (no HTTP): expressions, hash, target domains.
     *
     * @return array<string, mixed>
     */
    public function plan(int $websiteId): array;

    /**
     * @return array<string, mixed>
     */
    public function publish(int $websiteId, bool $dryRun = false, bool $force = false): array;

    /**
     * @return array<string, mixed>
     */
    public function inspect(int $websiteId): array;
}
