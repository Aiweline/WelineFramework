<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentStorefrontLandingUrlService;

/**
 * Express paid handoff must not rewrite to host-root /checkout/success on path-prefix sites.
 */
final class PaymentBrowserHandoffMountContractTest extends TestCase
{
    public function testExpressToSuccessRewriteKeepsDaocharmsMount(): void
    {
        $urls = new PaymentStorefrontLandingUrlService();
        $hint = 'https://p05113ef3.test.weline.com/daocharms/checkout/express-review?transaction_no=PAY-DAO-1';
        $base = $urls->resolveStorefrontBaseUrl(
            7,
            'https://p05113ef3.test.weline.com/daocharms',
            $hint,
        );
        $success = $urls->buildAbsoluteRoute(
            'checkout/success',
            [
                'checkout_group_uuid' => 'grp-dao-1',
                'checkout_token' => 'qt_dao_1',
                'order_uuid' => 'ord-dao-1',
            ],
            $base,
            $hint,
            7,
        );
        $success = $urls->ensureLandingUnderBase($success, $base);

        self::assertStringContainsString('/daocharms/checkout/success', $success);
        self::assertStringContainsString('order_uuid=ord-dao-1', $success);
        self::assertStringNotContainsString('express-review', $success);
        self::assertDoesNotMatchRegularExpression(
            '#https?://[^/]+/checkout/success#',
            $success,
        );
    }

    public function testBareHostRootSuccessIsRebuiltUnderFrozenMount(): void
    {
        $urls = new PaymentStorefrontLandingUrlService();
        $fixed = $urls->ensureLandingUnderBase(
            '/checkout/success?order_uuid=ord-dao-2',
            'https://p05113ef3.test.weline.com/daocharms',
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com/daocharms/checkout/success?order_uuid=ord-dao-2',
            $fixed,
        );
    }

    public function testHandoffSourceForbidsBareHostRootSuccessRewrite(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentBrowserHandoffReadinessService.php',
        );
        self::assertStringNotContainsString("\$path = '/checkout/success';", $src);
        self::assertStringContainsString('PaymentStorefrontLandingUrlService', $src);
        self::assertStringContainsString('buildAbsoluteRoute', $src);
        self::assertStringContainsString('ensureLandingUnderBase', $src);
    }

    public function testPersistenceFreezesWebsiteContextKeys(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentCheckoutSessionPersistenceService.php',
        );
        self::assertStringContainsString('CONTEXT_WEBSITE_ID', $src);
        self::assertStringContainsString('CONTEXT_WEBSITE_CODE', $src);
        self::assertStringContainsString('CONTEXT_STOREFRONT_BASE_URL', $src);
        self::assertStringContainsString('resolveWebsiteFreeze', $src);
    }

    public function testScopeConfigResolvesWebsiteId(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentScopeConfigService.php',
        );
        self::assertStringContainsString('resolveWebsiteCodeFromId', $src);
        self::assertStringContainsString("\$context['website_id']", $src);
    }

    public function testOrchestratorBindsAbsoluteUnderStorefront(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnLandingOrchestrator.php',
        );
        self::assertStringContainsString('bindAbsoluteIfPossible', $src);
        self::assertStringContainsString('PaymentStorefrontLandingUrlService', $src);
        self::assertStringContainsString('ensureLandingUnderBase', $src);
    }

    public function testCallbackCatalogHonorsStorefrontBase(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentShellCallbackUrlCatalog.php',
        );
        self::assertStringContainsString('activeStorefrontBase', $src);
        self::assertStringContainsString('resolveStorefrontBaseFromScope', $src);
        self::assertStringContainsString('storefront_base_url', $src);
    }
}
