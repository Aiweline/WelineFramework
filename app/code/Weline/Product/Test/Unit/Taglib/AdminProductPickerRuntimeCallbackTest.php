<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Taglib;

use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Template;
use Weline\Product\Taglib\AdminProductPicker;

final class AdminProductPickerRuntimeCallbackTest extends TestCore
{
    public function testRuntimeCallbackEmitsPickerMarkupWithoutPhpSource(): void
    {
        $template = $this->createMock(Template::class);
        $template->method('getData')->willReturn([
            'selectedProductsJson' => '[{"product_id":12,"website_id":3,"name":"Demo","sku":"SKU-1"}]',
        ]);
        $template->method('getUrl')->willReturn('https://example.test/admin/promotion/backend/theme/searchProducts');

        $runtime = AdminProductPicker::runtimeCallback();
        $html = $runtime(
            $template,
            'tag-self-close',
            [
                'id' => 'promotion-theme-product-picker',
                'name' => 'product_ids[]',
                'selected' => 'selectedProductsJson',
                'website-field' => 'website_id',
                'store-field' => 'store_code',
                'channel-field' => 'channel_code',
                'mode-field' => 'product_pick_mode',
                'search-url' => 'https://example.test/admin/promotion/backend/theme/searchProducts',
                'cross-website' => 'true',
                'limit' => '20',
            ],
            '',
        );

        self::assertIsString($html);
        self::assertStringContainsString('data-product-admin-picker', $html);
        self::assertStringContainsString('promotion-theme-product-picker', $html);
        self::assertStringContainsString('product-admin-picker.js', $html);
        self::assertStringContainsString('SKU-1', $html);
        self::assertStringNotContainsString('<?php', $html);
        self::assertStringNotContainsString('Weline_Taglib_resolve', $html);
        self::assertStringNotContainsString('$Taglib__', $html);
    }

    public function testCompileCallbackStillPrefacesAttributeResolverPhp(): void
    {
        $callback = AdminProductPicker::callback();
        $html = $callback(
            'tag-self-close',
            [],
            [''],
            [
                'id' => 'promotion-theme-product-picker',
                'name' => 'product_ids[]',
                'selected' => 'selectedProductsJson',
                'search-url' => 'https://example.test/admin/promotion/backend/theme/searchProducts',
                'cross-website' => 'true',
            ],
        );

        self::assertIsString($html);
        self::assertStringContainsString('<?php', $html);
        self::assertStringContainsString('Weline_Taglib_resolve', $html);
        self::assertStringContainsString('data-product-admin-picker', $html);
    }
}
