<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentBrowserReturnLandingOrchestrator;

final class PaymentBrowserReturnLandingContractTest extends TestCase
{
    public function testDispatcherSourceDoesNotRedirectToCheckoutReturn(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php');
        self::assertStringNotContainsString("'redirect_path' => 'payment/frontend/checkout/return'", $src);
        self::assertStringContainsString('PaymentBrowserReturnLandingOrchestrator', $src);
    }

    public function testLandingOrchestratorDefinesL1AndL2Paths(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnLandingOrchestrator.php');
        self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_TERMINAL_L1, $src);
        self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_HANDOFF_L2, $src);
        self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_CHECKOUT_LANDING_CANCEL, $src);
        self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_EXPRESS_REVIEW, $src);
        self::assertStringContainsString('function decideCancel', $src);
        self::assertStringContainsString('function decideExpressReview', $src);
        self::assertStringContainsString('checkout/express-review', $src);
        self::assertStringContainsString('checkout/success', $src);
        self::assertStringContainsString('payment/success', $src);
        self::assertStringContainsString('payment/handoff', $src);
        self::assertStringNotContainsString('payment/frontend/checkout/success-page', $src);
        self::assertStringNotContainsString("'redirect_path' => 'payment/frontend/checkout/handoff'", $src);
        self::assertFileExists(dirname(__DIR__, 3) . '/Controller/Success.php');
        self::assertFileExists(dirname(__DIR__, 3) . '/Controller/Handoff.php');
        $frontendCheckout = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Checkout.php');
        self::assertStringNotContainsString('function successPage', $frontendCheckout);
        self::assertStringNotContainsString('function handoff(', $frontendCheckout);
        self::assertStringNotContainsString('function handoffStatus', $frontendCheckout);
        self::assertStringContainsString('payment_return_cancelled', $frontendCheckout);
        self::assertStringContainsString('isCancelOutcome', $frontendCheckout);

        $cancelSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserCancelDispatcher.php');
        self::assertStringContainsString('decideCancel', $cancelSrc);
        self::assertStringNotContainsString('->decide(', $cancelSrc);

        $returnTpl = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/checkout/return.phtml');
        self::assertStringContainsString('payment_return_cancelled', $returnTpl);
        self::assertStringContainsString('已取消成功', $returnTpl);
        self::assertStringContainsString('已取消', $returnTpl);
        self::assertStringContainsString('payment_cancel_already', $returnTpl);

        $routesSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserCallbackRoutes.php');
        self::assertStringContainsString('CANCEL_STATE_ALREADY', $routesSrc);
        self::assertStringContainsString('CANCEL_STATE_DONE', $routesSrc);
        self::assertStringContainsString('QUERY_CANCEL_STATE', $routesSrc);
    }
}
