<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;
use Weline\Payment\Service\PaymentRedirectUriCatalog;
use Weline\Payment\Service\PayPalOAuthService;
use Weline\Payment\Service\PayPalSandboxRedirectUriCatalog;

/**
 * Developer Return URL 只暴露支付模块统一浏览器回跳；壳不得硬编码网关 OAuth。
 */
final class PayPalUnifiedBrowserReturnUriContractTest extends TestCase
{
    public function testCatalogAndOauthShareMethodScopedCallbackReturnRoute(): void
    {
        self::assertSame(
            'payment/frontend/callback/browser-return-entry',
            PaymentBrowserCallbackRoutes::RETURN_DISPATCH,
        );
        self::assertSame(
            'payment/frontend/callback/paypal',
            PaymentBrowserCallbackRoutes::returnRoute('paypal'),
        );

        $catalogSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentRedirectUriCatalog.php');
        self::assertStringContainsString('PaymentShellCallbackUrlCatalog', $catalogSrc);
        self::assertStringNotContainsString('payment/frontend/paypal/return', $catalogSrc);
        self::assertStringNotContainsString('getBackendUrl', $catalogSrc);

        $oauthSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PayPalOAuthService.php');
        self::assertStringContainsString('function browserReturnUrl', $oauthSrc);
        self::assertStringContainsString('browserReturnRegister', $oauthSrc);
        self::assertStringContainsString('PaymentShellCallbackUrlCatalog', $oauthSrc);
        self::assertStringContainsString('requireStorageScope', $oauthSrc);

        $routesSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserCallbackRoutes.php');
        self::assertStringContainsString('QUERY_TARGET_SCOPE', $routesSrc);
        self::assertStringContainsString('returnRoute', $routesSrc);
        self::assertStringContainsString('cancelRoute', $routesSrc);
        self::assertStringContainsString('RETURN_DISPATCH', $routesSrc);
        self::assertStringContainsString('QUERY_OUTCOME', $routesSrc);
        self::assertStringContainsString('OUTCOME_CANCEL', $routesSrc);
        self::assertStringNotContainsString('CANCEL_DISPATCH', $routesSrc);
        self::assertStringNotContainsString("'payment/frontend/callback/return/'", $routesSrc);

        $connectSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Connect.php');
        self::assertStringContainsString('一键授权缺少显式配置范围', $connectSrc);
        self::assertStringNotContainsString(
            "\$target = \$targetScopeService->resolveFromInput([], false);",
            $connectSrc,
        );

        $dispatcherSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentConnectDispatcher.php');
        self::assertStringContainsString('已拒绝静默写入 Global', $dispatcherSrc);

        $callbackSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Callback.php');
        self::assertStringContainsString('function browserReturnEntry()', $callbackSrc);
        self::assertStringContainsString('PaymentBrowserReturnDispatcher', $callbackSrc);
        self::assertStringNotContainsString('PayPalOAuthService', $callbackSrc);
        self::assertStringContainsString('function browserCancelEntry()', $callbackSrc);
        self::assertStringNotContainsString('function return()', $callbackSrc);
        self::assertStringNotContainsString('function cancel()', $callbackSrc);
        self::assertStringNotContainsString('function returnDispatch()', $callbackSrc);

        self::assertTrue(class_exists(PaymentRedirectUriCatalog::class));
        self::assertTrue(is_subclass_of(PayPalSandboxRedirectUriCatalog::class, PaymentRedirectUriCatalog::class));
        self::assertTrue(class_exists(PayPalOAuthService::class));
        self::assertSame('iframe', PaymentOperationResult::NEXT_IFRAME);
    }
}
