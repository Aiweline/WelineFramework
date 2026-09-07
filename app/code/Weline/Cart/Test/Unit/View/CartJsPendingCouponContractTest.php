<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CartJsPendingCouponContractTest extends TestCase
{
    public function testCartJsRegistersPendingCouponAndGuestRenewHelpers(): void
    {
        $root = \dirname(__DIR__, 3);
        $cartJs = (string)\file_get_contents($root . '/view/statics/js/cart.js');
        $modules = (string)\file_get_contents($root . '/view/statics/frontend/weline.modules.js');
        $provider = (string)\file_get_contents($root . '/extends/module/Weline_Framework/Query/CartQueryProvider.php');

        self::assertStringContainsString('weline.cart.pending_coupon', $cartJs);
        self::assertStringContainsString('weline.cart.summary_cache', $cartJs);
        self::assertStringContainsString('rememberSummary', $cartJs);
        self::assertStringContainsString('getCachedSummary', $cartJs);
        self::assertStringContainsString('clearCachedSummary', $cartJs);
        self::assertStringContainsString('weline:cart:apply-coupon', $cartJs);
        self::assertStringContainsString('renewGuestSession', $cartJs);
        self::assertStringContainsString('Weline_Cart::js/cart.js', $modules);
        self::assertStringContainsString("'renewGuestSession'", $provider);
        self::assertStringContainsString('function renewGuestSession', $provider);
        self::assertStringContainsString('touchGuestCart', (string)\file_get_contents($root . '/Service/CartService.php'));
    }
}
