<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderPaymentRecordsSlotContractTest extends TestCase
{
    public function testOrderDetailProvidesEmptyPaymentRecordsSlotWithoutHardcodedPaymentTable(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/view.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('id="backend-order-payment-records"', $source);
        self::assertStringContainsString('accept="backend-order-payment-records,payment-records,payment"', $source);
        self::assertStringContainsString(
            'Weline_Order::backend::order::view::payment-records',
            $source
        );
        self::assertStringNotContainsString('OrderPayment::schema_fields_PAYMENT_METHOD', $source);
        self::assertStringNotContainsString("getData('payments')", $source);
        self::assertStringNotContainsString('<w:widget', $source);
    }

    public function testOrderDeclaresPaymentRecordsHook(): void
    {
        $path = dirname(__DIR__, 3) . '/hook.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            "'Weline_Order::backend::order::view::payment-records'",
            $source
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/doc/hook/backend/order/view/payment-records.md'
        );
    }

    public function testOrderDoesNotOwnPaymentAttemptResolver(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/BackendOrderPaymentHistoryResolver.php';
        self::assertFileDoesNotExist($path);
    }
}
