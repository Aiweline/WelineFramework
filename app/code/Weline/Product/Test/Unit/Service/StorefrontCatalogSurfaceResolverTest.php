<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontCatalogSurfaceThemeCopyLoader;

final class StorefrontCatalogSurfaceResolverTest extends TestCase
{
    private StorefrontCatalogSurfaceResolver $resolver;

    /** Neutral-only resolver: never loads design theme packs. */
    private StorefrontCatalogSurfaceResolver $neutralResolver;

    protected function setUp(): void
    {
        $this->resolver = new StorefrontCatalogSurfaceResolver();
        $this->neutralResolver = new StorefrontCatalogSurfaceResolver(
            new StorefrontCatalogSurfaceThemeCopyLoader(static fn (): ?string => null),
        );
    }

    public function testProductsKeepTheProductListSurface(): void
    {
        $surface = $this->neutralResolver->resolve('/products?sort=price_asc', 'zh_Hans_CN');

        self::assertSame('products', $surface['code']);
        self::assertSame('products', $surface['page_type']);
        self::assertSame('products', $surface['public_route']);
        self::assertSame('全部商品', $surface['heading']);
        self::assertStringNotContainsString('汉服', (string)$surface['lede']);
    }

    public function testLocalePrefixedCategoriesUseCategorySurfaceAndEnglishCopy(): void
    {
        $surface = $this->resolver->resolve('/en_US/categories?sort=name_asc', '', 'hanfu');

        self::assertSame('categories', $surface['code']);
        self::assertSame('category', $surface['page_type']);
        self::assertSame('categories', $surface['public_route']);
        self::assertSame('Explore Hanfu by Style', $surface['heading']);
    }

    public function testArabicBestSellersUseReviewedEnglishFallbackCopy(): void
    {
        $surface = $this->resolver->resolve('/ar_SA/best-sellers', '', 'hanfu');

        self::assertSame('best_sellers', $surface['code']);
        self::assertSame('best-sellers', $surface['public_route']);
        self::assertSame('Most-Loved Hanfu', $surface['heading']);
        self::assertSame('Best-Selling Hanfu', $surface['seo_title']);
    }

    public function testCurrencyAndLocalePrefixedProductsResolve(): void
    {
        $surface = $this->resolver->resolveSupported('/EUR/en_US/products', '', 'hanfu');

        self::assertNotNull($surface);
        self::assertSame('products', $surface['code']);
        self::assertSame('products', $surface['page_type']);
        self::assertSame('en_US', $surface['locale']);
        self::assertSame('Shop All Hanfu | Ming & Tang', $surface['seo_title']);
        self::assertNotSame('', $surface['seo_keywords'] ?? '');
        self::assertSame(
            '/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp',
            $surface['share_image'] ?? null,
        );
    }

    public function testUnsupportedRoutesDoNotLeakCatalogSeo(): void
    {
        self::assertNull($this->resolver->resolveSupported('/en_US/product/hanfu-example'));
        self::assertNull($this->resolver->resolveSupported('/EUR/en_US/product/hanfu-example'));
    }

    public function testLeafCategoryPathsAreNotIndexSurfaces(): void
    {
        self::assertNull($this->resolver->resolveSupported('/category/women/mamian'));
        self::assertNull($this->resolver->resolveSupported('/en_US/category/women'));
        self::assertSame('categories', $this->resolver->resolveSupported('/categories')['code'] ?? null);
        self::assertSame('categories', $this->resolver->resolveSupported('/category')['code'] ?? null);
    }

    public function testMountedCategoryIndexesUseTheConfiguredMountInsteadOfWebsiteCode(): void
    {
        WelineEnv::set('website_url', 'https://example.test/e2e-mso3wfcu-1', 'catalog mount test');
        try {
            foreach (['/category', '/categories', '/EUR/en_US/categories'] as $route) {
                $surface = $this->resolver->resolve('/e2e-mso3wfcu-1' . $route, 'en_US', 'e2e-theme-default');
                self::assertSame('categories', $surface['code']);
                self::assertSame('category', $surface['layout_type']);
            }
            self::assertNull($this->resolver->resolveSupported('/e2e-mso3wfcu-1/category/women'));
            self::assertNull($this->resolver->resolveSupported('/e2e-mso3wfcu-10/categories'));
        } finally {
            WelineEnv::getInstance()->reset();
        }
    }

    public function testDaocharmsWebsiteUsesRitualObjectsCopyWithoutHanfuShareImage(): void
    {
        $surface = $this->resolver->resolve('/products', 'en_US', 'daocharms');

        self::assertSame('products', $surface['code']);
        self::assertSame('Objects for a quieter everyday.', $surface['heading']);
        self::assertSame('Objects for a quieter everyday. | DaoCharms', $surface['seo_title']);
        self::assertStringNotContainsString('Hanfu', $surface['heading']);
        self::assertStringNotContainsString('Hanfu', $surface['seo_title']);
        self::assertSame('', $surface['share_image'] ?? null);
    }

    public function testDaocharmsNewArrivalsPresentationComesFromThemePack(): void
    {
        $surface = $this->resolver->resolve('/new-arrivals', 'en_US', 'daocharms');
        $presentation = is_array($surface['presentation'] ?? null) ? $surface['presentation'] : [];

        self::assertSame(3, $presentation['card_limit'] ?? null);
        self::assertFalse((bool)($presentation['show_filters'] ?? true));
        self::assertSame('New to your everyday.', $surface['heading']);
    }

    public function testGroceryWebsiteUsesNeighborhoodShelfCopyWithoutHanfuShareImage(): void
    {
        $surface = $this->resolver->resolve('/products', 'zh_Hans_CN', 'grocery');

        self::assertSame('products', $surface['code']);
        self::assertSame('全部货架', $surface['heading']);
        self::assertSame('邻里货架 | 生鲜日用杂货', $surface['seo_title']);
        self::assertStringNotContainsString('汉服', $surface['heading']);
        self::assertStringNotContainsString('汉服', $surface['seo_title']);
        self::assertStringNotContainsString('马面', $surface['seo_description']);
        self::assertSame('', $surface['share_image'] ?? null);
    }

    public function testEmptyWebsiteWithoutThemePackUsesNeutralCopy(): void
    {
        $surface = $this->neutralResolver->resolve('/products', 'en_US', '');

        self::assertSame('Shop All Products', $surface['heading']);
        self::assertSame('', $surface['share_image'] ?? null);
        self::assertStringNotContainsString('Hanfu', $surface['heading']);
    }

    public function testHanfuWebsiteCodeLoadsThemePackCopy(): void
    {
        $surface = $this->resolver->resolve('/products', 'en_US', 'hanfu');

        self::assertSame('Shop All Hanfu', $surface['heading']);
        self::assertSame(
            '/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp',
            $surface['share_image'] ?? null,
        );
    }

    public function testProductSourceForbidsWebsiteSurfaceConstants(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogSurfaceResolver.php'
        );
        self::assertStringNotContainsString('SURFACES_DAOCHARMS', $source);
        self::assertStringNotContainsString('SURFACES_GROCERY', $source);
        self::assertStringNotContainsString("websiteCode === 'daocharms'", $source);
        self::assertStringNotContainsString('SHARE_IMAGE_BY_WEBSITE', $source);
    }
}
