<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Supplies localized defaults for public Product listing surfaces.
 *
 * Unknown title/description values are treated as merchant-authored and are
 * deliberately preserved.
 */
final class CatalogSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly StorefrontCatalogSurfaceResolver $surfaces = new StorefrontCatalogSurfaceResolver(),
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

        $url = trim((string)($context['canonical_url'] ?? ''));
        if ($url === '') {
            $url = trim((string)($context['url'] ?? ''));
        }
        $surface = $this->surfaces->resolveSupported(
            $url,
            trim((string)($context['locale'] ?? '')),
        );
        if ($surface === null) {
            return [];
        }

        $profile = [];
        $title = trim((string)($context['title'] ?? ''));
        if ($this->isSystemDefaultTitle($title, $surface)) {
            $profile['title'] = $surface['seo_title'];
        }

        $description = trim((string)($context['description'] ?? ''));
        if ($this->isSystemDefaultDescription($description, $title, $context, $surface)) {
            $profile['description'] = $surface['seo_description'];
        }

        return $profile;
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

        $siteName = trim((string)($context['site_name'] ?? ''));

        return $title !== ''
            && $siteName !== ''
            && $description === $title . ' - ' . $siteName;
    }
}
