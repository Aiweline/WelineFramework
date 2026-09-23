<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Theme-owned public marketing/static routes that are not product/CMS entities.
 */
final class StorefrontStaticSitemapUrlProvider implements SitemapUrlProviderInterface
{
    /** @var list<array{path:string,priority:string,changefreq:string,page_type:string}> */
    private const ROUTES = [
        ['path' => 'about', 'priority' => '0.6', 'changefreq' => 'monthly', 'page_type' => 'about'],
        ['path' => 'products', 'priority' => '0.8', 'changefreq' => 'daily', 'page_type' => 'products'],
        ['path' => 'categories', 'priority' => '0.7', 'changefreq' => 'weekly', 'page_type' => 'category_index'],
        ['path' => 'best-sellers', 'priority' => '0.7', 'changefreq' => 'daily', 'page_type' => 'best_sellers'],
        ['path' => 'new-arrivals', 'priority' => '0.7', 'changefreq' => 'daily', 'page_type' => 'new_arrivals'],
        // 政策/法律公开壳（与 Policy 布局白名单对齐；排除无稳定公网语义的 default）
        ['path' => 'policy/privacy', 'priority' => '0.5', 'changefreq' => 'yearly', 'page_type' => 'policy'],
        ['path' => 'policy/cookie', 'priority' => '0.5', 'changefreq' => 'yearly', 'page_type' => 'policy'],
        ['path' => 'policy/term-condition', 'priority' => '0.5', 'changefreq' => 'yearly', 'page_type' => 'policy'],
        ['path' => 'policy/refund', 'priority' => '0.5', 'changefreq' => 'yearly', 'page_type' => 'policy'],
        ['path' => 'policy/disclaimer', 'priority' => '0.5', 'changefreq' => 'yearly', 'page_type' => 'policy'],
        ['path' => 'policy/shipping', 'priority' => '0.5', 'changefreq' => 'yearly', 'page_type' => 'policy'],
        ['path' => 'policy/accessibility', 'priority' => '0.5', 'changefreq' => 'yearly', 'page_type' => 'policy'],
    ];

    public function __construct(
        private readonly WebsiteCatalogInterface $websites,
    ) {
    }

    public function getScope(): string
    {
        return 'storefront_static';
    }

    public function getModule(): string
    {
        return 'Weline_Theme';
    }

    public function getWebsiteIds(): array
    {
        return array_values(array_unique(array_map(
            static fn ($website): int => $website->id,
            $this->websites->all(),
        )));
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        $base = '';
        foreach ($this->websites->all() as $website) {
            if ($website->id === $websiteId) {
                $base = rtrim(trim((string) $website->url), '/');
                break;
            }
        }
        if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
            return [];
        }

        $urls = [];
        foreach (self::ROUTES as $route) {
            $loc = $base . '/' . ltrim($route['path'], '/');
            $urls[] = [
                'url_key' => 'theme-static:' . $route['path'],
                'loc' => $loc,
                'lastmod' => date('Y-m-d'),
                'changefreq' => $route['changefreq'],
                'priority' => $route['priority'],
                'entity_type' => 'storefront_static',
                'entity_id' => 0,
                'metadata' => [
                    'page_type' => $route['page_type'],
                    'source' => 'Weline_Theme',
                ],
            ];
        }

        return $urls;
    }

    public function getDescription(): string
    {
        return (string) __('主题店面静态页（about/products 等）');
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
