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
        self::assertSame('product_list', $surface['page_type']);
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
        self::assertSame('Best-Selling Hanfu | Most-Loved Styles', $surface['seo_title']);
    }

    public function testUnsupportedRoutesDoNotLeakCatalogSeo(): void
    {
        self::assertNull($this->resolver->resolveSupported('/en_US/product/hanfu-example'));
    }
}
