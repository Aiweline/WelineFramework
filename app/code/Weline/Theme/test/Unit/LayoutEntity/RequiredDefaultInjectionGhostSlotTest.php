<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionStorefrontOverlay;
use Weline\Theme\Service\SlotBoundaryScanner;

final class RequiredDefaultInjectionGhostSlotTest extends TestCase
{
    public function testGhostWidgetsAreDroppedWhenSlotHtmlLacksWidgetMarkers(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $method = new \ReflectionMethod($overlay, 'dropGhostStructureWidgets');
        $method->setAccessible(true);

        $rendered = '<!--@weline-slot:product-purchase-actions-->'
            . '                                    '
            . '<!--@/weline-slot:product-purchase-actions-->';
        $slots = [
            'product-purchase-actions' => [[
                'widget_module' => 'Weline_Cart',
                'widget_code' => 'product-add-to-cart',
            ], [
                'widget_module' => 'Weline_Checkout',
                'widget_code' => 'product-buy-now',
            ]],
        ];

        $cleaned = $method->invoke($overlay, $slots, $rendered);
        self::assertSame([], $cleaned['product-purchase-actions']);
    }

    public function testPresentWidgetsAreKept(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $method = new \ReflectionMethod($overlay, 'dropGhostStructureWidgets');
        $method->setAccessible(true);

        $rendered = '<!--@weline-slot:product-purchase-actions-->'
            . '<button data-testid="product-add-to-cart" data-action="add">Add</button>'
            . '<button data-testid="product-buy-now" data-action="buy-now">Buy</button>'
            . '<!--@/weline-slot:product-purchase-actions-->';
        $slots = [
            'product-purchase-actions' => [[
                'widget_module' => 'Weline_Cart',
                'widget_code' => 'product-add-to-cart',
            ], [
                'widget_module' => 'Weline_Checkout',
                'widget_code' => 'product-buy-now',
            ]],
        ];

        $cleaned = $method->invoke($overlay, $slots, $rendered);
        self::assertCount(2, $cleaned['product-purchase-actions']);
    }

    public function testOverlayAppendInvokesGhostDrop(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        self::assertStringContainsString('dropGhostStructureWidgets', $src);
        // 有槽才注：无 slot 边界时 continue，不在文末新建 ghost slot。
        self::assertStringContainsString('extractSlotInnerForPresence', $src);
        self::assertMatchesRegularExpression(
            '/if\s*\(\$inner\s*===\s*null\)\s*\{\s*continue;/s',
            $src,
        );
        self::assertStringNotContainsString(
            "\$rendered .= SlotBoundaryMarkers::open(\$slotId)",
            $src,
        );
        self::assertStringContainsString('error_log(\'[RequiredDefaultInjection]', $src);
    }
}
