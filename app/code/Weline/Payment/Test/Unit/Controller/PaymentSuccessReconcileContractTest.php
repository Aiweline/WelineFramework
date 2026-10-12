<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class PaymentSuccessReconcileContractTest extends TestCase
{
    public function test_success_page_reconciles_captured_unpaid_orders(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Success.php',
        );
        self::assertStringContainsString('reconcileCapturedOrderPaid', $src);
        self::assertStringContainsString('payment_success_page', $src);
        self::assertStringContainsString('notifyOrderPaid', $src);
    }

    public function test_browser_return_logs_notify_failures(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php',
        );
        self::assertStringContainsString('browser return order paid notification failed', $src);
        self::assertStringContainsString('w_log_error', $src);
    }
}
