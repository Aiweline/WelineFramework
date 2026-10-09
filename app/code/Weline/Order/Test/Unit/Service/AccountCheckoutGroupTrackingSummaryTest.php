<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\AccountCheckoutGroupPresenter;

final class AccountCheckoutGroupTrackingSummaryTest extends TestCase
{
    public function testShippedGroupExposesTrackingSummary(): void
    {
        $presenter = new AccountCheckoutGroupPresenter();
        $view = $presenter->present([
            'group_uuid' => 'g1',
            'display_number' => 'WL-G1',
            'status' => 'fulfilled',
            'grand_total_minor' => 1000,
            'currency' => 'CNY',
            'orders' => [
                [
                    'order_uuid' => 'o1',
                    'display_number' => 'WL-1',
                    'status' => 'fulfilled',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'shipped',
                ],
            ],
        ]);

        self::assertSame('已发货，发往目的地', $view['tracking_summary']);
    }

    public function testPendingPaymentHasNoTrackingSummary(): void
    {
        $presenter = new AccountCheckoutGroupPresenter();
        $view = $presenter->present([
            'group_uuid' => 'g2',
            'display_number' => 'WL-G2',
            'status' => 'pending',
            'grand_total_minor' => 1000,
            'currency' => 'CNY',
            'orders' => [
                [
                    'order_uuid' => 'o2',
                    'display_number' => 'WL-2',
                    'status' => 'pending',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'none',
                ],
            ],
        ]);

        self::assertSame('', $view['tracking_summary']);
    }

    public function testMultiSplitSameStatusExpandsEachChildWithOwnTrackingAndUuid(): void
    {
        $presenter = new AccountCheckoutGroupPresenter();
        $view = $presenter->present([
            'group_uuid' => 'g-split',
            'display_number' => 'WL-G-SPLIT',
            'status' => 'fulfilled',
            'grand_total_minor' => 2000,
            'currency' => 'CNY',
            'orders' => [
                [
                    'order_uuid' => 'child-a',
                    'display_number' => 'WL-A',
                    'status' => 'fulfilled',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'shipped',
                ],
                [
                    'order_uuid' => 'child-b',
                    'display_number' => 'WL-B',
                    'status' => 'fulfilled',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'shipped',
                ],
            ],
        ]);

        self::assertTrue((bool)$view['partial']);
        self::assertSame(AccountCheckoutGroupPresenter::VIEW_PARTIAL_EXPANDED, $view['view']);
        self::assertCount(2, $view['orders']);
        self::assertSame('child-a', $view['orders'][0]['order_uuid']);
        self::assertSame('child-b', $view['orders'][1]['order_uuid']);
        self::assertNotSame('', (string)$view['orders'][0]['tracking_summary']);
        self::assertNotSame('', (string)$view['orders'][1]['tracking_summary']);
        self::assertSame('结账组 · 含 2 个子订单', $view['order_count_label']);
        self::assertStringContainsString('子订单', (string)$view['orders'][0]['child_badge']);
    }

    public function testGroupShipmentCountLabelFromChildShipmentCounts(): void
    {
        $presenter = new AccountCheckoutGroupPresenter();
        $view = $presenter->present([
            'group_uuid' => 'g-ship',
            'display_number' => 'G-SHIP',
            'status' => 'fulfilled',
            'grand_total_minor' => 2000,
            'currency' => 'CNY',
            'orders' => [
                [
                    'order_uuid' => 'a',
                    'display_number' => 'A',
                    'status' => 'fulfilled',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'shipped',
                    'shipment_count' => 2,
                ],
                [
                    'order_uuid' => 'b',
                    'display_number' => 'B',
                    'status' => 'fulfilled',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'shipped',
                    'shipment_count' => 1,
                ],
            ],
        ]);

        self::assertSame(3, $view['shipment_count_in_group']);
        self::assertSame('发货共 3 笔', $view['group_shipment_count_label']);
        self::assertSame('发货×2', $view['orders'][0]['shipment_badge']);
        self::assertSame('发货×1', $view['orders'][1]['shipment_badge']);
    }
}
