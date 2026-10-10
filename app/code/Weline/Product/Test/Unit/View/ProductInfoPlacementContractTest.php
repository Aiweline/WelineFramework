<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductInfoPlacementContractTest extends TestCase
{
    public function testNativeRecommendationsKeepConfigOnlyOnForeignInjectionSlots(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        $expected = [
            'related-products' => ['design-product-related-products'],
            'you-may-like' => ['design-product-you-may-like'],
            'cross-sell' => ['design-product-cross-sell', 'cart-recommendations'],
            'recommended-products' => ['design-category-recommendations', 'design-list-recommendations', 'not-found-recommendations'],
        ];
        foreach ($expected as $code => $slots) {
            $tpl = 'Weline_Product::templates/frontend/widgets/' . $code . '.phtml';
            self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl), $code);
            $src = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/' . $code . '.phtml');
            self::assertStringContainsString('@widget.placement {layout}', $src, $code);
            foreach ($slots as $slot) {
                self::assertStringContainsString('"slot":"' . $slot . '"', $src, $code . ':' . $slot);
            }
            self::assertStringContainsString('"placement":"injection"', $src, $code);
            self::assertStringContainsString('"required":true', $src, $code);
            self::assertStringContainsString('"config":', $src, $code);
        }
    }

    public function testNativeInfoRetainsLayoutAndForeignDesignHasDedicatedInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        $tpl = 'Weline_Product::templates/frontend/widgets/product-info.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml');
        self::assertStringContainsString('@widget.placement {layout}', $src);
        self::assertStringContainsString('"slot":"design-product-main"', $src);
        self::assertStringContainsString('"layout_type":"product"', $src);
        self::assertStringContainsString('"required":true', $src);
        self::assertStringContainsString('"show_brand":true', $src);
        self::assertStringContainsString('"show_supplier":true', $src);
    }
}
