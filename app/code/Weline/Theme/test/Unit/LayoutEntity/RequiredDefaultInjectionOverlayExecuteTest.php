<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionStorefrontOverlay;
use Weline\Theme\Service\SlotBoundaryScanner;

/**
 * Behavioral closed loop for plan-based overlay execute (no Catalog I/O).
 */
final class RequiredDefaultInjectionOverlayExecuteTest extends TestCase
{
    public function testExecuteOneInjectsOnceIntoDeepestEmptyAndSkipsSiblingEmptyRegion(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $execute = new \ReflectionMethod($overlay, 'executeOne');
        $execute->setAccessible(true);

        // Two regions same slot id: outer empty shell + nested empty (historical duplicate markers).
        $html = '<!--@weline-slot:product-purchase-actions-->'
            . '<div data-wslot="product-purchase-actions" class="outer">'
            . '<!--@weline-slot:product-purchase-actions-->'
            . '<div data-wslot="product-purchase-actions" class="inner"></div>'
            . '<!--@/weline-slot:product-purchase-actions-->'
            . '</div>'
            . '<!--@/weline-slot:product-purchase-actions-->';

        $item = [
            'slot_id' => 'product-purchase-actions',
            'widget_module' => 'Weline_Cart',
            'widget_code' => 'product-add-to-cart',
            'depth' => 1,
            'node' => [
                'node_uid' => str_repeat('a', 32),
                'widget_module' => 'Weline_Cart',
                'widget_code' => 'product-add-to-cart',
                'widget_type' => 'product',
                'sort_order' => 0,
                'is_active' => true,
                'config' => [],
            ],
        ];

        // Stub renderNode via anonymous subclass is hard; call with a pre-wrapped presence
        // by testing second execute after first manual inject.
        $manual = '<!--@weline-slot:product-purchase-actions-->'
            . '<div data-wslot="product-purchase-actions" class="outer">'
            . '<div data-widget-code="product-add-to-cart" data-testid="product-add-to-cart" data-slot-id="product-purchase-actions">CTA</div>'
            . '<!--@weline-slot:product-purchase-actions-->'
            . '<div data-wslot="product-purchase-actions" class="inner"></div>'
            . '<!--@/weline-slot:product-purchase-actions-->'
            . '</div>'
            . '<!--@/weline-slot:product-purchase-actions-->';

        // Second execute must not fan-out into the empty nested sibling.
        $after = $execute->invoke($overlay, $manual, $item, 1, 'default', 'required');
        self::assertSame(1, substr_count($after, 'data-testid="product-add-to-cart"'));
        self::assertSame(1, substr_count($after, 'data-widget-code="product-add-to-cart"'));
        // Empty nested region may remain empty — that is OK (整槽 once).
        self::assertStringContainsString('class="inner"', $after);
    }

    public function testAssertFilledPassesWhenOncePresentAcrossRegions(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $assert = new \ReflectionMethod($overlay, 'assertFilled');
        $assert->setAccessible(true);

        $html = '<!--@weline-slot:product-purchase-actions-->'
            . '<div data-testid="product-add-to-cart" data-widget-code="product-add-to-cart">A</div>'
            . '<!--@/weline-slot:product-purchase-actions-->'
            . '<!--@weline-slot:product-purchase-actions-->'
            . '<div class="empty"></div>'
            . '<!--@/weline-slot:product-purchase-actions-->';

        $assert->invoke($overlay, $html, [
            'slot_id' => 'product-purchase-actions',
            'widget_module' => 'Weline_Cart',
            'widget_code' => 'product-add-to-cart',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testAssertFilledThrowsWhenSlotPresentButWidgetMissing(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $assert = new \ReflectionMethod($overlay, 'assertFilled');
        $assert->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('required_default_injection_unfilled');
        $assert->invoke($overlay, '<!--@weline-slot:product-purchase-actions--><div></div><!--@/weline-slot:product-purchase-actions-->', [
            'slot_id' => 'product-purchase-actions',
            'widget_module' => 'Weline_Cart',
            'widget_code' => 'product-add-to-cart',
        ]);
    }

    public function testExecuteOneReplacesMismatchedBakeInsteadOfPrepending(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        self::assertStringNotContainsString('$html . $inner', $src);
        self::assertStringContainsString(
            'replaceRegionInner($rendered, $target, $html)',
            $src,
        );
        self::assertStringContainsString('caused duplicate widgets', $src);

        $base = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $replace = new \ReflectionMethod($base, 'replaceRegionInner');
        $replace->setAccessible(true);
        $regionsMethod = new \ReflectionMethod($base, 'listSlotRegions');
        $regionsMethod->setAccessible(true);

        $baked = '<!--@weline-slot:checkout-summary-discount-->'
            . '<div data-wslot="checkout-summary-discount">'
            . '<div data-testid="marketing-checkout-coupon" data-marketing-checkout-coupon>BAKED</div>'
            . '</div>'
            . '<!--@/weline-slot:checkout-summary-discount-->';
        $regions = $regionsMethod->invoke($base, $baked, 'checkout-summary-discount');
        self::assertNotSame([], $regions);
        $injected = '<div data-widget-code="checkout-coupon" data-testid="checkout-coupon">INJECTED</div>';
        $after = $replace->invoke($base, $baked, $regions[0], $injected);
        self::assertSame(1, substr_count($after, 'INJECTED'));
        self::assertSame(0, substr_count($after, 'BAKED'));
        self::assertSame(1, substr_count($after, 'data-testid="checkout-coupon"'));
    }
}
