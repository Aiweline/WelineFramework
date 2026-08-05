<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Service\CheckoutGroupInvariant;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderFacadeConflictException;

/**
 * TEST-P2D-02 / TEST-P2D-03：快照守恒与提交回滚.
 */
final class CheckoutGroupTopologyTest extends TestCase
{
    public function testSplitCreatesPendingGroupWithFrozenSnapshots(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'topo-1',
            requestHash: hash('sha256', 'topo-1'),
            websiteId: 0,
            storeId: 1,
            currency: 'CNY',
            lines: [
                ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1000, 'split_key' => 'a', 'requires_shipping' => true],
                ['name' => 'B', 'qty_minor' => 2, 'unit_price_minor' => 250, 'split_key' => 'b', 'requires_shipping' => true],
            ],
            shippingMethod: 'flat',
            shippingAmountMinor: 120,
            shippingAddress: ['city' => 'SZ'],
        );

        $result = $facade->create($cmd);
        self::assertCount(2, $result->orderUuids);
        $group = $facade->getGroup($result->checkoutGroupUuid);
        self::assertSame(CheckoutGroup::STATUS_PENDING, $group['status']);
        self::assertArrayHasKey('money', $group['snapshots']);
        self::assertArrayHasKey('shipping', $group['snapshots']);
        self::assertSame(120, $group['snapshots']['shipping']['amount_minor']);
        self::assertSame($result->shippingChargeOwnerOrderUuid, $group['snapshots']['shipping']['charge_owner_order_uuid']);

        $inv = $facade->invariant();
        $inv->assertMoneyConservation($group['orders'], $group['totals']);
        $inv->assertSingleShippingOwner(
            $group['orders'],
            (int)$group['totals']['shipping_amount_minor'],
            $result->shippingChargeOwnerOrderUuid,
        );

        foreach ($result->orderUuids as $uuid) {
            $order = $facade->get($uuid);
            self::assertSame($result->checkoutGroupUuid, $order->checkoutGroupUuid);
            $row = $group['orders'];
            $match = null;
            foreach ($group['orders'] as $o) {
                if ($o['order_uuid'] === $uuid) {
                    $match = $o;
                    break;
                }
            }
            self::assertNotNull($match);
            self::assertArrayHasKey('snapshots', $match);
            $frozen = MoneySnapshot::fromArray($match['snapshots']['money']);
            $inv->assertSnapshotFrozen($frozen, MoneySnapshot::fromArray($match['money']));
        }

        // reservation stub total conservation (no inventory yet): grand = sum order grands
        $sum = 0;
        $fulfillmentQty = 0;
        foreach ($group['orders'] as $o) {
            $sum += (int)$o['money']['grand_total_minor'];
            self::assertCount(1, $o['fulfillment_units']);
            self::assertSame($o['order_uuid'], $o['fulfillment_units'][0]['order_uuid']);
            self::assertSame($group['checkout_group_uuid'], $o['fulfillment_units'][0]['checkout_group_uuid']);
            self::assertSame('pending', $o['fulfillment_units'][0]['status']);
            $fulfillmentQty += (int)$o['fulfillment_units'][0]['qty_minor'];
        }
        self::assertSame((int)$group['totals']['grand_total_minor'], $sum);
        self::assertSame(3, $fulfillmentQty);
    }

    public function testDigitalSplitDoesNotCreateFulfillmentUnit(): void
    {
        $facade = OrderFacade::forTesting();
        $result = $facade->create(new CreateCheckoutGroupCommand(
            idempotencyKey: 'topo-digital',
            requestHash: hash('sha256', 'topo-digital'),
            websiteId: 0,
            storeId: 1,
            lines: [
                ['name' => 'Physical', 'qty_minor' => 2, 'unit_price_minor' => 100, 'split_key' => 'a', 'requires_shipping' => true],
                ['name' => 'Digital', 'qty_minor' => 1, 'unit_price_minor' => 50, 'split_key' => 'b', 'requires_shipping' => false],
            ],
            shippingAmountMinor: 20,
        ));
        $group = $facade->getGroup($result->checkoutGroupUuid);

        self::assertCount(1, $group['orders'][0]['fulfillment_units']);
        self::assertSame(2, $group['orders'][0]['fulfillment_units'][0]['qty_minor']);
        self::assertSame([], $group['orders'][1]['fulfillment_units']);
    }

    public function testInjectedFailureRollsBackEntireGroup(): void
    {
        $facade = OrderFacade::forTesting();
        $facade->failAfterWritingOrderIndex(0); // fail after first order write

        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'rb-1',
            requestHash: hash('sha256', 'rb-1'),
            websiteId: 0,
            storeId: 1,
            lines: [
                ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'split_key' => 'a'],
                ['name' => 'B', 'qty_minor' => 1, 'unit_price_minor' => 200, 'split_key' => 'b'],
            ],
            shippingAmountMinor: 50,
        );

        try {
            $facade->create($cmd);
            self::fail('expected rollback');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderFacade::ERROR_COMMIT_FAILED, $e->errorCode());
        }

        self::assertSame(0, $facade->groupCount());
        self::assertSame(0, $facade->orderCount());
        self::assertSame(0, $facade->writeCount());

        // retry without injection succeeds
        $ok = $facade->create($cmd);
        self::assertFalse($ok->replayed);
        self::assertSame(1, $facade->groupCount());
        self::assertSame(2, $facade->orderCount());
    }

    public function testGroupStateMachineTransitions(): void
    {
        $inv = new CheckoutGroupInvariant();
        self::assertTrue($inv->canTransitionGroup(CheckoutGroup::STATUS_PENDING, CheckoutGroup::STATUS_PAID));
        self::assertTrue($inv->canTransitionGroup(CheckoutGroup::STATUS_PENDING, CheckoutGroup::STATUS_CANCELLED));
        self::assertFalse($inv->canTransitionGroup(CheckoutGroup::STATUS_CANCELLED, CheckoutGroup::STATUS_PAID));
        self::assertTrue($inv->canTransitionGroup(CheckoutGroup::STATUS_PAID, CheckoutGroup::STATUS_COMPLETED));
    }
}
