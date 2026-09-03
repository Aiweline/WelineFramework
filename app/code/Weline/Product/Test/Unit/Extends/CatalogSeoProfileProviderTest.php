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
    }

    public function testArabicBestSellersUseReviewedEnglishSeoFallback(): void
    {
        $profile = $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'canonical_url' => 'https://shop.example/ar_SA/best-sellers',
            'title' => '热销榜 | 热销榜布局',
            'description' => '热销榜 | 热销榜布局 - Weline Framework',
        ]);

        self::assertSame('Best-Selling Hanfu | Most-Loved Styles', $profile['title']);
        self::assertStringContainsString('best-selling Hanfu', $profile['description']);
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
    }

    public function testMerchantAuthoredMetadataIsPreserved(): void
    {
        $profile = $this->provider->provideSeoProfile(null, [
            '_slot' => 'head',
            'canonical_url' => 'https://shop.example/en_US/products',
            'title' => 'Autumn Hanfu Collection',
            'description' => 'A hand-curated seasonal edit.',
        ]);

        self::assertSame([], $profile);
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
}
