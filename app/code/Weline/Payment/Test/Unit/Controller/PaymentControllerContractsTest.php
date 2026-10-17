<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllPaymentControllerContractsPass().
 */
final class PaymentControllerContractsTest extends TestCase
{
    public function testAllPaymentControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PaymentCallbackLegacyPath::testRouterBlocksLegacyAndDotCancelPaths' => function () {
            $this->assert_PaymentCallbackLegacyPath_testRouterBlocksLegacyAndDotCancelPaths();
        },
            'PaymentCallbackLegacyPath::testPublicRoutesShareSingleMethodPath' => function () {
            $this->assert_PaymentCallbackLegacyPath_testPublicRoutesShareSingleMethodPath();
        },
            'PaymentCallbackLegacyPath::testCallbackControllerBranchesOnOutcome' => function () {
            $this->assert_PaymentCallbackLegacyPath_testCallbackControllerBranchesOnOutcome();
        },
            'PaymentSuccessReconcile::test_success_page_reconciles_captured_unpaid_orders' => function () {
            $this->assert_PaymentSuccessReconcile_test_success_page_reconciles_captured_unpaid_orders();
        },
            'PaymentSuccessReconcile::test_browser_return_logs_notify_failures' => function () {
            $this->assert_PaymentSuccessReconcile_test_browser_return_logs_notify_failures();
        },
        ];
    }

private function assert_PaymentCallbackLegacyPath_testRouterBlocksLegacyAndDotCancelPaths(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Router.php');
    self::assertStringContainsString("str_starts_with(\$normalizedPath, 'payment/frontend/callback/return/')", $src);
    self::assertStringContainsString("str_starts_with(\$normalizedPath, 'payment/frontend/callback/cancel/')", $src);
    self::assertStringContainsString("'payment/frontend/callback/__removed__'", $src);
    self::assertStringContainsString('outcome=cancel', $src);
    self::assertStringContainsString('\.cancel', $src);
}

private function assert_PaymentCallbackLegacyPath_testPublicRoutesShareSingleMethodPath(): void
{
    self::assertSame('payment/frontend/callback/paypal', PaymentBrowserCallbackRoutes::returnRoute('paypal'));
    self::assertSame(PaymentBrowserCallbackRoutes::returnRoute('paypal'), PaymentBrowserCallbackRoutes::cancelRoute('paypal'));
    self::assertTrue(PaymentBrowserCallbackRoutes::isCancelOutcome([PaymentBrowserCallbackRoutes::QUERY_OUTCOME => 'cancel']));
    self::assertFalse(PaymentBrowserCallbackRoutes::isCancelOutcome([]));
}

private function assert_PaymentCallbackLegacyPath_testCallbackControllerBranchesOnOutcome(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/Callback.php');
    self::assertStringContainsString('function browserReturnEntry()', $src);
    self::assertStringContainsString('isCancelOutcome', $src);
    self::assertStringContainsString('function browserCancelEntry()', $src);
    self::assertStringNotContainsString('function return()', $src);
    self::assertStringNotContainsString('function cancel()', $src);
}

private function assert_PaymentSuccessReconcile_test_success_page_reconciles_captured_unpaid_orders(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Success.php');
    self::assertStringContainsString('reconcileCapturedOrderPaid', $src);
    self::assertStringContainsString('payment_success_page', $src);
    self::assertStringContainsString('notifyOrderPaid', $src);
}

private function assert_PaymentSuccessReconcile_test_browser_return_logs_notify_failures(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnDispatcher.php');
    self::assertStringContainsString('browser return order paid notification failed', $src);
    self::assertStringContainsString('w_log_error', $src);
}
}
