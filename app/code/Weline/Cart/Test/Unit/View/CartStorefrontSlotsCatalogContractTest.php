<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 购物车模块页槽必须进入部件槽目录，否则 Theme default_injections 会被 layout_slot_missing 拦截。
 */
final class CartStorefrontSlotsCatalogContractTest extends TestCase
{
    public function testCartDeclaresStorefrontSlotCatalogForInjectionDiscovery(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
        self::assertIsArray($widgets);
        self::assertArrayHasKey('cart-storefront-slots', $widgets);

        $catalog = $widgets['cart-storefront-slots'];
        self::assertTrue((bool)($catalog['is_container'] ?? false));
        self::assertSame('container', $catalog['type'] ?? null);

        $slots = $catalog['slots'] ?? [];
        self::assertArrayHasKey('cart-summary-discount', $slots);
        self::assertArrayHasKey('cart-summary-note', $slots);
        self::assertArrayHasKey('cart-summary-credit', $slots);
        self::assertContains('cart-coupon', $slots['cart-summary-discount']['accepts'] ?? []);
        self::assertContains('order-notice', $slots['cart-summary-note']['accepts'] ?? []);
        self::assertContains('b2b-checkout-credit', $slots['cart-summary-credit']['accepts'] ?? []);
        self::assertContains('b2b', $slots['cart-summary-credit']['accepts'] ?? []);

        $template = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cart-storefront-slots.phtml';
        self::assertFileExists($template);
        self::assertStringContainsString('discovery-only', (string)file_get_contents($template));
    }

    public function testCartPageSummaryDeclaresCouponAndNoteSlots(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/cart/index.phtml';
        $template = (string)file_get_contents($path);
        self::assertStringContainsString('id="cart-summary-discount"', $template);
        self::assertStringContainsString('id="cart-summary-note"', $template);
        self::assertStringContainsString('id="cart-summary-credit"', $template);
        self::assertStringContainsString('class="weline-cart-shell__coupon-slot"', $template);
        self::assertStringContainsString('class="weline-cart-shell__note-slot"', $template);
        self::assertStringContainsString('class="weline-cart-shell__credit-slot"', $template);
        self::assertStringContainsString('b2b-checkout-credit', $template);
        self::assertStringContainsString('data-cart-discount-breakdown', $template);
        self::assertStringContainsString('data-cart-goods-subtotal', $template);
        self::assertStringContainsString('data-cart-goods-subtotal-major', $template);
        self::assertStringContainsString('data-cart-discount-lines', $template);
        self::assertStringContainsString('weline-cart-shell__summary-row--payable', $template);
        self::assertStringContainsString('discount_preview', $template);
        self::assertStringContainsString('weline:cart-updated', $template);
        self::assertStringNotContainsString('<w:widget', $template);
    }
}
