<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;

final class PaymentBrowserCallbackTargetScopeTest extends TestCase
{
    public function testWithTargetScopeAppendsQuery(): void
    {
        $url = PaymentBrowserCallbackRoutes::withTargetScope(
            'https://shop.example.test/payment/frontend/callback/return',
            'shop.cn.default',
        );

        self::assertSame(
            'https://shop.example.test/payment/frontend/callback/return?target_scope=shop.cn.default',
            $url,
        );
    }

    public function testWithTargetScopeOverwritesExistingTargetScope(): void
    {
        $url = PaymentBrowserCallbackRoutes::withTargetScope(
            'https://shop.example.test/payment/frontend/callback/return?target_scope=default.default.default&x=1',
            'shop.default.default',
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('shop.default.default', $query['target_scope'] ?? null);
        self::assertSame('1', $query['x'] ?? null);
    }

    public function testWithTargetScopeRejectsShortScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentBrowserCallbackRoutes::withTargetScope(
            'https://shop.example.test/payment/frontend/callback/return',
            'default',
        );
    }

    public function testIsStorageScopeAcceptsWebsiteSentinel(): void
    {
        self::assertTrue(PaymentBrowserCallbackRoutes::isStorageScope('default.__website__.default'));
        self::assertTrue(PaymentBrowserCallbackRoutes::isStorageScope('default.default.default'));
        self::assertFalse(PaymentBrowserCallbackRoutes::isStorageScope(''));
        self::assertFalse(PaymentBrowserCallbackRoutes::isStorageScope('shop.main'));
    }
}
