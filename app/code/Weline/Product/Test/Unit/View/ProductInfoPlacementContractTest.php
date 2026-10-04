<?php
declare(strict_types=1);
namespace Weline\Product\Test\Unit\View;
use PHPUnit\Framework\TestCase;
final class ProductInfoPlacementContractTest extends TestCase
{
    public function testNativeRecommendationsKeepConfigOnlyOnForeignInjectionSlots(): void
    {
        $entries = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        $expected = [
            'related-products' => ['design-product-related-products'],
            'you-may-like' => ['design-product-you-may-like'],
            'cross-sell' => ['design-product-cross-sell', 'cart-recommendations'],
            'recommended-products' => ['design-category-recommendations', 'design-list-recommendations', 'not-found-recommendations'],
        ];
        foreach ($expected as $code => $slots) {
            self::assertSame('layout', $entries[$code]['placement']);
            self::assertSame($slots, array_column($entries[$code]['default_injections'], 'slot'));
            foreach ($entries[$code]['default_injections'] as $relation) {
                self::assertSame('injection', $relation['placement']);
                self::assertTrue($relation['required']);
                self::assertNotEmpty($relation['config']);
            }
        }
    }
    public function testNativeInfoRetainsLayoutAndForeignDesignHasDedicatedInjection(): void
    {
        $entries = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        $widget = $entries['product-info'];
        self::assertSame('layout', $widget['placement']);
        self::assertCount(1, $widget['default_injections'] ?? []);
        $injection = $widget['default_injections'][0];
        self::assertSame('injection', $injection['placement']);
        self::assertSame('product', $injection['layout_type']);
        self::assertSame('default', $injection['layout_option']);
        self::assertSame('design-product-main', $injection['slot']);
        self::assertTrue($injection['required']);
        self::assertSame(['show_brand' => true, 'show_supplier' => true], $injection['config']);
    }
}
