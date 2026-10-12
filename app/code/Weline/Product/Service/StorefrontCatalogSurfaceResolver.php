<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Runtime\RequestContext;

/**
 * Resolves the public catalog surface before the Product controller rewrites
 * the request into its shared listing action.
 *
 * Brand / website copy lives in design-theme {@code data/catalog-surfaces.php};
 * this class keeps route skeleton + neutral ecommerce fallback only.
 */
final class StorefrontCatalogSurfaceResolver
{
    /**
     * Neutral default share image (empty = omit brand asset).
     * Theme packs supply brand images via {@see StorefrontCatalogSurfaceThemeCopyLoader}.
     */
    public const SHARE_IMAGE = '';

    /**
     * Route skeleton + neutral ecommerce copy (no website / design-theme brand).
     *
     * @var array<string, array{
     *     page_type:string,
     *     layout_type:string,
     *     public_route:string,
     *     zh:array{title:string,heading:string,lede:string,seo_title:string,seo_description:string,seo_keywords:string,share_image_alt:string},
     *     en:array{title:string,heading:string,lede:string,seo_title:string,seo_description:string,seo_keywords:string,share_image_alt:string}
     * }>
     */
    private const SURFACES = [
        'products' => [
            'page_type' => 'products',
            'layout_type' => 'products',
            'public_route' => 'products',
            'zh' => [
                'title' => '商品列表',
                'heading' => '全部商品',
                'lede' => '浏览当前网站已发布的商品，可按价格与名称排序。',
                'seo_title' => '商品列表',
                'seo_description' => '浏览当前网站已发布的商品，支持多语言店面浏览。',
                'seo_keywords' => '商品,购物,商城',
                'share_image_alt' => '商品列表',
            ],
            'en' => [
                'title' => 'Shop',
                'heading' => 'Shop All Products',
                'lede' => 'Browse published products with price and name sorting.',
                'seo_title' => 'Shop All Products',
                'seo_description' => 'Browse published products with international storefront support.',
                'seo_keywords' => 'shop,products,store',
                'share_image_alt' => 'Product listing',
            ],
        ],
        'categories' => [
            'page_type' => 'category',
            'layout_type' => 'category',
            'public_route' => 'categories',
            'zh' => [
                'title' => '商品分类',
                'heading' => '按分类探索',
                'lede' => '从店铺分类中找到适合的商品。',
                'seo_title' => '商品分类',
                'seo_description' => '按分类浏览当前网站已发布的商品。',
                'seo_keywords' => '分类,商品分类',
                'share_image_alt' => '商品分类',
            ],
            'en' => [
                'title' => 'Categories',
                'heading' => 'Explore by Category',
                'lede' => 'Browse store categories to find what you need.',
                'seo_title' => 'Categories',
                'seo_description' => 'Explore published products by category.',
                'seo_keywords' => 'categories,shop categories',
                'share_image_alt' => 'Product categories',
            ],
        ],
        'new_arrivals' => [
            'page_type' => 'products',
            'layout_type' => 'products',
            'public_route' => 'new-arrivals',
            'zh' => [
                'title' => '新品上架',
                'heading' => '新品上架',
                'lede' => '按上架时间探索最新商品。',
                'seo_title' => '新品上架',
                'seo_description' => '探索最新上架的商品。',
                'seo_keywords' => '新品,上新',
                'share_image_alt' => '新品上架',
            ],
            'en' => [
                'title' => 'New Arrivals',
                'heading' => 'New Arrivals',
                'lede' => 'Explore the latest products in arrival order.',
                'seo_title' => 'New Arrivals',
                'seo_description' => 'Discover the latest products.',
                'seo_keywords' => 'new arrivals,new products',
                'share_image_alt' => 'New arrivals',
            ],
        ],
        'best_sellers' => [
            'page_type' => 'products',
            'layout_type' => 'products',
            'public_route' => 'best-sellers',
            'zh' => [
                'title' => '热销榜',
                'heading' => '热销榜',
                'lede' => '查看当前最受欢迎的商品。',
                'seo_title' => '热销榜',
                'seo_description' => '探索热销商品。',
                'seo_keywords' => '热销,人气商品',
                'share_image_alt' => '热销榜',
            ],
            'en' => [
                'title' => 'Best Sellers',
                'heading' => 'Best Sellers',
                'lede' => 'Discover the products customers love most.',
                'seo_title' => 'Best Sellers',
                'seo_description' => 'Discover best-selling products.',
                'seo_keywords' => 'best sellers,popular products',
                'share_image_alt' => 'Best sellers',
            ],
        ],
    ];

    public function __construct(
        private readonly StorefrontCatalogSurfaceThemeCopyLoader $themeCopyLoader = new StorefrontCatalogSurfaceThemeCopyLoader(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $requestUri, string $locale = '', string $websiteCode = ''): array
    {
        return $this->resolveSupported($requestUri, $locale, $websiteCode)
            ?? $this->localizedSurface('products', $locale, $websiteCode);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveSupported(string $requestUri, string $locale = '', string $websiteCode = ''): ?array
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $path = \Weline\Framework\Http\Url::peelWebsiteMountPathFromRelativePath($path);
        $segments = array_values(array_filter(
            explode('/', trim(rawurldecode($path), '/')),
            static fn(string $segment): bool => $segment !== '',
        ));
        $nonLocale = [];
        foreach ($segments as $segment) {
            if ($this->isCurrencySegment($segment)) {
                continue;
            }
            if (preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4}){1,2}$/i', $segment) === 1) {
                if ($locale === '') {
                    $locale = $segment;
                }
                continue;
            }
            $nonLocale[] = strtolower(str_replace('_', '-', trim($segment)));
        }
        if ($nonLocale === []) {
            return null;
        }

        $first = $nonLocale[0];
        $rest = array_slice($nonLocale, 1);
        $surfaceCode = match ($first) {
            'products' => 'products',
            'categories' => 'categories',
            // `/category` alone is the index surface; `/category/{slug…}` is a leaf entity page.
            'category' => $rest === [] ? 'categories' : null,
            'best-sellers', 'bestsellers' => 'best_sellers',
            'new-arrivals', 'newarrivals' => 'new_arrivals',
            default => null,
        };
        if ($surfaceCode === null) {
            return null;
        }

        return $this->localizedSurface($surfaceCode, $locale, $websiteCode);
    }

    /**
     * True when the active/bound theme pack (or websiteCode design folder) provides surfaces.
     */
    public function hasWebsiteCopy(string $websiteCode = ''): bool
    {
        return $this->themeCopyLoader->hasSurfaces($this->normalizeWebsiteCode($websiteCode));
    }

    /**
     * @return array<string, mixed>
     */
    private function localizedSurface(string $surfaceCode, string $locale, string $websiteCode = ''): array
    {
        $surface = self::SURFACES[$surfaceCode];
        $websiteCode = $this->normalizeWebsiteCode($websiteCode);
        $themePack = $this->themeCopyLoader->load($websiteCode);
        $themeSurfaces = is_array($themePack['surfaces'] ?? null) ? $themePack['surfaces'] : [];
        $copyBucket = $surface;
        if (isset($themeSurfaces[$surfaceCode]) && is_array($themeSurfaces[$surfaceCode])) {
            $copyBucket = array_merge($surface, $themeSurfaces[$surfaceCode]);
        }

        $normalizedLocale = strtolower(str_replace('-', '_', trim($locale)));
        $copy = $normalizedLocale === '' || str_starts_with($normalizedLocale, 'zh')
            ? ($copyBucket['zh'] ?? $surface['zh'])
            : ($copyBucket['en'] ?? $surface['en']);

        $shareImage = array_key_exists('share_image', $themePack)
            ? (string)$themePack['share_image']
            : self::SHARE_IMAGE;

        $presentationAll = is_array($themePack['presentation'] ?? null) ? $themePack['presentation'] : [];
        $presentation = is_array($presentationAll[$surfaceCode] ?? null)
            ? $presentationAll[$surfaceCode]
            : [];

        return [
            'code' => $surfaceCode,
            'page_type' => $surface['page_type'],
            'layout_type' => $surface['layout_type'],
            'public_route' => $surface['public_route'],
            'locale' => trim($locale),
            'title' => (string)($copy['title'] ?? ''),
            'heading' => (string)($copy['heading'] ?? ''),
            'lede' => (string)($copy['lede'] ?? ''),
            'seo_title' => (string)($copy['seo_title'] ?? ''),
            'seo_description' => (string)($copy['seo_description'] ?? ''),
            'seo_keywords' => (string)($copy['seo_keywords'] ?? ''),
            'share_image' => $shareImage,
            'share_image_alt' => (string)($copy['share_image_alt'] ?? ''),
            'presentation' => $presentation,
        ];
    }

    private function normalizeWebsiteCode(string $websiteCode): string
    {
        $code = strtolower(trim($websiteCode));
        if ($code !== '') {
            return $code;
        }

        try {
            return strtolower(trim((string)RequestContext::getWelineWebsiteCode()));
        } catch (\Throwable) {
            return '';
        }
    }

    private function isCurrencySegment(string $segment): bool
    {
        // Storefront currency prefixes are always ISO-4217 uppercase (EUR/USD/CNY).
        return (bool) preg_match('/^[A-Z]{3}$/', trim($segment));
    }
}
