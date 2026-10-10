<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountOrderPaymentRecordsSlotContractTest extends TestCase
{
    public function testAccountOrderDetailProvidesEmptyPaymentRecordsSlot(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('id="account-order-payment-records"', $source);
        self::assertStringContainsString(
            'Weline_Order::frontend::account::order-detail::payment-records',
            $source
        );
        self::assertStringContainsString('account_payment_order_uuid', $source);
        self::assertStringNotContainsString('BackendOrderPaymentRecordsService', $source);
        self::assertStringNotContainsString('weline_payment_attempt', $source);
    }

    public function testOrderDeclaresAccountPaymentRecordsHook(): void
    {
        $path = dirname(__DIR__, 3) . '/hook.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            "'Weline_Order::frontend::account::order-detail::payment-records'",
            $source
        );
        self::assertFileExists(
            dirname(__DIR__, 3)
            . '/doc/hook/frontend/account/order-detail/payment-records.md'
        );
    }
}
