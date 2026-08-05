<?php

declare(strict_types=1);

namespace Weline\Vendor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Service\VendorConflictException;
use Weline\Vendor\Service\VendorSettlementService;
use Weline\Vendor\Service\VendorSplitSnapshotStore;
use Weline\Websites\Api\Catalog\Data\StoreSummary;

/**
 * TASK-P4A-002 / TEST-P4A-02, TEST-P4A-03 and TEST-P4A-05：
 * split 守恒、旧快照退款冲正、payout、报表环境隔离、mode off.
 */
final class VendorSplitPayoutReversalTest extends TestCase
{
    private VendorSettlementService $settlement;
    private string $vendorId;
    private int $storeId = 701;

    protected function setUp(): void
    {
        $this->settlement = VendorSettlementService::forTesting();
        $this->settlement->rollout()->setMode(
            VendorSettlementService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        $vendors = $this->settlement->vendors();
        $vendors->acl()->replaceGrantsForTesting([
            new ObjectScopeGrantRecord(
                9,
                false,
                ScopeIdentity::KIND_WEBSITE,
                0,
                'default',
                null,
                null,
                [ObjectAction::LIST, ObjectAction::VIEW, ObjectAction::CREATE, ObjectAction::UPDATE],
                1,
            ),
        ]);
        $vendor = $vendors->registerVendor([
            'code' => 'split_demo',
            'legal_name' => 'Split Demo Ltd',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 0, 'default');
        $this->vendorId = (string) $vendor['vendor_id'];
        $vendors->authorizeWebsite($this->vendorId, 0, 9, 'default');
        $vendors->accounts()->registerStoreForTesting(new StoreSummary(
            $this->storeId,
            0,
            'split-test',
            'Split Test Store',
            'test',
            false,
            true,
            'active',
            null,
        ));
        $vendors->bindAccount([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'store_id' => $this->storeId,
            'environment' => VendorIdentity::ENV_SANDBOX,
            'account_ref' => 'sandbox:split_demo',
        ], 9, 'default');
        $this->settlement->upsertRule([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'commission_bps' => 1500, // 15% platform
            'currency' => 'CNY',
            'legal_entity' => 'Split Demo Legal',
        ]);
    }

    public function testCaptureSnapshotConservesAmountsAndIsImmutable(): void
    {
        $snap = $this->settlement->captureSnapshot([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'store_id' => $this->storeId,
            'order_ref' => 'ord-1',
            'payment_ref' => 'pay-1',
            'gross_minor' => 10000,
            'required_environment' => VendorIdentity::ENV_SANDBOX,
        ]);

        self::assertSame(10000, $snap['gross_minor']);
        self::assertSame(1500, $snap['platform_share_minor']);
        self::assertSame(8500, $snap['vendor_share_minor']);
        self::assertSame(10000, $snap['vendor_share_minor'] + $snap['platform_share_minor']);
        self::assertSame(VendorIdentity::ENV_SANDBOX, $snap['environment']);
        self::assertNotSame('', $snap['payload_hash']);
        self::assertSame('Split Demo Legal', $snap['legal']['legal_entity']);

        $hashBefore = $snap['payload_hash'];
        try {
            $this->settlement->snapshots()->update($snap['snapshot_id'], ['gross_minor' => 1]);
            self::fail('expected immutable');
        } catch (VendorConflictException $e) {
            self::assertSame(VendorSplitSnapshotStore::ERROR_IMMUTABLE, $e->errorCode);
        }
        self::assertSame($hashBefore, $this->settlement->snapshots()->get($snap['snapshot_id'])['payload_hash']);

        // Duplicate capture for same payable forbidden (no recalculation).
        try {
            $this->settlement->captureSnapshot([
                'vendor_id' => $this->vendorId,
                'website_id' => 0,
                'store_id' => $this->storeId,
                'order_ref' => 'ord-1',
                'payment_ref' => 'pay-1',
                'gross_minor' => 10000,
            ]);
            self::fail('expected duplicate deny');
        } catch (VendorConflictException $e) {
            self::assertSame(VendorSplitSnapshotStore::ERROR_EXISTS, $e->errorCode);
        }
    }

    public function testPayoutAndPartialRefundReversalConserveNet(): void
    {
        $snap = $this->settlement->captureSnapshot([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'store_id' => $this->storeId,
            'order_ref' => 'ord-2',
            'payment_ref' => 'pay-2',
            'gross_minor' => 10000,
        ]);
        $payout = $this->settlement->schedulePayout($snap['snapshot_id'], 'idem-2');
        self::assertSame(8500, $payout['amount_minor']);
        self::assertSame(8500, $payout['net_minor']);
        $this->settlement->payouts()->markPaid($payout['payout_id']);

        $rev = $this->settlement->reverseRefund([
            'payout_id' => $payout['payout_id'],
            'refund_ref' => 'rf-partial-1',
            'amount_minor' => 2500,
            'reason' => 'partial_refund',
        ]);
        self::assertTrue($rev['ok']);
        self::assertTrue($rev['snapshot_unchanged']);
        self::assertSame($snap['payload_hash'], $rev['snapshot_hash']);
        self::assertSame(6000, $rev['payout']['net_minor']);
        self::assertSame('partially_reversed', $rev['payout']['status']);

        $report = $this->settlement->reconcileReport($this->vendorId);
        self::assertTrue($report['ok']);
        self::assertTrue($report['conserved']);
        self::assertSame(8500, $report['gross_payout_minor']);
        self::assertSame(2500, $report['reversed_minor']);
        self::assertSame(6000, $report['net_minor']);
    }

    public function testOverReversalIsRejected(): void
    {
        $snap = $this->settlement->captureSnapshot([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'store_id' => $this->storeId,
            'order_ref' => 'ord-3',
            'payment_ref' => 'pay-3',
            'gross_minor' => 1000,
        ]);
        $payout = $this->settlement->schedulePayout($snap['snapshot_id']);

        $this->expectException(VendorConflictException::class);
        $this->settlement->reverseRefund([
            'payout_id' => $payout['payout_id'],
            'refund_ref' => 'rf-over',
            'amount_minor' => 99999,
        ]);
    }

    public function testModeOffBlocksNewSplitButAllowsExistingSettlement(): void
    {
        $snap = $this->settlement->captureSnapshot([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'store_id' => $this->storeId,
            'order_ref' => 'ord-4',
            'payment_ref' => 'pay-4',
            'gross_minor' => 5000,
        ]);

        $this->settlement->rollout()->setMode(
            VendorSettlementService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );

        try {
            $this->settlement->captureSnapshot([
                'vendor_id' => $this->vendorId,
                'website_id' => 0,
                'store_id' => $this->storeId,
                'order_ref' => 'ord-5',
                'payment_ref' => 'pay-5',
                'gross_minor' => 1000,
            ]);
            self::fail('expected mode off new split block');
        } catch (VendorConflictException $e) {
            self::assertSame(VendorSettlementService::ERROR_MODE_OFF_NEW_SPLIT, $e->errorCode);
        }

        try {
            $this->settlement->upsertRule([
                'vendor_id' => $this->vendorId,
                'website_id' => 0,
                'commission_bps' => 1000,
            ]);
            self::fail('expected mode off rule block');
        } catch (VendorConflictException $e) {
            self::assertSame(VendorSettlementService::ERROR_MODE_OFF_NEW_SPLIT, $e->errorCode);
        }

        // Existing obligation continues.
        $payout = $this->settlement->schedulePayout($snap['snapshot_id']);
        self::assertSame(4250, $payout['amount_minor']); // 5000 - 15%
        $rev = $this->settlement->reverseRefund([
            'payout_id' => $payout['payout_id'],
            'refund_ref' => 'rf-mode-off',
            'amount_minor' => 4250,
        ]);
        self::assertSame(0, $rev['payout']['net_minor']);
        self::assertSame('reversed', $rev['payout']['status']);
        self::assertTrue($this->settlement->reconcileReport($this->vendorId)['conserved']);
    }

    public function testRuleChangeDoesNotMutateOldSnapshot(): void
    {
        $snap = $this->settlement->captureSnapshot([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'store_id' => $this->storeId,
            'order_ref' => 'ord-6',
            'payment_ref' => 'pay-6',
            'gross_minor' => 10000,
        ]);
        self::assertSame(1500, $snap['commission_bps']);

        $this->settlement->upsertRule([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'commission_bps' => 2000,
        ]);
        $still = $this->settlement->snapshots()->get($snap['snapshot_id']);
        self::assertSame(1500, $still['commission_bps']);
        self::assertSame(8500, $still['vendor_share_minor']);
        self::assertSame($snap['payload_hash'], $still['payload_hash']);
    }

    public function testSettlementReportsHardIsolateNormalLiveFromTestSandbox(): void
    {
        $sandboxSnapshot = $this->settlement->captureSnapshot([
            'vendor_id' => $this->vendorId,
            'website_id' => 0,
            'store_id' => $this->storeId,
            'order_ref' => 'ord-report-test',
            'payment_ref' => 'pay-report-test',
            'gross_minor' => 1000,
        ]);
        $this->settlement->schedulePayout($sandboxSnapshot['snapshot_id'], 'report-test');

        $vendors = $this->settlement->vendors();
        $liveStoreId = 702;
        $vendors->accounts()->registerStoreForTesting(new StoreSummary(
            $liveStoreId,
            0,
            'split-normal',
            'Split Normal Store',
            'normal',
            false,
            true,
            'active',
            null,
        ));
        $live = $vendors->registerVendor([
            'code' => 'split_live',
            'legal_name' => 'Split Live Ltd',
            'environment' => VendorIdentity::ENV_LIVE,
        ], 9, 0, 'default');
        $liveVendorId = (string) $live['vendor_id'];
        $vendors->authorizeWebsite($liveVendorId, 0, 9, 'default');
        $vendors->bindAccount([
            'vendor_id' => $liveVendorId,
            'website_id' => 0,
            'store_id' => $liveStoreId,
            'environment' => VendorIdentity::ENV_LIVE,
            'account_ref' => 'live:split_live',
        ], 9, 'default');
        $this->settlement->upsertRule([
            'vendor_id' => $liveVendorId,
            'website_id' => 0,
            'commission_bps' => 1000,
            'currency' => 'CNY',
        ]);
        $liveSnapshot = $this->settlement->captureSnapshot([
            'vendor_id' => $liveVendorId,
            'website_id' => 0,
            'store_id' => $liveStoreId,
            'order_ref' => 'ord-report-live',
            'payment_ref' => 'pay-report-live',
            'gross_minor' => 2000,
        ]);
        $this->settlement->schedulePayout($liveSnapshot['snapshot_id'], 'report-live');

        $normal = $this->settlement->reconcileReport(
            environment: VendorIdentity::ENV_LIVE,
            storeMode: 'normal',
        );
        self::assertTrue($normal['conserved']);
        self::assertSame(1, $normal['payout_count']);
        self::assertSame($liveVendorId, $normal['payouts'][0]['vendor_id']);
        self::assertSame('normal', $normal['payouts'][0]['store_mode_snapshot']);
        self::assertNotSame('', $normal['report_hash']);

        $test = $this->settlement->reconcileReport(
            environment: VendorIdentity::ENV_SANDBOX,
            storeMode: 'test',
        );
        self::assertTrue($test['conserved']);
        self::assertSame(1, $test['payout_count']);
        self::assertSame($this->vendorId, $test['payouts'][0]['vendor_id']);
        self::assertSame('test', $test['payouts'][0]['store_mode_snapshot']);
        self::assertNotSame($normal['report_hash'], $test['report_hash']);
    }
}
