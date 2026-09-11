<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Cart owns storefront cart_type switch Event/API; Theme must not bind B2B DOM.
 */
final class CartTypeSwitchDecoupleContractTest extends TestCase
{
    public function testCartJsOwnsCartTypeChangedEventAndRequestApi(): void
    {
        $root = \dirname(__DIR__, 3);
        $cartJs = (string)\file_get_contents($root . '/view/statics/js/cart.js');

        self::assertStringContainsString("CART_TYPE_CHANGED_EVENT = 'weline:cart-type-changed'", $cartJs);
        self::assertStringContainsString('function requestCartType', $cartJs);
        self::assertStringContainsString('requestCartType: requestCartType', $cartJs);
        self::assertStringContainsString('CART_TYPE_CHANGED_EVENT: CART_TYPE_CHANGED_EVENT', $cartJs);
        self::assertStringContainsString('weline_cart_type_explicit', $cartJs);
        self::assertStringContainsString('[data-cart-type-option', $cartJs);
        // forceNetwork must emit Event (not chrome-only click) so preferCache cannot drop reload.
        self::assertStringContainsString('!forceNetwork && options.clickChrome !== false', $cartJs);
        self::assertMatchesRegularExpression(
            "/forceNetwork:\\s*forceNetwork/",
            $cartJs,
        );
    }

    public function testStorefrontConsumersUseCartApiNotB2bSelectors(): void
    {
        $root = \dirname(__DIR__, 3);
        $cartPage = (string)\file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
        $miniCart = (string)\file_get_contents(
            \dirname($root) . '/Theme/view/statics/js/widgets/mini-cart-icon.js',
        );
        $b2b = (string)\file_get_contents(
            \dirname($root) . '/B2B/view/statics/js/selling-mode.js',
        );
        $cartCss = (string)\file_get_contents($root . '/view/statics/css/cart-page-amazon.css');

        self::assertStringContainsString('WelineCart.requestCartType', $cartPage);
        self::assertStringContainsString('weline:cart-type-changed', $cartPage);
        self::assertStringContainsString("forceNetwork: true", $cartPage);
        self::assertStringContainsString('weline-cart-shell__sibling-cta', $cartPage);
        self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $cartPage);
        // Empty preferCache must not terminal-return; sibling with items clears opposite empty bucket.
        self::assertStringContainsString('localCartSummaryHasItems', $cartPage);
        self::assertStringContainsString('invalidateEmptyCachesClaimedBySiblings', $cartPage);
        self::assertStringContainsString('Empty typed cache cannot short-circuit', $cartPage);

        self::assertStringContainsString('WelineCart.requestCartType', $miniCart);
        self::assertStringContainsString('weline:cart-type-changed', $miniCart);
        self::assertStringContainsString("forceNetwork: true", $miniCart);
        self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $miniCart);
        self::assertStringContainsString('summaryCacheHasLineItems', $miniCart);
        self::assertStringContainsString('invalidateEmptyCachesClaimedBySiblings', $miniCart);
        self::assertStringContainsString('Empty typed cache cannot short-circuit', $miniCart);

        // B2B adapts Cart Event → setMode without re-emitting (avoids preferCache race).
        self::assertStringContainsString('weline:cart-type-changed', $b2b);
        self::assertStringContainsString('data-cart-type-option', $b2b);
        self::assertStringContainsString('emit: false', $b2b);

        self::assertStringContainsString('.weline-cart-shell__sibling-cta', $cartCss);
        self::assertStringContainsString('.weline-cart-shell__empty-actions', $cartCss);
    }
}
