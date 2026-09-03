<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PayPalAuthorizeCallbackPathContractTest extends TestCase
{
    public function testAuthorizeAdaptersDeclareCallbackPath(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/paypal.phtml'
        );
        self::assertStringContainsString('code="paypal.sandbox.authorize"', $src);
        self::assertStringContainsString('callback-path="payment/frontend/callback/paypal"', $src);
        self::assertStringContainsString('code="paypal.live.authorize"', $src);
        self::assertSame(2, substr_count($src, 'callback-path="payment/frontend/callback/paypal"'));
        self::assertStringNotContainsString('callback/return/paypal', $src);
    }
}
