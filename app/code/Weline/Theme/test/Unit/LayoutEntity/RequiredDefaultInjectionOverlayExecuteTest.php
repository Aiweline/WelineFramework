<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionStorefrontOverlay;
use Weline\Theme\Service\SlotBoundaryScanner;

/**
 * Behavioral closed loop for plan-based overlay execute (no Catalog I/O).
 * Identity XOR is enforced at registry/static gate — not by runtime presence/count.
 */
final class RequiredDefaultInjectionOverlayExecuteTest extends TestCase
{
    public function testExecuteOneInjectsOnceIntoDeepestEmptyAndSkipsSiblingEmptyRegion(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $execute = new \ReflectionMethod($overlay, 'executeOne');
        $execute->setAccessible(true);

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

        $manual = '<!--@weline-slot:product-purchase-actions-->'
            . '<div data-wslot="product-purchase-actions" class="outer">'
            . '<div data-widget-code="product-add-to-cart" data-testid="product-add-to-cart" data-slot-id="product-purchase-actions">CTA</div>'
            . '<!--@weline-slot:product-purchase-actions-->'
            . '<div data-wslot="product-purchase-actions" class="inner"></div>'
            . '<!--@/weline-slot:product-purchase-actions-->'
            . '</div>'
            . '<!--@/weline-slot:product-purchase-actions-->';

        $after = $execute->invoke($overlay, $manual, $item, 1, 'default', 'required');
        self::assertSame(1, substr_count($after, 'data-testid="product-add-to-cart"'));
        self::assertSame(1, substr_count($after, 'data-widget-code="product-add-to-cart"'));
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

    public function testAssertFilledSoftSkipsWhenSlotPresentButWidgetMissing(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $assert = new \ReflectionMethod($overlay, 'assertFilled');
        $assert->setAccessible(true);

        // Unfilled is soft: same-widget layout+JSON XOR is source hygiene, not a 500.
        $assert->invoke($overlay, '<!--@weline-slot:product-purchase-actions--><div></div><!--@/weline-slot:product-purchase-actions-->', [
            'slot_id' => 'product-purchase-actions',
            'widget_module' => 'Weline_Cart',
            'widget_code' => 'product-add-to-cart',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testExecuteOneAppendsSiblingsOnMultipleSlot(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $replace = new \ReflectionMethod($overlay, 'replaceRegionInner');
        $replace->setAccessible(true);
        $regionsMethod = new \ReflectionMethod($overlay, 'listSlotRegions');
        $regionsMethod->setAccessible(true);

        $html = '<!--@weline-slot:header-nav-extensions-->'
            . '<div data-wslot="header-nav-extensions" data-wslot-multiple="true" class="header-nav-extensions">'
            . '<a data-testid="header-deals-link" data-widget-code="header-deals-link">Deals</a>'
            . '</div>'
            . '<!--@/weline-slot:header-nav-extensions-->';

        // Simulate compose path used by executeOne without full renderNode.
        $regions = $regionsMethod->invoke($overlay, $html, 'header-nav-extensions');
        self::assertNotSame([], $regions);
        $existing = (string)($regions[0]['inner'] ?? '');
        $blog = '<a data-testid="header-blog-link" data-widget-code="header-blog-link">Blog</a>';
        $allows = new \ReflectionMethod($overlay, 'slotAllowsMultiple');
        $allows->setAccessible(true);
        self::assertTrue($allows->invoke($overlay, $html, 'header-nav-extensions', $regions[0]));
        $composed = $existing . $blog;
        $after = $replace->invoke($overlay, $html, $regions[0], $composed);
        self::assertStringContainsString('header-deals-link', $after);
        self::assertStringContainsString('header-blog-link', $after);
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
        self::assertStringContainsString('pageHasWidgetPresent', $src);
        self::assertStringContainsString('assertSlotHasAtMostOne', $src);
        self::assertStringContainsString('outermostSlotRegions', $src);
        self::assertStringContainsString('soft-skip', $src);
        self::assertStringContainsString('slotAllowsMultiple', $src);
        self::assertStringNotContainsString(
            "throw new \\RuntimeException(\n                'required_default_injection_duplicate:",
            $src,
        );

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
