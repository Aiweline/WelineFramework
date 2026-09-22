<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentService;

final class PaymentServiceRefundMinorContractTest extends TestCase
{
    public function testRefundPrimarySignatureUsesAmountMinorInt(): void
    {
        $ref = new \ReflectionMethod(PaymentService::class, 'refund');
        $params = $ref->getParameters();
        self::assertCount(3, $params);
        self::assertSame('transactionNo', $params[0]->getName());
        self::assertSame('amountMinor', $params[1]->getName());
        self::assertSame('int', (string) $params[1]->getType());
        self::assertSame('reason', $params[2]->getName());

        self::assertTrue(method_exists(PaymentService::class, 'refundWithMajorAmount'));
        $deprecated = new \ReflectionMethod(PaymentService::class, 'refundWithMajorAmount');
        self::assertSame('float', (string) $deprecated->getParameters()[1]->getType());

        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentService.php');
        self::assertStringContainsString('function refund(string $transactionNo, int $amountMinor', $src);
        self::assertStringNotContainsString(
            'function refund(string $transactionNo, float $amount',
            $src
        );
        self::assertStringContainsString('@deprecated', $src);
        self::assertStringContainsString('refundWithMajorAmount', $src);
    }
}
