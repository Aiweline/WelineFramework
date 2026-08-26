<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\ThemeDemoCatalog;

class ThemeDemoCatalogTest extends TestCase
{
    public function testProductsReturnVisualDefaults(): void
    {
        $products = ThemeDemoCatalog::products(4, 2);

        self::assertCount(4, $products);
        self::assertStringEndsWith('/images/storefront-placeholder/default.svg', $products[0]['image']);
        self::assertStringNotContainsString('data:image/', $products[0]['image']);
        self::assertSame($products[0]['image'], ThemeDemoCatalog::productImage(99));
        self::assertNotSame('', $products[0]['name']);
        self::assertGreaterThan(0, $products[0]['price']);
    }

    public function testFormatPrice(): void
    {
        self::assertSame('¥128.00', ThemeDemoCatalog::formatPrice(128));
    }
}
