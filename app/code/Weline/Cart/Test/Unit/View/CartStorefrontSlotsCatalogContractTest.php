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
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
        $tpl = 'Weline_Cart::templates/frontend/widgets/cart-storefront-slots.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));

        $template = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cart-storefront-slots.phtml';
        self::assertFileExists($template);
        $src = (string)file_get_contents($template);
        self::assertStringContainsString('discovery-only', $src);
        self::assertStringContainsString('@widget.code {cart-storefront-slots}', $src);
        self::assertStringContainsString('@widget.type {container}', $src);
        self::assertStringContainsString('@widget.is_container {true}', $src);
        self::assertStringContainsString('"cart-summary-discount"', $src);
        self::assertStringContainsString('"cart-summary-note"', $src);
        self::assertStringContainsString('"cart-summary-credit"', $src);
        self::assertStringContainsString('cart-coupon', $src);
        self::assertStringContainsString('order-notice', $src);
        self::assertStringContainsString('b2b-checkout-credit', $src);
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
        // toc fail-closed: credit slot SSR-hidden until tob JS reveals (extras tab gate).
        self::assertMatchesRegularExpression(
            '/class="weline-cart-shell__credit-slot"\s+hidden\b/s',
            $template
        );
        self::assertStringContainsString('b2b-checkout-credit', $template);
        self::assertStringContainsString('data-cart-discount-breakdown', $template);
        self::assertStringContainsString('data-cart-goods-subtotal', $template);
        self::assertStringContainsString('data-cart-goods-subtotal-major', $template);
        self::assertStringContainsString('data-cart-discount-lines', $template);
        self::assertStringContainsString('discount_preview', $template);
        self::assertStringContainsString('weline:cart-updated', $template);
        self::assertStringNotContainsString('<w:widget', $template);
    }
}
