<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Model\Affiliate;
use Weline\Affiliate\Service\AffiliateOrderEventPayloadBuilder;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

final class AffiliateOrderEventPayloadBuilderTest extends TestCase
{
    public function testBuildCheckoutPayloadAddsSummaryFromOrder(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getData')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                Order::schema_fields_CUSTOMER_ID => 7,
                Order::schema_fields_CURRENCY => 'CNY',
                Order::schema_fields_SUBTOTAL => 100.0,
                Order::schema_fields_DISCOUNT_AMOUNT => 10.0,
                default => null,
            };
        });

        $builder = new AffiliateOrderEventPayloadBuilder();
        $payload = $builder->buildCheckoutPayload([
            'order' => $order,
            'order_items' => [[
                'product_id' => 9,
                'quantity' => 1,
                'row_total' => 90.0,
            ]],
        ]);

        $this->assertSame(42, $payload['order_id']);
        $this->assertSame(7, $payload['customer_id']);
        $this->assertSame('CNY', $payload['currency_code']);
        $this->assertSame(100.0, $payload['order_summary']['subtotal']);
        $this->assertCount(1, $payload['order_items']);
    }

    public function testBuildPaymentPayloadSetsStatus(): void
    {
        $builder = new AffiliateOrderEventPayloadBuilder();
        $payload = $builder->buildPaymentPayload(['order_id' => 5], 'paid');

        $this->assertSame('paid', $payload['new_payment_status']);
        $this->assertSame('paid', $payload['payment_status']);
    }
}
