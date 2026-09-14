<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\Order;
use Weline\Order\Service\BackendOrderStatusFlowPresenter;

final class BackendOrderStatusFlowPresenterTest extends TestCase
{
    public function testRefundedOrderMarksRefundCurrentAndExposesChannelId(): void
    {
        $flow = (new BackendOrderStatusFlowPresenter())->present(
            [
                Order::schema_fields_STATUS => Order::STATUS_REFUNDED,
                Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_REFUNDED,
                Order::schema_fields_FULFILLMENT_STATUS => Order::FULFILLMENT_STATUS_PENDING,
                Order::schema_fields_PAYMENT_METHOD => 'paypal',
            ],
            [
                [
                    'comment' => '订单已支付',
                    'status' => 'paid',
                    'created_at' => '2026-09-14 16:00:00',
                    'history_id' => 40,
                ],
                [
                    'comment' => '退款成功 paypal 8P2855227D736811T',
                    'status' => 'refunded',
                    'created_at' => '2026-09-14 15:59:00',
                    'history_id' => 39,
                ],
            ],
            [
                [
                    'payment_method' => 'paypal',
                    'provider_refund_id' => '8P2855227D736811T',
                    'channel_status' => 'succeeded',
                    'status' => 'succeeded',
                    'reason' => 'sandbox paypal 流程验收退款',
                ],
            ],
            [
                'payment_method_label' => 'PayPal',
                'payment_status_label' => '已退款',
            ],
        );

        self::assertTrue($flow['has_refund']);
        self::assertSame('refunded', $flow['current_code']);
        self::assertSame('PayPal', $flow['payment_method_label']);
        self::assertSame('8P2855227D736811T', $flow['refunds'][0]['provider_refund_id']);
        self::assertSame('退款成功 paypal 8P2855227D736811T', $flow['latest_comment']);

        $codes = array_column($flow['steps'], 'code');
        self::assertSame(
            [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_PAID, Order::STATUS_REFUNDED],
            $codes,
        );
        self::assertSame('current', $flow['steps'][3]['state']);
        self::assertSame('done', $flow['steps'][2]['state']);
        self::assertSame('warning', $flow['steps'][3]['tone']);
    }

    public function testPaidOrderHasNoRefundRowAndKeepsUpcomingFulfillment(): void
    {
        $flow = (new BackendOrderStatusFlowPresenter())->present(
            [
                Order::schema_fields_STATUS => Order::STATUS_PAID,
                Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PAID,
                Order::schema_fields_FULFILLMENT_STATUS => Order::FULFILLMENT_STATUS_PENDING,
            ],
        );

        self::assertFalse($flow['has_refund']);
        self::assertSame([], $flow['refunds']);
        $byCode = [];
        foreach ($flow['steps'] as $step) {
            $byCode[$step['code']] = $step['state'];
        }
        self::assertSame('current', $byCode[Order::STATUS_PAID]);
        self::assertSame('upcoming', $byCode[Order::STATUS_FULFILLED]);
        self::assertSame('done', $byCode[Order::STATUS_PENDING]);
    }
}
