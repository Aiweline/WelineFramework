<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontSeoListingFacts;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Supplies listing/category SEO facts for public Product catalog surfaces.
 *
 * Merchant-authored title/description values are preserved. ItemList and
 * breadcrumbs are derived from storefront payloads, never hardcoded HTML.
 */
final class CatalogSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly StorefrontCatalogSurfaceResolver $surfaces = new StorefrontCatalogSurfaceResolver(),
        private readonly StorefrontSeoListingFacts $listingFacts = new StorefrontSeoListingFacts(),
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));
        if ($slot !== '' && $slot !== 'head') {
            return [];
        }

        $pageType = strtolower(str_replace(['-', ' '], '_', trim((string)($context['page_type'] ?? ''))));
        if ($pageType === 'product') {
            // Product variants belong in Product/ProductGroup — never ItemList from offers.
            return [];
        }

        $url = trim((string)($context['canonical_url'] ?? ''));
        if ($url === '') {
            $url = trim((string)($context['url'] ?? ''));
        }

        $leafCategory = $this->isLeafCategoryContext($template, $context, $url);
        $surface = $leafCategory
            ? null
            : $this->surfaces->resolveSupported($url, trim((string)($context['locale'] ?? '')));

        if ($surface === null && !$leafCategory) {
            return [];
        }

        $profile = [];
        if ($surface !== null) {
            $title = trim((string)($context['title'] ?? ''));
            if ($this->isSystemDefaultTitle($title, $surface)) {
                $profile['title'] = $surface['seo_title'];
            }

            $description = trim((string)($context['description'] ?? ''));
            if ($this->isSystemDefaultDescription($description, $title, $context, $surface)) {
                $profile['description'] = $surface['seo_description'];
            }

            $pageType = trim((string)($context['page_type'] ?? ''));
            if ($pageType === '' || in_array(strtolower($pageType), ['web_page', 'webpage', 'web-page', 'best_sellers'], true)) {
                $profile['page_type'] = $surface['page_type'];
            }

            if (trim((string)($context['keywords'] ?? '')) === '' && trim((string)($surface['seo_keywords'] ?? '')) !== '') {
                $profile['keywords'] = $surface['seo_keywords'];
            }
            if (trim((string)($context['image'] ?? '')) === '' && trim((string)($surface['share_image'] ?? '')) !== '') {
                $profile['image'] = $surface['share_image'];
                if (trim((string)($context['image_alt'] ?? '')) === '' && trim((string)($surface['share_image_alt'] ?? '')) !== '') {
                    $profile['image_alt'] = $surface['share_image_alt'];
                }
            }
        } else {
            $profile['page_type'] = 'category';
            $category = $this->categoryPayload($template, $context);
            $categoryName = trim((string)($category['name'] ?? $category['title'] ?? ''));
            $title = trim((string)($context['title'] ?? ''));
            if ($categoryName !== '' && $this->isGenericCategoryTitle($title)) {
                $profile['title'] = $categoryName;
            }
            $description = trim((string)($context['description'] ?? ''));
            $metaDescription = trim((string)($category['meta_description'] ?? $category['description'] ?? $category['summary'] ?? ''));
            if ($metaDescription !== '' && $this->isGenericCategoryDescription($description, $title, $context)) {
                $profile['description'] = $this->enrichCategoryDescription($metaDescription, $categoryName, $context);
            } elseif (
                $categoryName !== ''
                && ($description === '' || $this->isGenericCategoryDescription($description, $title, $context) || mb_strlen($description) < 80)
            ) {
                $profile['description'] = $this->enrichCategoryDescription($description, $categoryName, $context);
            }
            $image = trim((string)($category['image'] ?? $category['banner'] ?? ''));
            if ($image !== '' && trim((string)($context['image'] ?? '')) === '') {
                $profile['image'] = $image;
                if (trim((string)($context['image_alt'] ?? '')) === '') {
                    $profile['image_alt'] = $categoryName !== '' ? $categoryName : $title;
                }
            }
        }

        if (empty($context['item_list']) || !is_array($context['item_list']) || $context['item_list'] === []) {
            $itemList = $this->resolveItemList($template, $context);
            if ($itemList !== []) {
                $profile['item_list'] = $itemList;
            }
        }

        if (empty($context['breadcrumbs']) || !is_array($context['breadcrumbs']) || $context['breadcrumbs'] === []) {
            $breadcrumbs = $this->resolveBreadcrumbs($template, $context, $surface, $leafCategory);
            if ($breadcrumbs !== []) {
                $profile['breadcrumbs'] = $breadcrumbs;
            }
        }

        if (
            $surface !== null
            && ($surface['code'] ?? '') === 'new_arrivals'
            && (empty($context['feeds']) || !is_array($context['feeds']) || $context['feeds'] === [])
        ) {
            $profile['feeds'] = [[
                'type' => 'application/rss+xml',
                'title' => (string)__('新品 RSS'),
                'href' => '/new-arrivals/rss.xml',
            ]];
        }

        // Align with Product/home: listing surfaces always expose sitelinks SearchAction.
        $profile['site_search_enabled'] = true;

        return $profile;
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     */
    private function isLeafCategoryContext($template, array $context, string $url): bool
    {
        $pageType = strtolower(trim((string)($context['page_type'] ?? '')));
        if ($pageType === 'category' && $this->categoryPayload($template, $context) !== []) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
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
                continue;
            }
            $nonLocale[] = strtolower(str_replace('_', '-', $segment));
        }

        return ($nonLocale[0] ?? '') === 'category' && count($nonLocale) > 1;
    }

    private function isCurrencySegment(string $segment): bool
    {
        return (bool) preg_match('/^[A-Z]{3}$/', trim($segment));
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function categoryPayload($template, array $context): array
    {
        if (is_array($context['category'] ?? null) && $context['category'] !== []) {
            return $context['category'];
        }
        if (is_array($context['storefront_category'] ?? null) && $context['storefront_category'] !== []) {
            return $context['storefront_category'];
        }
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return [];
        }
        foreach (['category', 'storefront_category'] as $key) {
            $value = $template->getData($key);
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return list<array{name:string,url:string,image?:string,description?:string}>
     */
    private function resolveItemList($template, array $context): array
    {
        foreach (['item_list', 'storefront_offers', 'storefront_best_sellers', 'storefront_new_arrivals'] as $key) {
            if (is_array($context[$key] ?? null) && $context[$key] !== []) {
                return $this->listingFacts->itemListFromOffers($context[$key]);
            }
        }
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return [];
        }
        foreach (['item_list', 'storefront_offers', 'storefront_best_sellers', 'storefront_new_arrivals'] as $key) {
            $value = $template->getData($key);
            if (is_array($value) && $value !== []) {
                return $this->listingFacts->itemListFromOffers($value);
            }
        }

        return [];
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @param array<string, string>|null $surface
     * @return list<array{name:string,url:string}>
     */
    private function resolveBreadcrumbs($template, array $context, ?array $surface, bool $leafCategory): array
    {
        $raw = [];
        if (is_array($context['breadcrumbs'] ?? null)) {
            $raw = $context['breadcrumbs'];
        } elseif (is_array($context['storefront_category_breadcrumbs'] ?? null)) {
            $raw = $context['storefront_category_breadcrumbs'];
        } elseif (is_object($template) && method_exists($template, 'getData')) {
            foreach (['breadcrumbs', 'storefront_category_breadcrumbs'] as $key) {
                $value = $template->getData($key);
                if (is_array($value) && $value !== []) {
                    $raw = $value;
                    break;
                }
            }
        }

        $trail = $this->listingFacts->normalizeBreadcrumbs($raw);
        if ($trail !== []) {
            return $this->listingFacts->withHomeBreadcrumb($trail);
        }

        if ($surface !== null) {
            return $this->listingFacts->withHomeBreadcrumb([
                [
                    'name' => (string)($surface['title'] ?: $surface['heading'] ?: $surface['seo_title']),
                    'url' => '/' . ltrim((string)$surface['public_route'], '/'),
                ],
            ]);
        }

        if ($leafCategory) {
            $category = $this->categoryPayload($template, $context);
            $name = trim((string)($category['name'] ?? $category['title'] ?? ''));
            if ($name !== '') {
                return $this->listingFacts->withHomeBreadcrumb([
                    ['name' => $name, 'url' => trim((string)($context['canonical_url'] ?? $context['url'] ?? ''))],
                ]);
            }
        }

        return [];
    }

    /**
     * @param array<string, string> $surface
     */
    private function isSystemDefaultTitle(string $title, array $surface): bool
    {
        return in_array($title, [
            '',
            $surface['title'],
            $surface['heading'],
            $surface['seo_title'],
            '商品',
            '商品列表',
            '全部商品',
            'Products',
            'All products',
            'All products.',
            '分类',
            'Categories',
            '热销榜',
            'Best Sellers',
            '新品上架',
            'New Arrivals',
            '商品列表页布局',
            '商品列表 | 商品列表页布局',
            '分类页布局',
            '分类 | 分类页布局',
            '分类页面布局',
            '分类 | 分类页面布局',
            '热销榜布局',
            '热销榜 | 热销榜布局',
        ], true);
    }

    private function isGenericCategoryTitle(string $title): bool
    {
        return in_array($title, [
            '',
            '分类',
            'Categories',
            '分类页布局',
            '分类 | 分类页布局',
            '分类页面布局',
            '分类 | 分类页面布局',
            '汉服分类 | 按朝代、形制与场景选购',
            'Hanfu Categories | Shop by Dynasty & Style',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $surface
     */
    private function isSystemDefaultDescription(
        string $description,
        string $title,
        array $context,
        array $surface,
    ): bool {
        if (in_array($description, [
            '',
            $surface['lede'],
            $surface['seo_description'],
            '商品列表 | 商品列表页布局 - Weline Framework',
            '分类 | 分类页布局 - Weline Framework',
            '分类页面布局 - Weline Framework',
            '分类 | 分类页面布局 - Weline Framework',
            '热销榜 | 热销榜布局 - Weline Framework',
        ], true)) {
            return true;
        }

        return $this->isGenericCategoryDescription($description, $title, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isGenericCategoryDescription(string $description, string $title, array $context): bool
    {
        $siteName = trim((string)($context['site_name'] ?? ''));

        return $title !== ''
            && $siteName !== ''
            && $description === $title . ' - ' . $siteName;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function enrichCategoryDescription(string $seed, string $categoryName, array $context): string
    {
        $seed = trim($seed);
        $name = trim($categoryName) !== '' ? trim($categoryName) : trim((string) ($context['title'] ?? ''));
        $siteName = trim((string) ($context['site_name'] ?? '云裳汉服'));
        if ($seed === '' && $name !== '') {
            $seed = $name . '精选汉服与配饰，覆盖形制说明、面料要点与搭配场景，助你更快选到合身得体的款式。';
        }
        if (mb_strlen($seed) >= 90) {
            return $seed;
        }
        $suffix = '浏览' . ($name !== '' ? $name : '本分类') . '商品，了解尺码与面料要点，由'
            . ($siteName !== '' ? $siteName : '本店')
            . '提供可靠选购参考。';
        $combined = trim($seed . ($seed !== '' ? ' ' : '') . $suffix);

        return mb_substr($combined, 0, 320);
    }
}
