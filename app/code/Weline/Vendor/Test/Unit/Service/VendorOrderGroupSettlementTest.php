<?php

declare(strict_types=1);

namespace Weline\Vendor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Service\VendorSettlementService;
use Weline\Websites\Api\Catalog\Data\StoreSummary;

/** TEST-P4A-02: consume two child Orders through Order's public result contract. */
final class VendorOrderGroupSettlementTest extends TestCase
{
    public function testTwoVendorsShareCheckoutGroupWithoutSnapshotCollision(): void
    {
        $created = new CreateCheckoutGroupResult(
            checkoutGroupUuid: '00000000-0000-4000-8000-000000000711',
            orderUuids: [
                '10000000-0000-4000-8000-000000000711',
                '20000000-0000-4000-8000-000000000711',
            ],
            currency: 'CNY',
            totals: ['grand_total_minor' => 10000],
            orders: [
                [
                    'order_uuid' => '10000000-0000-4000-8000-000000000711',
                    'split_key' => 'vendor-a',
                    'money' => ['grand_total_minor' => 7000],
                    'is_shipping_charge_owner' => true,
                    'status' => 'pending',
                ],
                [
                    'order_uuid' => '20000000-0000-4000-8000-000000000711',
                    'split_key' => 'vendor-b',
                    'money' => ['grand_total_minor' => 3000],
                    'is_shipping_charge_owner' => false,
                    'status' => 'pending',
                ],
            ],
        );
        self::assertCount(2, $created->orders);

        $settlement = VendorSettlementService::forTesting();
        $settlement->rollout()->setMode(
            VendorSettlementService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        $vendors = $settlement->vendors();
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
        $vendors->accounts()->registerStoreForTesting(new StoreSummary(
            711,
            0,
            'p4a-group-test',
            'P4A Group Test',
            'test',
            false,
            true,
            'active',
            null,
        ));

        $vendorIds = [];
        foreach (['a' => 1000, 'b' => 2000] as $code => $bps) {
            $vendor = $vendors->registerVendor([
                'code' => 'group_vendor_' . $code,
                'legal_name' => 'Group Vendor ' . strtoupper($code),
                'environment' => VendorIdentity::ENV_SANDBOX,
            ], 9, 0, 'default');
            $vendorId = (string) $vendor['vendor_id'];
            $vendorIds[] = $vendorId;
            $vendors->authorizeWebsite($vendorId, 0, 9, 'default');
            $vendors->bindAccount([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'store_id' => 711,
                'environment' => VendorIdentity::ENV_SANDBOX,
                'account_ref' => 'sandbox:group_vendor_' . $code,
            ], 9, 'default');
            $settlement->upsertRule([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'commission_bps' => $bps,
                'currency' => 'CNY',
            ]);
        }

        $snapshots = [];
        foreach ($created->orders as $index => $order) {
            $snapshots[] = $settlement->captureSnapshot([
                'vendor_id' => $vendorIds[$index],
                'website_id' => 0,
                'store_id' => 711,
                'checkout_group_ref' => $created->checkoutGroupUuid,
                'order_ref' => (string) $order['order_uuid'],
                'payment_ref' => 'pay-group-' . $created->checkoutGroupUuid,
                'gross_minor' => (int) $order['money']['grand_total_minor'],
                'currency' => 'CNY',
                'required_environment' => VendorIdentity::ENV_SANDBOX,
            ]);
        }

        self::assertNotSame($snapshots[0]['snapshot_id'], $snapshots[1]['snapshot_id']);
        self::assertSame($created->checkoutGroupUuid, $snapshots[0]['checkout_group_ref']);
        self::assertSame($created->checkoutGroupUuid, $snapshots[1]['checkout_group_ref']);
        self::assertNotSame($snapshots[0]['vendor_id'], $snapshots[1]['vendor_id']);
        self::assertSame(
            (int) $created->totals['grand_total_minor'],
            (int) $snapshots[0]['gross_minor'] + (int) $snapshots[1]['gross_minor'],
        );
        foreach ($snapshots as $index => $snapshot) {
            self::assertSame(
                (int) $snapshot['gross_minor'],
                (int) $snapshot['vendor_share_minor'] + (int) $snapshot['platform_share_minor'],
            );
            self::assertSame('test', $snapshot['store_mode_snapshot']);
            self::assertSame(
                'sandbox:group_vendor_' . ($index === 0 ? 'a' : 'b'),
                $snapshot['account']['account_ref'],
            );
            $settlement->schedulePayout($snapshot['snapshot_id'], 'group-' . $snapshot['vendor_id']);
        }
        self::assertSame(2, $settlement->reconcileReport(
            environment: VendorIdentity::ENV_SANDBOX,
            storeMode: 'test',
        )['payout_count']);
    }
}
