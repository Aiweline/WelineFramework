<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Points\Service\OrderPointsContext;

final class OrderPointsContextTest extends TestCase
{
    public function testCashPaidExcludesAssetTender(): void
    {
        $context = (new OrderPointsContext())->fromPaidEvent([
            'order' => [
                'order_id' => 10,
                'customer_id' => 7,
                'website_id' => 3,
                'order_uuid' => 'uuid-10',
                'currency' => 'USD',
                'checkout_entry' => 'checkout',
                'money_snapshot_json' => json_encode(['grand_total_minor' => 5000]),
            ],
            'payment' => ['payment_id' => 99],
        ], 1200);

        self::assertSame(5000, $context['total_minor']);
        self::assertSame(1200, $context['asset_tender_minor']);
        self::assertSame(3800, $context['cash_minor']);
        self::assertSame('99', $context['payment_key']);
        self::assertSame('live', $context['namespace']);
    }

    public function testGrandTotalFallsBackToMajorUnits(): void
    {
        $context = (new OrderPointsContext())->fromPaidEvent([
            'order' => [
                'order_id' => 11,
                'customer_id' => 8,
                'grand_total' => 12.34,
                'currency' => 'CNY',
            ],
        ]);

        self::assertSame(1234, $context['total_minor']);
        self::assertSame(1234, $context['cash_minor']);
    }

    public function testZeroDecimalCurrencyUsesWholeUnits(): void
    {
        $context = (new OrderPointsContext())->fromPaidEvent([
            'order' => [
                'order_id' => 13,
                'customer_id' => 10,
                'grand_total' => 1500,
                'currency' => 'JPY',
            ],
        ]);

        self::assertSame(0, $context['precision']);
        self::assertSame(1500, $context['cash_minor']);
    }

    public function testSandboxNamespaceFromOrder(): void
    {
        $context = (new OrderPointsContext())->fromPaidEvent([
            'order' => ['order_id' => 12, 'customer_id' => 9, 'is_sandbox' => 1],
        ]);

        self::assertSame('sandbox', $context['namespace']);
    }

    public function testRefundAmountUsesEarnCurrencyPrecision(): void
    {
        $paid = [
            'order_id' => 20,
            'customer_id' => 5,
            'website_id' => 1,
            'namespace' => 'live',
            'precision' => 0,
            'cash_minor' => 1500,
        ];
        $context = (new OrderPointsContext())->fromRefundEvent([
            'refund' => ['payment_id' => 44],
            'refund_amount' => 500,
        ], $paid);

        self::assertSame(500, $context['refund_amount_minor']);
        self::assertSame('44', $context['refund_key']);
    }
}
