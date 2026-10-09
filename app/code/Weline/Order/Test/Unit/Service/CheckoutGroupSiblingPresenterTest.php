<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\CheckoutGroupSiblingPresenter;

final class CheckoutGroupSiblingPresenterTest extends TestCase
{
    public function testMultiOrderGroupExposesChildBadgeAndShipmentCounts(): void
    {
        $presenter = new CheckoutGroupSiblingPresenter();
        $view = $presenter->presentFromRows(
            [
                [
                    'order_id' => 40,
                    'order_uuid' => 'uuid-a',
                    'order_number' => 'ORD-A',
                    'checkout_group_uuid' => 'af0e656b-aaaa-bbbb-cccc-ddddeeeeffff',
                    'status' => 'fulfilled',
                    'fulfillment_status' => 'shipped',
                    'is_shipping_charge_owner' => 1,
                ],
                [
                    'order_id' => 41,
                    'order_uuid' => 'uuid-b',
                    'order_number' => 'ORD-B',
                    'checkout_group_uuid' => 'af0e656b-aaaa-bbbb-cccc-ddddeeeeffff',
                    'status' => 'fulfilled',
                    'fulfillment_status' => 'shipped',
                    'is_shipping_charge_owner' => 0,
                ],
            ],
            40,
            [40 => 2, 41 => 1],
            'af0e656b-aaaa-bbbb-cccc-ddddeeeeffff'
        );

        self::assertSame('G-af0e656b', $view['group_display']);
        self::assertTrue($view['is_multi_order']);
        self::assertSame(2, $view['sibling_count']);
        self::assertSame(1, $view['current_index']);
        self::assertSame('子订单 1/2', $view['child_badge']);
        self::assertTrue($view['is_shipping_charge_owner']);
        self::assertSame(2, $view['shipment_count']);
        self::assertSame(3, $view['shipment_count_in_group']);
        self::assertSame('发货共 3 笔', $view['group_shipment_count_label']);
        self::assertSame('结账组 · 含 2 个子订单', $view['group_order_count_label']);
        self::assertCount(2, $view['siblings']);
        self::assertTrue($view['siblings'][0]['is_current']);
        self::assertSame('发货×2', $view['siblings'][0]['shipment_badge']);
        self::assertSame('发货×1', $view['siblings'][1]['shipment_badge']);
        self::assertFalse($view['siblings'][1]['is_shipping_charge_owner']);
    }

    public function testSingleOrderDoesNotEmphasizeChildBadge(): void
    {
        $presenter = new CheckoutGroupSiblingPresenter();
        $view = $presenter->presentFromRows(
            [
                [
                    'order_id' => 10,
                    'order_uuid' => 'solo',
                    'order_number' => 'ORD-1',
                    'checkout_group_uuid' => 'solo-group-uuid-xxxx',
                    'status' => 'paid',
                    'is_shipping_charge_owner' => 1,
                ],
            ],
            10,
            [10 => 0],
            'solo-group-uuid-xxxx'
        );

        self::assertFalse($view['is_multi_order']);
        self::assertSame('', $view['child_badge']);
        self::assertSame('订单', $view['child_label']);
        self::assertSame(0, $view['shipment_count_in_group']);
        self::assertSame('', $view['group_shipment_count_label']);
    }

    public function testShipmentBadgeEmptyWhenZero(): void
    {
        $presenter = new CheckoutGroupSiblingPresenter();
        self::assertSame('', $presenter->shipmentBadge(0));
        self::assertSame('发货×3', $presenter->shipmentBadge(3));
    }

    public function testLoadPathsCloneSharedModelsBeforeClear(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/CheckoutGroupSiblingPresenter.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('clone $this->resolve(Order::class)', $src);
        self::assertStringContainsString('clone $this->resolve(OrderShipment::class)', $src);
        self::assertStringContainsString('never clear() the shared Order singleton', $src);
    }
}
