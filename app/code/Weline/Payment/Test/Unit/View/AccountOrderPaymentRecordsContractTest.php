<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountOrderPaymentRecordsContractTest extends TestCase
{
    public function testPaymentProvidesAccountOrderPaymentRecordsHook(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Order/frontend/account/order-detail/payment-records.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            'Weline_Payment::templates/Frontend/account/order-payment-records.phtml',
            $source
        );
    }

    public function testAccountTemplateShowsPaymentEntryBadge(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/templates/Frontend/account/order-payment-records.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('BackendOrderPaymentRecordsService', $source);
        self::assertStringContainsString('listForPayable', $source);
        self::assertStringContainsString('data-testid="account-order-payment-records"', $source);
        self::assertStringContainsString('data-testid="account-order-payment-entry"', $source);
        self::assertStringContainsString('payment_entry_label', $source);
        self::assertStringContainsString('payment_entry', $source);
    }
}
