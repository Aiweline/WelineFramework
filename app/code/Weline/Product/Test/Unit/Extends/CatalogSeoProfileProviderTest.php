<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Seo\SeoProfileProvider\CatalogSeoProfileProvider;

final class CatalogSeoProfileProviderTest extends TestCase
{
    private CatalogSeoProfileProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new CatalogSeoProfileProvider();
    }

    public function testChineseCategoryDefaultsAreDistinctFromProducts(): void
    {
        $profile = $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'canonical_url' => 'https://shop.example/zh_Hans_CN/categories',
            'title' => '商品列表 | 商品列表页布局',
            'description' => '商品列表 | 商品列表页布局 - Weline Framework',
        ]);

        self::assertSame('汉服分类 | 按朝代、形制与场景选购', $profile['title']);
        self::assertStringContainsString('明制、宋制、唐制', $profile['description']);
        self::assertSame('category', $profile['page_type']);
    }

    public function testArabicBestSellersUseReviewedEnglishSeoFallback(): void
    {
        $profile = $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'canonical_url' => 'https://shop.example/ar_SA/best-sellers',
            'title' => '热销榜 | 热销榜布局',
            'description' => '热销榜 | 热销榜布局 - Weline Framework',
        ]);

        self::assertSame('Best-Selling Hanfu', $profile['title']);
        self::assertStringContainsString('best-selling Hanfu', $profile['description']);
        self::assertSame('products', $profile['page_type']);
    }

    public function testLegacyCategoryPageLayoutDefaultsAreLocalized(): void
    {
        $profile = $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'canonical_url' => 'https://shop.example/en_US/categories',
            'site_name' => 'Weline Framework',
            'title' => '分类 | 分类页面布局',
            'description' => '分类 | 分类页面布局 - Weline Framework',
        ]);

        self::assertSame(
            'Hanfu Categories | Shop by Dynasty & Style',
            $profile['title'],
        );
        self::assertSame(
            'Explore Ming, Song, and Tang dynasty Hanfu, mamian skirts, and accessories by style and occasion.',
            $profile['description'],
        );
        self::assertSame('category', $profile['page_type']);
    }

    public function testMerchantAuthoredMetadataIsPreserved(): void
    {
        $profile = $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'canonical_url' => 'https://shop.example/en_US/products',
            'title' => 'Autumn Hanfu Collection',
            'description' => 'A hand-curated seasonal edit.',
        ]);

        self::assertSame('products', $profile['page_type']);
        self::assertArrayNotHasKey('title', $profile);
        self::assertArrayNotHasKey('description', $profile);
    }

    public function testProductDetailAndNonHeadSlotsAreIgnored(): void
    {
        self::assertSame([], $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'canonical_url' => 'https://shop.example/en_US/product/example',
        ]));
        self::assertSame([], $this->provider->provideSeoProfile(null, [
            '_slot' => 'body',
            'canonical_url' => 'https://shop.example/en_US/products',
        ]));
    }

    public function testLeafCategoryBuildsItemListAndBreadcrumbsFromStorefrontFacts(): void
    {
        $profile = $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'page_type' => 'category',
            'canonical_url' => 'https://shop.example/category/women/mamian',
            'title' => '分类',
            'description' => '分类 - Weline Framework',
            'site_name' => 'Weline Framework',
            'category' => [
                'name' => '马面裙',
                'meta_description' => '精选马面裙',
            ],
            'storefront_offers' => [
                ['name' => '红马面', 'url' => '/product/100', 'image' => '/a.jpg'],
            ],
            'storefront_category_breadcrumbs' => [
                ['label' => '女装', 'url' => '/category/women'],
                ['label' => '马面裙', 'url' => '/category/women/mamian'],
            ],
        ]);

        self::assertSame('category', $profile['page_type']);
        self::assertSame('马面裙', $profile['title']);
        self::assertSame('精选马面裙', $profile['description']);
        self::assertSame('红马面', $profile['item_list'][0]['name']);
        self::assertSame('/', $profile['breadcrumbs'][0]['url']);
        self::assertSame('马面裙', $profile['breadcrumbs'][2]['name']);
    }
}
