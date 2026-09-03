<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;

/**
 * 唯一公网 callback/{method}；取消用 outcome=cancel；禁止旧嵌套/.cancel 路径。
 */
final class PaymentCallbackLegacyPathContractTest extends TestCase
{
    public function testRouterBlocksLegacyAndDotCancelPaths(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Router.php');
        self::assertStringContainsString("str_starts_with(\$normalizedPath, 'payment/frontend/callback/return/')", $src);
        self::assertStringContainsString("str_starts_with(\$normalizedPath, 'payment/frontend/callback/cancel/')", $src);
        self::assertStringContainsString("'payment/frontend/callback/__removed__'", $src);
        self::assertStringContainsString('outcome=cancel', $src);
        self::assertStringContainsString('\\.cancel', $src);
    }

    public function testPublicRoutesShareSingleMethodPath(): void
    {
        self::assertSame(
            'payment/frontend/callback/paypal',
            PaymentBrowserCallbackRoutes::returnRoute('paypal'),
        );
        self::assertSame(
            PaymentBrowserCallbackRoutes::returnRoute('paypal'),
            PaymentBrowserCallbackRoutes::cancelRoute('paypal'),
        );
        self::assertTrue(PaymentBrowserCallbackRoutes::isCancelOutcome([
            PaymentBrowserCallbackRoutes::QUERY_OUTCOME => 'cancel',
        ]));
        self::assertFalse(PaymentBrowserCallbackRoutes::isCancelOutcome([]));
    }

    public function testCallbackControllerBranchesOnOutcome(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Callback.php');
        self::assertStringContainsString('function browserReturnEntry()', $src);
        self::assertStringContainsString('isCancelOutcome', $src);
        self::assertStringContainsString('function browserCancelEntry()', $src);
        self::assertStringNotContainsString('function return()', $src);
        self::assertStringNotContainsString('function cancel()', $src);
    }
}
