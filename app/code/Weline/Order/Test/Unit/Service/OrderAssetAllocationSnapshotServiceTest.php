<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\OrderAssetAllocationSnapshotService;

final class OrderAssetAllocationSnapshotServiceTest extends TestCase
{
    public function testCommittedSnapshotIsImmutableAndIdempotent(): void
    {
        $service = OrderAssetAllocationSnapshotService::forTesting();
        $first = $service->recordCommittedAllocations(
            'weline_order',
            'ord-snapshot',
            'pi-1',
            'pa-1',
            [$this->allocation()],
            'attempt:pa-1:asset:commit:v1',
        );
        $replayed = $service->recordCommittedAllocations(
            'weline_order',
            'ord-snapshot',
            'pi-1',
            'pa-1',
            [$this->allocation()],
            'attempt:pa-1:asset:commit:v1',
        );

        self::assertTrue($first['ok']);
        self::assertFalse($first['snapshots'][0]['replayed']);
        self::assertTrue($replayed['snapshots'][0]['replayed']);
        self::assertCount(1, $service->listForOrder('ord-snapshot'));

        $changed = $this->allocation();
        $changed['amount_minor'] = 301;
        try {
            $service->recordCommittedAllocations(
                'weline_order',
                'ord-snapshot',
                'pi-1',
                'pa-1',
                [$changed],
                'attempt:pa-1:asset:commit:v1',
            );
            self::fail('Expected immutable snapshot conflict');
        } catch (\LogicException $exception) {
            self::assertStringStartsWith(
                'order_asset_snapshot_immutable_conflict:',
                $exception->getMessage(),
            );
        }
        self::assertSame(
            300,
            $service->listForOrder('ord-snapshot')[0]['amount_minor'],
        );
    }

    public function testNonOrderPayableIsNotApplicable(): void
    {
        $service = OrderAssetAllocationSnapshotService::forTesting();
        $result = $service->recordCommittedAllocations(
            'subscription',
            'sub-1',
            'pi-1',
            null,
            [$this->allocation()],
            'intent:pi-1:asset:commit:v1',
        );

        self::assertTrue($result['ok']);
        self::assertTrue($result['not_applicable']);
        self::assertSame([], $service->listForOrder('sub-1'));
    }

    public function testRejectsNonCommittedAllocation(): void
    {
        $service = OrderAssetAllocationSnapshotService::forTesting();
        $allocation = $this->allocation();
        $allocation['status'] = 'reserved';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'order_asset_snapshot_requires_committed_allocation',
        );
        $service->recordCommittedAllocations(
            'weline_order',
            'ord-snapshot',
            'pi-1',
            'pa-1',
            [$allocation],
            'attempt:pa-1:asset:commit:v1',
        );
    }

    public function testPartialRefundsUseCumulativeDeterministicAllocation(): void
    {
        $service = OrderAssetAllocationSnapshotService::forTesting();
        $second = $this->allocation();
        $second['allocation_code'] = 'asset-allocation-2';
        $second['reservation_id'] = 'rsv-2';
        $second['asset_code'] = 'points';
        $second['source_code'] = 'points';
        $second['asset_amount_minor'] = 400;
        $second['amount_minor'] = 200;
        $service->recordCommittedAllocations(
            'order',
            'ord-snapshot',
            'pi-1',
            'pa-1',
            [$this->allocation(), $second],
            'attempt:pa-1:asset:commit:v1',
        );

        $first = $service->allocateRefund('ord-snapshot', 1000, 300);
        self::assertSame(150, $first['cash_amount_minor']);
        self::assertSame(150, $first['asset_amount_minor']);
        self::assertSame(
            [90, 60],
            array_column($first['asset_allocations'], 'payment_refund_amount_minor'),
        );
        self::assertSame(
            [90, 120],
            array_column($first['asset_allocations'], 'asset_return_amount_minor'),
        );

        $secondRefund = $service->allocateRefund(
            'ord-snapshot',
            1000,
            200,
            300,
            $first['asset_allocations'],
        );
        self::assertSame(100, $secondRefund['cash_amount_minor']);
        self::assertSame(100, $secondRefund['asset_amount_minor']);
        self::assertSame(
            [60, 40],
            array_column(
                $secondRefund['asset_allocations'],
                'payment_refund_amount_minor',
            ),
        );
        self::assertSame(
            [60, 80],
            array_column(
                $secondRefund['asset_allocations'],
                'asset_return_amount_minor',
            ),
        );

        $final = $service->allocateRefund(
            'ord-snapshot',
            1000,
            500,
            500,
            [
                ...$first['asset_allocations'],
                ...$secondRefund['asset_allocations'],
            ],
        );
        self::assertSame(250, $final['cash_amount_minor']);
        self::assertSame(250, $final['asset_amount_minor']);
        self::assertSame(
            [150, 100],
            array_column($final['asset_allocations'], 'payment_refund_amount_minor'),
        );
        self::assertSame(
            [150, 200],
            array_column($final['asset_allocations'], 'asset_return_amount_minor'),
        );
    }

    public function testAssetOnlyRefundProducesNoCashTail(): void
    {
        $service = OrderAssetAllocationSnapshotService::forTesting();
        $service->recordCommittedAllocations(
            'order',
            'ord-snapshot',
            'pi-1',
            null,
            [$this->allocation()],
            'intent:pi-1:asset:commit:v1',
        );

        $result = $service->allocateRefund('ord-snapshot', 300, 120);

        self::assertSame(0, $result['cash_amount_minor']);
        self::assertSame(120, $result['asset_amount_minor']);
        self::assertSame(
            120,
            $result['asset_allocations'][0]['asset_return_amount_minor'],
        );
    }

    public function testPreviousRefundDriftFailsClosed(): void
    {
        $service = OrderAssetAllocationSnapshotService::forTesting();
        $service->recordCommittedAllocations(
            'order',
            'ord-snapshot',
            'pi-1',
            'pa-1',
            [$this->allocation()],
            'attempt:pa-1:asset:commit:v1',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'order_asset_refund_previous_payment_drift:asset-allocation-1',
        );
        $service->allocateRefund(
            'ord-snapshot',
            600,
            100,
            100,
            [[
                'allocation_code' => 'asset-allocation-1',
                'payment_refund_amount_minor' => 49,
                'asset_return_amount_minor' => 49,
            ]],
        );
    }

    /** @return array<string, mixed> */
    private function allocation(): array
    {
        return [
            'allocation_code' => 'asset-allocation-1',
            'customer_id' => '42',
            'website_id' => 0,
            'asset_code' => 'credit',
            'source_code' => 'credit',
            'role' => 'payment',
            'namespace' => 'live',
            'reservation_id' => 'rsv-1',
            'asset_amount_minor' => 300,
            'amount_minor' => 300,
            'currency_code' => 'CNY',
            'precision' => 2,
            'status' => 'committed',
        ];
    }
}
