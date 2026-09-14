<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: checkout submit must pass selected payment_method into Order create options.
 */
final class CheckoutSubmitPaymentMethodPersistContractTest extends TestCase
{
    public function testSubmitLockedPassesPaymentMethodIntoCreateCommandOptions(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString("'payment_method' => \$resolvedPaymentMethod", $src);
        self::assertStringContainsString(
            '$resolvedPaymentMethod = strtolower(trim((string)($paymentMethod ?? $session[\'payment_method\'] ?? \'\')));',
            $src,
        );
    }

    public function testHangDepositPartialBackfillsPaymentMethod(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutOrderPaymentService.php'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('rememberOrderPaymentMethod', $src);
        self::assertStringContainsString(
            'markOrderPaymentPartial($orderUuid, $methodCode)',
            $src,
        );
        self::assertStringContainsString(
            'Order::schema_fields_PAYMENT_METHOD, $methodCode',
            $src,
        );
    }
}
