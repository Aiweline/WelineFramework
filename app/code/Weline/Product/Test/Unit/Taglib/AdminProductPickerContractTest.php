<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class AdminProductPickerContractTest extends TestCase
{
    public function testPickerUsesProductAdminResourceAndThemeTokens(): void
    {
        $taglibPath = dirname(__DIR__, 3) . '/Taglib/AdminProductPicker.php';
        $scriptPath = dirname(__DIR__, 3) . '/view/statics/js/backend/product-admin-picker.js';
        self::assertFileExists($taglibPath);
        self::assertFileExists($scriptPath);

        $taglib = (string) file_get_contents($taglibPath);
        $script = (string) file_get_contents($scriptPath);

        self::assertSame('product:admin:picker', \Weline\Product\Taglib\AdminProductPicker::name());
        self::assertTrue(method_exists(\Weline\Product\Taglib\AdminProductPicker::class, 'runtimeCallback'));
        self::assertStringContainsString('filters.keyword', $script);
        self::assertStringNotContainsString('filters.name = kw', $script);
        self::assertStringNotContainsString('filters.sku = kw', $script);
        self::assertStringContainsString('data-product-admin-picker', $taglib);
        self::assertStringContainsString('data-product-admin-picker-open', $taglib);
        self::assertStringContainsString('w-dialog', $taglib);
        self::assertStringContainsString('data-product-admin-picker-dialog', $taglib);
        self::assertStringContainsString('w-product-admin-picker__thumb', $script);
        self::assertStringContainsString('resolvePickerLocale', $script);
        self::assertStringContainsString('filters.locale', $script);
        self::assertStringContainsString('formatSelectedLabel', $script);
        self::assertStringContainsString('enrichSelectedLabels', $script);
        self::assertStringContainsString('product_ids', $script);
        self::assertStringContainsString('ui.dialog.open', $script);
        self::assertStringContainsString('runSearch()', $script);
        self::assertStringContainsString('正在加载已发布商品', $taglib);
        self::assertStringContainsString('data-product-admin-picker-open', $script);
        self::assertStringContainsString('extractPrice', $script);
        self::assertStringContainsString('price_label', $script);
        self::assertStringContainsString('w-product-admin-picker__item-price', $script);
        self::assertStringContainsString('w-product-admin-picker__col-price', $script);
        self::assertStringContainsString("'price' =>", $taglib);
        self::assertStringContainsString('resolveModuleStaticUrl', $taglib);
        self::assertStringContainsString('fetchTagSource', $taglib);
        self::assertStringNotContainsString('@static(Weline_Product::css/backend/product-admin-picker.css)', $taglib);
        self::assertStringNotContainsString('@static(Weline_Product::js/backend/product-admin-picker.js)', $taglib);
        self::assertStringContainsString('--weline-theme-border', (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/backend/product-admin-picker.css'
        ));
    }
}
