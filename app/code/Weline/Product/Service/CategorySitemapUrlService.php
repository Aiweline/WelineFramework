<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\Category;
use Weline\Product\Repository\CategoryRepository;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/** Category storefront URLs for sitemap discovery. */
final class CategorySitemapUrlService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly WebsiteCatalogInterface $websites,
        private readonly StoreCatalogInterface $stores,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function getUrlsForWebsite(int $websiteId): array
    {
        $websiteUrl = '';
        foreach ($this->websites->all() as $website) {
            if ($website->id === $websiteId) {
                $websiteUrl = rtrim(trim($website->url), '/');
                break;
            }
        }
        if ($websiteUrl === '' || !$this->isHttpUrl($websiteUrl)) {
            return [];
        }

        $urls = [];
        foreach ($this->stores->byWebsite($websiteId) as $store) {
            if (!$store->enabled || $store->lifecycleStatus !== 'active' || $store->storeMode !== 'normal') {
                continue;
            }
            $base = rtrim(trim((string) $store->url), '/') ?: $websiteUrl;
            if (!$this->isHttpUrl($base) || $this->origin($base) !== $this->origin($websiteUrl)) {
                continue;
            }

            foreach ($this->categories->listAll($websiteId) as $row) {
                if (strtolower(trim((string) ($row[Category::schema_fields_STATUS] ?? 'active'))) === 'inactive') {
                    continue;
                }
                $categoryId = (int) ($row[Category::schema_fields_ID] ?? 0);
                if ($categoryId <= 0) {
                    continue;
                }
                $path = trim(str_replace('\\', '/', (string) ($row[Category::schema_fields_PATH] ?? '')), '/');
                if ($path === '') {
                    continue;
                }
                $loc = $base . '/category/' . $path;
                $urls[$loc] ??= [
                    'url_key' => 'category:' . $categoryId . ':store:' . $store->id,
                    'loc' => $loc,
                    'lastmod' => date('Y-m-d'),
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                    'entity_type' => 'category',
                    'entity_id' => $categoryId,
                    'metadata' => [
                        'page_type' => 'category',
                        'path' => $path,
                        'source' => 'Weline_Product',
                    ],
                ];
            }
        }

        return array_values($urls);
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && (string) (parse_url($url, PHP_URL_HOST) ?? '') !== '';
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme . '://' . strtolower((string) $parts['host']) . ':' . $port;
    }
}
