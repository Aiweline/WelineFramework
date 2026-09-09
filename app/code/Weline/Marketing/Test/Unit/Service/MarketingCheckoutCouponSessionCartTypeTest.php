<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Marketing\Service\MarketingCheckoutCouponSession;

final class MarketingCheckoutCouponSessionCartTypeTest extends TestCase
{
    public function testNormalizeAndAllowListDefaultsToTocOnly(): void
    {
        $session = $this->newSession();
        self::assertSame('toc', $session->normalizeCartType(null));
        self::assertSame('toc', $session->normalizeCartType(''));
        self::assertSame('tob', $session->normalizeCartType('TOB'));
        self::assertTrue($session->couponsAllowedForCartType('toc'));
        self::assertFalse($session->couponsAllowedForCartType('tob'));
        self::assertFalse($session->couponsAllowedForCartType('custom'));
    }

    public function testGetCouponIsScopedAndTobAlwaysEmpty(): void
    {
        $session = $this->newSession();
        $apply = $session->getCoupon(['cart_type' => 'toc']);
        self::assertTrue($apply['success']);
        self::assertSame('toc', $apply['cart_type']);
        self::assertTrue($apply['coupons_allowed']);
        self::assertSame('', $apply['coupon_code']);

        $tob = $session->getCoupon(['cart_type' => 'tob']);
        self::assertTrue($tob['success']);
        self::assertSame('tob', $tob['cart_type']);
        self::assertFalse($tob['coupons_allowed']);
        self::assertSame('', $tob['coupon_code']);
        self::assertSame('', $session->getCouponCode('tob'));
    }

    private function newSession(): MarketingCheckoutCouponSession
    {
        /** @var array<string, mixed> $bag */
        $bag = [];
        $auth = $this->createMock(AuthenticatedSessionInterface::class);
        $auth->method('get')->willReturnCallback(static function (string $key) use (&$bag): mixed {
            return $bag[$key] ?? null;
        });
        $auth->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$bag): void {
            $bag[$key] = $value;
        });
        $auth->method('delete')->willReturnCallback(static function (string $key) use (&$bag): void {
            unset($bag[$key]);
        });
        $auth->method('isLoggedIn')->willReturn(false);
        $auth->method('getUserId')->willReturn(null);

        $factory = $this->createStub(SessionFactory::class);
        $factory->method('createFrontendSession')->willReturn($auth);

        return new MarketingCheckoutCouponSession($factory);
    }
}
