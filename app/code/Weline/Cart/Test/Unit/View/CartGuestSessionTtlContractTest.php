<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\CartPersistencePolicy;

final class CartGuestSessionTtlContractTest extends TestCase
{
    public function testFrontendAndQueryAlignToFifteenDayGuestTtl(): void
    {
        $root = \dirname(__DIR__, 3);
        $cartJs = (string)\file_get_contents($root . '/view/statics/js/cart.js');
        $purchase = (string)\file_get_contents($root . '/view/statics/js/widgets/product-purchase-actions.js');
        $provider = (string)\file_get_contents($root . '/extends/module/Weline_Framework/Query/CartQueryProvider.php');
        $store = (string)\file_get_contents($root . '/Service/CartCacheStore.php');

        $guestDaysExpr = '15 * 24 * 3600';

        self::assertStringContainsString('GUEST_SESSION_MS', $cartJs);
        self::assertStringContainsString($guestDaysExpr . ' * 1000', $cartJs);
        self::assertStringContainsString('guestSessionFrom', $cartJs);
        self::assertStringContainsString('var WEEK_MS = 7 * 24 * 3600 * 1000', $cartJs);

        self::assertStringContainsString($guestDaysExpr . ' * 1000', $purchase);

        self::assertStringContainsString('CartPersistencePolicy::guestTtlSeconds()', $provider);
        self::assertStringContainsString('CartPersistencePolicy::guestExpiresAtMs()', $provider);
        self::assertStringNotContainsString('3600 * 24 * 7', $provider);
        self::assertStringContainsString('another 15 days', $provider);

        self::assertStringContainsString('CartPersistencePolicy::ttlForCart', $store);
        self::assertStringNotContainsString('604800', $store);
        self::assertStringContainsString('1_296_000', (string)\file_get_contents(
            $root . '/Service/CartPersistencePolicy.php',
        ));
        self::assertSame(1_296_000, CartPersistencePolicy::GUEST_TTL_SECONDS);
        self::assertStringContainsString('expires_at_ms', $cartJs);
        self::assertStringContainsString('guestSessionFrom()', $cartJs);
    }

    public function testSummaryCacheRejectsGhostCartWithoutGuestToken(): void
    {
        $root = \dirname(__DIR__, 3);
        $cartJs = (string)\file_get_contents($root . '/view/statics/js/cart.js');
        $cartPage = (string)\file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
        $miniCart = (string)\file_get_contents(
            \dirname($root) . '/Theme/view/statics/js/widgets/mini-cart-icon.js',
        );

        self::assertStringContainsString('function summaryHasLineItems', $cartJs);
        self::assertStringContainsString('Ghost-cart gate: non-empty local summaries require an exact guest_token match', $cartJs);
        self::assertStringContainsString('requireTokenMatch: true', $cartPage);
        self::assertStringContainsString('Ghost-cart gate: non-empty local summary without matching guest_token is a miss', $cartPage);
        self::assertStringContainsString('Ghost-cart gate: no matching guest_token', $miniCart);
        self::assertStringContainsString('requireTokenMatch: true', $miniCart);
        self::assertStringContainsString('Ghost-cart gate (legacy untyped key)', $miniCart);
        self::assertStringNotContainsString('if (!(token && cachedToken && token !== cachedToken))', $miniCart);
        self::assertStringContainsString('data-cart-siblings', $cartPage);
        self::assertStringContainsString('sibling_carts', $cartPage);
        self::assertStringContainsString('WelineCart.requestCartType', $cartPage);
        self::assertStringContainsString('weline:cart-type-changed', $cartPage);
        self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $cartPage);
        self::assertStringContainsString('data-mini-cart-sibling', $miniCart);
        self::assertStringContainsString('WelineCart.requestCartType', $miniCart);
        self::assertStringContainsString('weline:cart-type-changed', $miniCart);
        self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $miniCart);
        self::assertStringContainsString('scheduleCartSync(root)', $miniCart);
    }
}
