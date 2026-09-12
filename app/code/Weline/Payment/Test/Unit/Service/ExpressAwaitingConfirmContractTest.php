<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\PaymentExpressFacadeInterface;
use Weline\Payment\Service\ExpressCheckoutOrchestrator;
use Weline\Payment\Service\PaymentBrowserReturnLandingOrchestrator;

final class ExpressAwaitingConfirmContractTest extends TestCase
{
    public function testEvaluateExpressProfileCoreAndGaps(): void
    {
        $orch = new ExpressCheckoutOrchestrator();
        $incomplete = $orch->evaluateExpressProfile([
            'country_code' => 'US',
        ]);
        self::assertFalse($incomplete['complete']);
        self::assertContains('contact_name', $incomplete['missing_fields']);
        self::assertContains('address1', $incomplete['missing_fields']);

        $core = $orch->evaluateExpressProfile([
            'contact_name' => 'Ada',
            'address1' => '1 St',
            'country_code' => 'US',
        ]);
        self::assertTrue($core['complete']);
        self::assertContains('contact_phone', $core['missing_fields']);
        self::assertSame(PaymentExpressFacadeInterface::META_AWAITING_CONFIRM, ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM);
        self::assertTrue(ExpressCheckoutOrchestrator::isExpressAwaitingConfirm([
            'metadata' => [ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM => 1],
        ]));
    }

    public function testLandingOrchestratorDefinesExpressReview(): void
    {
        self::assertSame('express_review', PaymentBrowserReturnLandingOrchestrator::DECISION_EXPRESS_REVIEW);
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnLandingOrchestrator.php');
        self::assertStringContainsString('checkout/express-review', $src);
        self::assertStringContainsString('decideExpressReview', $src);
    }

    public function testPayPalExpressUsesContinueAndPatchOrder(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php'
        );
        self::assertStringContainsString("'user_action' => \$express ? 'CONTINUE' : 'PAY_NOW'", $provider);
        self::assertStringContainsString('express_prepare_only', $provider);
        self::assertStringContainsString('express_confirm_capture', $provider);
        $client = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PayPalApiClient.php');
        self::assertStringContainsString('function patchOrder', $client);
    }

    public function testProductExpressJsOpensProviderWindow(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/product-express-pay.js');
        self::assertStringContainsString('startExpressCheckout', $js);
        self::assertStringContainsString('weline_express_pay', $js);
        self::assertStringContainsString('openProviderWindow', $js);
        self::assertStringNotContainsString('buyNow.click', $js);
        self::assertStringNotContainsString("searchParams.set('express_pay'", $js);
        self::assertStringNotContainsString('location.assign(target)', $js);
    }
}
