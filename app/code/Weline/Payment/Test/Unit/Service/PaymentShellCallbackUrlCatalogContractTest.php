<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;
use Weline\Payment\Service\PaymentBrowserCallbackTokenService;
use Weline\Payment\Service\PaymentBrowserReturnContextResolver;
use Weline\Payment\Service\PaymentShellCallbackUrlCatalog;

final class PaymentShellCallbackUrlCatalogContractTest extends TestCase
{
    public function testCatalogSourceDefinesShellRoutes(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentShellCallbackUrlCatalog.php');
        self::assertStringContainsString('browserReturnRegister', $src);
        self::assertStringContainsString('browserReturn', $src);
        self::assertStringContainsString('browserCancel', $src);
        self::assertStringContainsString('webhookNotify', $src);
        self::assertStringContainsString('browserFailure', $src);
        self::assertStringContainsString('buildBrowserCallbackUrls', $src);
        self::assertStringContainsString('shell_token', $src);
    }

    public function testCancelUsesOutcomeOnSamePath(): void
    {
        self::assertSame(
            'payment/frontend/callback/paypal',
            PaymentBrowserCallbackRoutes::returnRoute('paypal'),
        );
        self::assertSame(
            'payment/frontend/callback/paypal',
            PaymentBrowserCallbackRoutes::cancelRoute('paypal'),
        );
        self::assertSame(PaymentBrowserCallbackRoutes::OUTCOME_CANCEL, 'cancel');
    }

    public function testWithTargetScopeAppendsQuery(): void
    {
        $url = PaymentBrowserCallbackRoutes::withTargetScope(
            'http://shop.test/payment/frontend/callback/paypal',
            'default.default.default',
        );
        self::assertStringContainsString(PaymentBrowserCallbackRoutes::QUERY_TARGET_SCOPE . '=default.default.default', $url);
        self::assertStringContainsString('/callback/paypal', $url);
        self::assertStringNotContainsString('/callback/return/', $url);
    }

    public function testCatalogCancelAppendsOutcome(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentShellCallbackUrlCatalog.php');
        self::assertStringContainsString('QUERY_OUTCOME', $src);
        self::assertStringContainsString('OUTCOME_CANCEL', $src);
        self::assertStringContainsString('browserReturnRegister', $src);
    }

    public function testReturnContextResolverPrefersShellToken(): void
    {
        $tokenService = new PaymentBrowserCallbackTokenService();
        $token = $tokenService->encode('stripe', 'TXN-9', 'shop.cn.default', 3600);
        $resolver = new PaymentBrowserReturnContextResolver($tokenService);
        $context = $resolver->resolve([
            PaymentBrowserCallbackTokenService::QUERY_SHELL_TOKEN => $token,
            PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE => 'stripe',
            'token' => 'GW-ORDER-1',
        ]);
        self::assertNotNull($context);
        self::assertSame('stripe', $context['method_code']);
        self::assertSame('TXN-9', $context['transaction_no']);
        self::assertSame('GW-ORDER-1', $context['gateway_token']);
        self::assertSame('shell_token', $context['source']);
    }

    public function testReturnContextResolverAcceptsExplicitCodes(): void
    {
        $resolver = new PaymentBrowserReturnContextResolver(new PaymentBrowserCallbackTokenService());
        $context = $resolver->resolve([
            PaymentShellCallbackUrlCatalog::QUERY_METHOD_CODE => 'alipay',
            PaymentShellCallbackUrlCatalog::QUERY_TRANSACTION_NO => 'TXN-88',
            PaymentBrowserCallbackRoutes::QUERY_TARGET_SCOPE => 'default.default.default',
        ]);
        self::assertNotNull($context);
        self::assertSame('alipay', $context['method_code']);
        self::assertSame('TXN-88', $context['transaction_no']);
        self::assertSame('explicit_codes', $context['source']);
    }
}
