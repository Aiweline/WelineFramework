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
        self::assertStringStartsWith('data:image/svg+xml', $products[0]['image']);
        self::assertNotSame('', $products[0]['name']);
        self::assertGreaterThan(0, $products[0]['price']);
    }

    public function testFormatPrice(): void
    {
        self::assertSame('¥128.00', ThemeDemoCatalog::formatPrice(128));
    }
}
