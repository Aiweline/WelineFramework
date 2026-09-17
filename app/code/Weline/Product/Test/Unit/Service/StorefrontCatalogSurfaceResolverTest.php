<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;

final class StorefrontCatalogSurfaceResolverTest extends TestCase
{
    private StorefrontCatalogSurfaceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new StorefrontCatalogSurfaceResolver();
    }

    public function testProductsKeepTheProductListSurface(): void
    {
        $surface = $this->resolver->resolve('/products?sort=price_asc', 'zh_Hans_CN');

        self::assertSame('products', $surface['code']);
        self::assertSame('products', $surface['page_type']);
        self::assertSame('products', $surface['public_route']);
        self::assertSame('全部商品', $surface['heading']);
    }

    public function testLocalePrefixedCategoriesUseCategorySurfaceAndEnglishCopy(): void
    {
        $surface = $this->resolver->resolve('/en_US/categories?sort=name_asc');

        self::assertSame('categories', $surface['code']);
        self::assertSame('category', $surface['page_type']);
        self::assertSame('categories', $surface['public_route']);
        self::assertSame('Explore Hanfu by Style', $surface['heading']);
    }

    public function testArabicBestSellersUseReviewedEnglishFallbackCopy(): void
    {
        $surface = $this->resolver->resolve('/ar_SA/best-sellers');

        self::assertSame('best_sellers', $surface['code']);
        self::assertSame('best-sellers', $surface['public_route']);
        self::assertSame('Most-Loved Hanfu', $surface['heading']);
        self::assertSame('Best-Selling Hanfu', $surface['seo_title']);
    }

    public function testCurrencyAndLocalePrefixedProductsResolve(): void
    {
        $surface = $this->resolver->resolveSupported('/EUR/en_US/products');

        self::assertNotNull($surface);
        self::assertSame('products', $surface['code']);
        self::assertSame('products', $surface['page_type']);
        self::assertSame('en_US', $surface['locale']);
        self::assertSame('Shop All Hanfu | Ming & Tang', $surface['seo_title']);
        self::assertNotSame('', $surface['seo_keywords'] ?? '');
        self::assertSame(StorefrontCatalogSurfaceResolver::SHARE_IMAGE, $surface['share_image'] ?? null);
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
}
