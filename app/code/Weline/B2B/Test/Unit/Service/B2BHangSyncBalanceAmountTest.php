<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangOrderService;

final class B2BHangSyncBalanceAmountTest extends TestCase
{
    public function testSyncBalanceAmountMinorUpdatesAwaitingMerchantHang(): void
    {
        $svc = B2BHangOrderService::forTesting(static fn (): int => 1_700_000_000);
        $created = $svc->createAwaitingDeposit([
            'order_ref' => 'ord-sync-bal-1',
            'customer_id' => '85',
            'website_id' => 0,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 0,
            'is_shipping_owner' => true,
            'deposit_ratio_bps' => 3000,
            'token_ids' => [],
        ]);
        self::assertSame(3000, (int)$created['deposit_amount_minor']);
        self::assertSame(7000, (int)$created['balance_amount_minor']);

        $svc->onDepositPaid('ord-sync-bal-1', 'dep-1', static function (): void {
        });
        $hang = $svc->getByOrderRef('ord-sync-bal-1');
        self::assertNotNull($hang);
        self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $hang->hangStatus);

        $synced = $svc->syncBalanceAmountMinor('ord-sync-bal-1', 6500);
        self::assertNotNull($synced);
        self::assertSame(6500, $synced->balanceAmountMinor);
        self::assertSame(6500, (int)$svc->getByOrderRef('ord-sync-bal-1')?->balanceAmountMinor);
    }

    public function testSyncBalanceSkipsCompletedHang(): void
    {
        $svc = B2BHangOrderService::forTesting(static fn (): int => 1_700_000_100);
        $svc->createAwaitingDeposit([
            'order_ref' => 'ord-sync-bal-done',
            'customer_id' => '1',
            'website_id' => 0,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 0,
            'is_shipping_owner' => true,
            'deposit_ratio_bps' => 3000,
            'token_ids' => [],
        ]);
        $svc->onDepositPaid('ord-sync-bal-done', 'dep-2', static function (): void {
        });
        $svc->approve('ord-sync-bal-done');
        $beforeBalance = (int)$svc->getByOrderRef('ord-sync-bal-done')?->balanceAmountMinor;
        $svc->onBalancePaid('ord-sync-bal-done', 'bal-1');
        $before = $svc->getByOrderRef('ord-sync-bal-done');
        self::assertSame(B2BOrderHang::STATUS_COMPLETED, $before?->hangStatus);

        $synced = $svc->syncBalanceAmountMinor('ord-sync-bal-done', 1);
        self::assertSame($beforeBalance, $synced?->balanceAmountMinor);
    }
}
