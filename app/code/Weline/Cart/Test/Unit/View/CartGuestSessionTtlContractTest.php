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
    }
}
