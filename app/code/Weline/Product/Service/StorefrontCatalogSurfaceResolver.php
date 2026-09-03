<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Resolves the public catalog surface before the Product controller rewrites
 * the request into its shared listing action.
 */
final class StorefrontCatalogSurfaceResolver
{
    /**
     * @var array<string, array{
     *     page_type:string,
     *     layout_type:string,
     *     public_route:string,
     *     zh:array{title:string,heading:string,lede:string,seo_title:string,seo_description:string},
     *     en:array{title:string,heading:string,lede:string,seo_title:string,seo_description:string}
     * }>
     */
    private const SURFACES = [
        'products' => [
            'page_type' => 'product_list',
            'layout_type' => 'product_list',
            'public_route' => 'products',
            'zh' => [
                'title' => '商品列表',
                'heading' => '全部商品',
                'lede' => '浏览当前网站已发布的汉服、马面裙与传统配饰，可按价格与名称排序。',
                'seo_title' => '汉服商城 | 明制、宋制、唐制与马面裙',
                'seo_description' => '浏览云裳汉服全系列，选购明制、宋制、唐制汉服、马面裙与传统配饰，支持国际独立站多语言浏览。',
            ],
            'en' => [
                'title' => 'Hanfu Shop',
                'heading' => 'Shop All Hanfu',
                'lede' => 'Explore published Hanfu, mamian skirts, and traditional accessories, with price and name sorting.',
                'seo_title' => 'Shop Hanfu | Ming, Song & Tang Styles',
                'seo_description' => 'Shop Ming, Song, and Tang dynasty Hanfu, mamian skirts, and traditional accessories with international storefront support.',
            ],
        ],
        'categories' => [
            'page_type' => 'category',
            'layout_type' => 'category',
            'public_route' => 'categories',
            'zh' => [
                'title' => '汉服分类',
                'heading' => '按形制探索汉服',
                'lede' => '从明制、宋制、唐制、马面裙与传统配饰中，找到适合日常、节庆与礼仪的款式。',
                'seo_title' => '汉服分类 | 按朝代、形制与场景选购',
                'seo_description' => '按朝代、形制与穿着场景探索明制、宋制、唐制汉服、马面裙和传统配饰。',
            ],
            'en' => [
                'title' => 'Hanfu Categories',
                'heading' => 'Explore Hanfu by Style',
                'lede' => 'Browse Ming, Song, and Tang styles, mamian skirts, and accessories for everyday wear, festivals, and ceremonies.',
                'seo_title' => 'Hanfu Categories | Shop by Dynasty & Style',
                'seo_description' => 'Explore Ming, Song, and Tang dynasty Hanfu, mamian skirts, and accessories by style and occasion.',
            ],
        ],
        'new_arrivals' => [
            'page_type' => 'product_list',
            'layout_type' => 'product_list',
            'public_route' => 'new-arrivals',
            'zh' => [
                'title' => '新品上架',
                'heading' => '新到汉服',
                'lede' => '按上架时间探索最新汉服、马面裙与传统配饰，发现当季东方衣冠新作。',
                'seo_title' => '汉服新品 | 最新汉服、马面裙与传统配饰',
                'seo_description' => '探索云裳汉服新品上架，选购最新汉服、马面裙与传统配饰。',
            ],
            'en' => [
                'title' => 'New Arrivals',
                'heading' => 'New Hanfu Arrivals',
                'lede' => 'Explore the latest Hanfu, mamian skirts, and traditional accessories in arrival order.',
                'seo_title' => 'New Hanfu Arrivals',
                'seo_description' => 'Discover the latest Hanfu, mamian skirts, and traditional accessories from Yunshang Hanfu.',
            ],
        ],
        'best_sellers' => [
            'page_type' => 'product_list',
            'layout_type' => 'product_list',
            'public_route' => 'best-sellers',
            'zh' => [
                'title' => '热销榜',
                'heading' => '人气汉服榜',
                'lede' => '查看当前最受欢迎的汉服、马面裙与传统配饰，快速发现店铺口碑之选。',
                'seo_title' => '热销汉服榜 | 人气汉服与马面裙推荐',
                'seo_description' => '探索云裳汉服热销榜，发现人气汉服、马面裙与传统配饰。',
            ],
            'en' => [
                'title' => 'Best Sellers',
                'heading' => 'Most-Loved Hanfu',
                'lede' => 'Discover the Hanfu, mamian skirts, and traditional accessories our customers love most.',
                'seo_title' => 'Best-Selling Hanfu | Most-Loved Styles',
                'seo_description' => 'Discover best-selling Hanfu, mamian skirts, and traditional accessories from Yunshang Hanfu.',
            ],
        ],
    ];

    /**
     * @return array<string, string>
     */
    public function resolve(string $requestUri, string $locale = ''): array
    {
        return $this->resolveSupported($requestUri, $locale)
            ?? $this->localizedSurface('products', $locale);
    }

    /**
     * @return array<string, string>|null
     */
    public function resolveSupported(string $requestUri, string $locale = ''): ?array
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', trim(rawurldecode($path), '/')),
            static fn(string $segment): bool => $segment !== '',
        ));
        $surfaceCode = null;
        foreach ($segments as $segment) {
            $normalized = strtolower(str_replace('_', '-', trim($segment)));
            $candidate = match ($normalized) {
                'products', 'product-list' => 'products',
                'category', 'categories' => 'categories',
                'best-sellers', 'bestsellers' => 'best_sellers',
                'new-arrivals', 'newarrivals' => 'new_arrivals',
                default => null,
            };
            if ($candidate !== null) {
                $surfaceCode = $candidate;
            }
        }
        if ($surfaceCode === null) {
            return null;
        }

        foreach ($segments as $segment) {
            if (preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4}){1,2}$/i', $segment) === 1) {
                $locale = $segment;
                break;
            }
        }

        return $this->localizedSurface($surfaceCode, $locale);
    }

    /**
     * @return array<string, string>
     */
    private function localizedSurface(string $surfaceCode, string $locale): array
    {
        $surface = self::SURFACES[$surfaceCode];
        $normalizedLocale = strtolower(str_replace('-', '_', trim($locale)));
        $copy = $normalizedLocale === '' || str_starts_with($normalizedLocale, 'zh')
            ? $surface['zh']
            : $surface['en'];

        return [
            'code' => $surfaceCode,
            'page_type' => $surface['page_type'],
            'layout_type' => $surface['layout_type'],
            'public_route' => $surface['public_route'],
            'locale' => trim($locale),
            'title' => $copy['title'],
            'heading' => $copy['heading'],
            'lede' => $copy['lede'],
            'seo_title' => $copy['seo_title'],
            'seo_description' => $copy['seo_description'],
        ];
    }
}
