<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentBrowserReturnDispatcher;

final class PaymentBrowserReturnHangReconcileTest extends TestCase
{
    public function testHangPurposeFromTransactionReadsMetadata(): void
    {
        $dispatcher = (new ReflectionClass(PaymentBrowserReturnDispatcher::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionClass($dispatcher)->getMethod('hangPurposeFromTransaction');
        $method->setAccessible(true);

        $transaction = $this->createMock(PaymentTransaction::class);
        $transaction->method('getRequestData')->willReturn([
            'metadata' => [
                'purpose' => 'balance',
                'hang_purpose' => 'balance',
            ],
        ]);

        self::assertSame('balance', $method->invoke($dispatcher, $transaction));
    }

    public function testDepositPurposeDoesNotFallThroughAsFull(): void
    {
        $dispatcher = (new ReflectionClass(PaymentBrowserReturnDispatcher::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionClass($dispatcher)->getMethod('hangPurposeFromTransaction');
        $method->setAccessible(true);

        $transaction = $this->createMock(PaymentTransaction::class);
        $transaction->method('getRequestData')->willReturn([
            'purpose' => 'deposit',
        ]);

        self::assertSame('deposit', $method->invoke($dispatcher, $transaction));
    }

    public function testNotifyOrderPaidFromTransactionSourceContainsHangReconcile(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php',
        );
        self::assertStringContainsString('reconcileB2bHang', $src);
        self::assertStringContainsString('hangPurposeFromTransaction', $src);
        self::assertStringContainsString("if (\$purpose === 'deposit')", $src);
        self::assertStringContainsString('notifyOrderPaid', $src);
    }
}
