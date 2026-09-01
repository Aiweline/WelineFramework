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
        self::assertStringContainsString('product_admin', $script);
        self::assertStringContainsString("resource('product_admin')", $script);
        self::assertStringContainsString('data-product-admin-picker', $taglib);
        self::assertStringContainsString('resolveModuleStaticUrl', $taglib);
        self::assertStringContainsString('fetchTagSource', $taglib);
        self::assertStringNotContainsString('@static(Weline_Product::css/backend/product-admin-picker.css)', $taglib);
        self::assertStringNotContainsString('@static(Weline_Product::js/backend/product-admin-picker.js)', $taglib);
        self::assertStringContainsString('--weline-theme-border', (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/backend/product-admin-picker.css'
        ));
    }
}
