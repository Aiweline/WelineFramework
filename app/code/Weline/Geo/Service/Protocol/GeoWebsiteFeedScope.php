<?php

declare(strict_types=1);

namespace Weline\Geo\Service\Protocol;

use Weline\Geo\Model\FeedItem;
use Weline\Seo\Service\SeoWebsiteDirectory;

/**
 * Keeps GEO protocol / feed items inside one website boundary (same idea as sitemap).
 */
final class GeoWebsiteFeedScope
{
    public function __construct(
        private readonly SeoWebsiteDirectory $websiteDirectory,
    ) {
    }

    /**
     * @return list<string> lowercase hosts belonging to the website
     */
    public function allowedHosts(int $websiteId, string $publicBaseUrl = ''): array
    {
        $hosts = [];
        $registered = $this->websiteDirectory->getWebsiteById($websiteId, true);
        $website = is_array($registered)
            ? $registered
            : ['website_id' => $websiteId, 'url' => $publicBaseUrl];
        foreach ($this->websiteDirectory->listPublicOrigins($website) as $origin) {
            $host = $this->hostOf((string)$origin);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        $fallback = $this->hostOf($publicBaseUrl);
        if ($fallback !== '') {
            $hosts[$fallback] = true;
        }

        return array_keys($hosts);
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $allowedHosts
     */
    public function itemBelongsToWebsite(array $item, int $websiteId, array $allowedHosts): bool
    {
        if ($websiteId < 0) {
            return false;
        }

        $itemWebsiteId = $this->itemWebsiteId($item);
        if ($itemWebsiteId !== null && $itemWebsiteId !== $websiteId) {
            return false;
        }

        $url = trim((string)($item[FeedItem::schema_fields_URL] ?? $item['url'] ?? ''));
        if ($url === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            // Relative URLs inherit the current request site.
            return $itemWebsiteId === null || $itemWebsiteId === $websiteId;
        }

        $host = $this->hostOf($url);
        if ($host === '') {
            return false;
        }
        if ($allowedHosts === []) {
            // No registered origins yet: require an explicit matching website_id.
            return $itemWebsiteId === $websiteId;
        }

        return in_array($host, $allowedHosts, true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $allowedHosts
     * @return list<array<string, mixed>>
     */
    public function filterItems(array $items, int $websiteId, array $allowedHosts): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($this->itemBelongsToWebsite($item, $websiteId, $allowedHosts)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public function siteFeedDirectory(int $websiteId): string
    {
        // Avoid touching FeedGeneratorService::FEED_DIR here: that constant
        // evaluates PUB at class-load time and breaks lightweight unit boots.
        $root = defined('BP')
            ? rtrim(BP . '/pub/geo-feeds', '/')
            : rtrim(sys_get_temp_dir() . '/geo-feeds', '/');

        return $root . '/w' . max(0, $websiteId);
    }

    public function siteJsonPath(int $websiteId): string
    {
        return $this->siteFeedDirectory($websiteId) . '/site.json';
    }

    public function siteXmlPath(int $websiteId): string
    {
        return $this->siteFeedDirectory($websiteId) . '/site.xml';
    }

    /**
     * @param array<string, mixed> $item
     */
    public function itemWebsiteId(array $item): ?int
    {
        $metadata = $item[FeedItem::schema_fields_METADATA] ?? ($item['metadata'] ?? null);
        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        if (array_key_exists('website_id', $metadata) && $metadata['website_id'] !== null && $metadata['website_id'] !== '') {
            $id = (int)$metadata['website_id'];
            return $id >= 0 ? $id : null;
        }
        if (array_key_exists('website_id', $item) && $item['website_id'] !== null && $item['website_id'] !== '') {
            $id = (int)$item['website_id'];
            return $id >= 0 ? $id : null;
        }

        return null;
    }

    private function hostOf(string $url): string
    {
        $host = strtolower((string)(parse_url(trim($url), PHP_URL_HOST) ?? ''));

        return $host;
    }
}
