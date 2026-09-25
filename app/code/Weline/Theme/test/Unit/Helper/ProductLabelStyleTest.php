<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\ProductLabelStyle;
use Weline\Theme\Helper\ThemeUiColor;

final class ProductLabelStyleTest extends TestCase
{
    public function testScopeStyleAttributeOnlyIncludesNonEmptyOverrides(): void
    {
        $style = ProductLabelStyle::scopeStyleAttribute([
            'new_bg' => '#e6f2e6',
            'new_text' => '',
            'sale_bg' => 'var(--weline-theme-danger-surface)',
            'sale_text' => '#9f1239',
        ]);

        self::assertStringContainsString('--w-product-label-new-bg:#e6f2e6', $style);
        self::assertStringContainsString('--w-product-label-sale-bg:var(--weline-theme-danger-surface)', $style);
        self::assertStringContainsString('--w-product-label-sale-text:#9f1239', $style);
        self::assertStringNotContainsString('new-text', $style);
    }

    public function testScopeStyleAttributeIgnoresInvalidColors(): void
    {
        self::assertSame('', ProductLabelStyle::scopeStyleAttribute([
            'new_bg' => 'javascript:alert(1)',
            'sale_text' => '<script>',
        ]));
    }

    public function testGlobalTokens(): void
    {
        self::assertSame('var(--weline-product-label-new-bg)', ProductLabelStyle::globalBgToken(ProductLabelStyle::KIND_NEW));
        self::assertSame('var(--weline-product-label-sale-text)', ProductLabelStyle::globalTextToken(ProductLabelStyle::KIND_SALE));
        self::assertSame('var(--weline-product-label-demo-bg)', ProductLabelStyle::globalBgToken(ProductLabelStyle::KIND_DEMO));
        self::assertSame('var(--weline-product-label-demo-text)', ProductLabelStyle::globalTextToken(ProductLabelStyle::KIND_DEMO));
    }

    public function testResolveFlagsUsesProductDataWhenPresent(): void
    {
        $flags = ProductLabelStyle::resolveFlags(['is_new' => 1, 'is_sale' => 0, 'is_demo' => 1], 5);
        self::assertTrue($flags['is_new']);
        self::assertFalse($flags['is_sale']);
        self::assertTrue($flags['is_demo']);
        self::assertFalse(ProductLabelStyle::resolveFlags(['is_new' => 0, 'is_sale' => 0], 1)['is_demo']);
    }

    public function testResolveFlagsDoesNotFabricateByIndex(): void
    {
        $flags = ProductLabelStyle::resolveFlags([], 0);
        self::assertFalse($flags['is_new']);
        self::assertFalse($flags['is_sale']);
        self::assertFalse($flags['is_demo']);

        $flags = ProductLabelStyle::resolveFlags([], 3);
        self::assertFalse($flags['is_new']);
        self::assertFalse($flags['is_sale']);
    }

    public function testResolveFlagsDoesNotFabricateFreeShippingByPrice(): void
    {
        $flags = ProductLabelStyle::resolveFlags(['price' => 99.0], 0);
        self::assertFalse($flags['is_free_shipping']);
        $flags = ProductLabelStyle::resolveFlags([
            'is_free_shipping' => 1,
            'free_shipping_min_amount' => 0,
            'price' => 10,
        ], 0);
        self::assertTrue($flags['is_free_shipping']);
    }

    public function testSanitizeUsesThemeUiColor(): void
    {
        self::assertTrue(ThemeUiColor::isValid(ProductLabelStyle::globalBgToken(ProductLabelStyle::KIND_NEW)));
    }
}
