<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;

/**
 * Regression guard for the duplicated `product-info` PDP render.
 *
 * Theme-override semantics: an overridden widget must count as already present even
 * when the persisted declaration still carries a pre-rename slot id
 * (`product-main` vs the rendered `design-product-main`), so the original file is
 * inert instead of being injected a second time.
 *
 * Constraint kept intact: presence stays PER DECLARED SLOT (not page-wide) — the same
 * widget may legitimately be required into several different slots and each must fill.
 */
final class RequiredDefaultInjectionSlotFamilyTest extends TestCase
{
    private const MODULE = 'Weline_Product';
    private const CODE = 'product-info';

    public function testSlotIdAliasesBridgesDesignPrefix(): void
    {
        self::assertSame(['product-main', 'design-product-main'], RequiredDefaultInjectionContract::slotIdAliases('product-main'));
        self::assertSame(['design-product-main', 'product-main'], RequiredDefaultInjectionContract::slotIdAliases('design-product-main'));
        self::assertSame([], RequiredDefaultInjectionContract::slotIdAliases('  '));
        self::assertSame(
            RequiredDefaultInjectionContract::slotIdAliases('PRODUCT-MAIN'),
            RequiredDefaultInjectionContract::slotIdAliases('product-main'),
        );
    }

    /**
     * The actual bug: widget rendered by the layout inside the current slot, while the
     * declaration still points at the old slot id -> must count as present.
     */
    public function testDeclarationOldSlotStillCountsAsPresentViaFamily(): void
    {
        $html = '<div class="widget-wrapper"'
            . ' data-node-uid="n1" data-widget-code="product-info"'
            . ' data-widget-module="Weline_Product" data-widget-type="product"'
            . ' data-slot-id="design-product-main">'
            . '<section class="product-native-detail">main info</section>'
            . '</div>';

        self::assertTrue(
            RequiredDefaultInjectionContract::slotFamilyHasWidgetPresent(
                $html,
                'product-main',
                self::MODULE,
                self::CODE,
            ),
            'Old declared slot must resolve through the slot family.',
        );
        // Slot-exact check misses — that is the bug; the family check is the fix.
        self::assertFalse(
            RequiredDefaultInjectionContract::slotHasWidgetPresent($html, 'product-main', self::MODULE, self::CODE),
        );
    }

    public function testGenuinelyMissingWidgetIsStillAbsent(): void
    {
        $html = '<div class="page"><p>no product widget here</p></div>';

        self::assertFalse(
            RequiredDefaultInjectionContract::slotFamilyHasWidgetPresent($html, 'product-main', self::MODULE, self::CODE),
        );
    }

    /**
     * Different widget code in the same slot must not mask a missing one.
     */
    public function testDifferentWidgetCodeDoesNotCount(): void
    {
        $html = '<div class="widget-wrapper" data-widget-code="product-reviews"'
            . ' data-slot-id="design-product-main"></div>';

        self::assertFalse(
            RequiredDefaultInjectionContract::slotFamilyHasWidgetPresent($html, 'product-main', self::MODULE, self::CODE),
        );
    }

    /**
     * Critical invariant: the SAME widget required into a SECOND, unrelated slot must
     * still be considered absent for that slot (so it gets filled). Slot-family aliasing
     * must not degrade this into page-level presence.
     */
    public function testSameWidgetInAnotherUnrelatedSlotIsStillAbsentForThisSlot(): void
    {
        $html = '<div class="widget-wrapper" data-widget-code="checkout-coupon"'
            . ' data-slot-id="footer-extras"></div>';

        self::assertTrue(
            RequiredDefaultInjectionContract::slotFamilyHasWidgetPresent($html, 'footer-extras', 'Weline_Marketing', 'checkout-coupon'),
        );
        self::assertFalse(
            RequiredDefaultInjectionContract::slotFamilyHasWidgetPresent($html, 'checkout-summary-discount', 'Weline_Marketing', 'checkout-coupon'),
            'A sibling slot must remain fillable; aliasing must not become page-wide.',
        );
    }
}
