<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Service\BackendOrderTotalsPresenter;

final class BackendOrderTotalsPresenterTest extends TestCase
{
    public function testPresentAccumulatesLineQtyAndSubtotalWithMoneySnapshot(): void
    {
        $presenter = new BackendOrderTotalsPresenter();
        $totals = $presenter->present([
            Order::schema_fields_CURRENCY => 'CNY',
            Order::schema_fields_SUBTOTAL => 0,
            Order::schema_fields_SHIPPING_AMOUNT => 0,
            Order::schema_fields_TAX_AMOUNT => 0,
            Order::schema_fields_DISCOUNT_AMOUNT => 0,
            Order::schema_fields_GRAND_TOTAL => 0,
            Order::schema_fields_MONEY_SNAPSHOT_JSON => json_encode([
                'subtotal_minor' => 21940,
                'shipping_amount_minor' => 1200,
                'tax_amount_minor' => 100,
                'discount_amount_minor' => 40,
                'grand_total_minor' => 23200,
            ], JSON_UNESCAPED_UNICODE),
            Order::schema_fields_TYPE_PAYLOAD_JSON => json_encode([
                'deposit_amount_minor' => 5000,
                'balance_amount_minor' => 18200,
            ], JSON_UNESCAPED_UNICODE),
        ], [[
            OrderItem::schema_fields_QTY_ORDERED => 10,
            OrderItem::schema_fields_ROW_TOTAL => 219.40,
        ]]);

        self::assertSame(1, $totals['line_count']);
        self::assertEqualsWithDelta(10.0, $totals['lines_qty'], 0.001);
        self::assertEqualsWithDelta(219.40, $totals['lines_subtotal'], 0.001);
        self::assertEqualsWithDelta(219.40, $totals['subtotal'], 0.001);
        self::assertEqualsWithDelta(12.0, $totals['shipping_amount'], 0.001);
        self::assertEqualsWithDelta(1.0, $totals['tax_amount'], 0.001);
        self::assertEqualsWithDelta(0.4, $totals['discount_amount'], 0.001);
        self::assertEqualsWithDelta(232.0, $totals['grand_total'], 0.001);
        self::assertTrue($totals['lines_match_subtotal']);
        self::assertGreaterThanOrEqual(8, count($totals['rows']));
        self::assertCount(1, $totals['discount_items']);
        self::assertSame('订单折扣', $totals['discount_items'][0]['label']);
        self::assertEqualsWithDelta(0.4, $totals['discount_items'][0]['amount'], 0.001);
    }

    public function testPresentBuildsDiscountItemsFromOrderAndLines(): void
    {
        $shippingCatalog = new class implements \Weline\Order\Api\OrderShippingMethodCatalogInterface {
            public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
            {
                return [['code' => 'SEED_LANE_DOMESTIC', 'label' => '国内标快']];
            }

            public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
            {
                return strtoupper($code) === 'SEED_LANE_DOMESTIC' ? '国内标快' : $code;
            }
        };
        $presenter = new BackendOrderTotalsPresenter($shippingCatalog);
        $totals = $presenter->present([
            Order::schema_fields_CURRENCY => 'CNY',
            Order::schema_fields_SHIPPING_METHOD => 'SEED_LANE_DOMESTIC',
            Order::schema_fields_DISCOUNT_AMOUNT => 5.4,
            Order::schema_fields_MONEY_SNAPSHOT_JSON => json_encode([
                'discount_amount_minor' => 540,
                'shipping_amount_minor' => 0,
            ], JSON_UNESCAPED_UNICODE),
            Order::schema_fields_TYPE_PAYLOAD_JSON => json_encode([
                'discount_kind' => 'asset_b2b_credit',
            ], JSON_UNESCAPED_UNICODE),
        ], [[
            OrderItem::schema_fields_PRODUCT_NAME => 'Demo SKU',
            OrderItem::schema_fields_QTY_ORDERED => 1,
            OrderItem::schema_fields_ROW_TOTAL => 100,
            'campaign_label' => "Today's Picks",
            'line_discount_minor' => 120,
        ]]);

        self::assertSame('国内标快', $totals['shipping_method_label']);
        self::assertCount(2, $totals['discount_items']);
        self::assertSame("Today's Picks", $totals['discount_items'][0]['label']);
        self::assertEqualsWithDelta(1.2, $totals['discount_items'][0]['amount'], 0.001);
        self::assertSame('批发信用优惠', $totals['discount_items'][1]['label']);
        self::assertEqualsWithDelta(4.2, $totals['discount_items'][1]['amount'], 0.001);
    }

    public function testPresentFlagsMismatchWhenLinesDifferFromSubtotal(): void
    {
        $presenter = new BackendOrderTotalsPresenter();
        $totals = $presenter->present([
            Order::schema_fields_SUBTOTAL => 100,
            Order::schema_fields_MONEY_SNAPSHOT_JSON => '',
        ], [[
            OrderItem::schema_fields_QTY_ORDERED => 1,
            OrderItem::schema_fields_ROW_TOTAL => 50,
        ]]);

        self::assertFalse($totals['lines_match_subtotal']);
        self::assertEqualsWithDelta(50.0, $totals['lines_subtotal'], 0.001);
        self::assertEqualsWithDelta(100.0, $totals['subtotal'], 0.001);
        self::assertSame([], $totals['discount_items']);
    }
}
