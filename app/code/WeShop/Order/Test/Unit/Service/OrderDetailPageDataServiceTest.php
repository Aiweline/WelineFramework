<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderDetailPageDataService;
use WeShop\Order\Service\OrderService;

class OrderDetailPageDataServiceTest extends TestCase
{
    public function testBuildReturnsNormalizedOrderAndItems(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
            }

            public function getId(mixed $default = 0): mixed
            {
                return 77;
            }
        };
        $order->setData(Order::schema_fields_ID, 77);
        $order->setData(Order::schema_fields_customer_id, 3);
        $order->setData(Order::schema_fields_increment_id, 'WS100077');
        $order->setData(Order::schema_fields_status, OrderService::STATUS_PROCESSING);
        $order->setData(Order::schema_fields_total, '299.99');
        $order->setData(Order::schema_fields_created_at, '2026-03-24 14:10:00');
        $order->setData('payment_status', OrderService::PAYMENT_STATUS_PENDING);

        $orderService = new class($order) extends OrderService {
            public function __construct(private readonly Order $order)
            {
            }

            public function getOrder(int $orderId): ?Order
            {
                return $orderId === 77 ? $this->order : null;
            }

            public function getOrderItems(int $orderId): array
            {
                return [[
                    'item_id' => 12,
                    'product_name' => 'Desk Lamp',
                    'product_sku' => 'LAMP-1',
                    'quantity' => 2,
                    'price' => '49.90',
                    'total' => '99.80',
                ]];
            }

            public function canRetryPayment(int $orderId, int $customerId): bool
            {
                return true;
            }

            public function canCancelOrder(int $orderId, int $customerId): array
            {
                return [
                    'can_cancel' => true,
                    'reason' => null,
                    'require_return' => false,
                    'require_refund' => false,
                ];
            }
        };

        $service = new OrderDetailPageDataService($orderService);
        $data = $service->build(3, 77);

        $this->assertSame('WS100077', $data['order']['increment_id']);
        $this->assertSame('Processing', $data['order']['status_label']);
        $this->assertTrue($data['order']['can_retry_payment']);
        $this->assertTrue($data['order']['can_cancel']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('Desk Lamp', $data['items'][0]['product_name']);
        $this->assertSame('weshop/order/list', $data['back_url']);
    }

    public function testBuildThrowsWhenOrderIsMissingOrOwnedByAnotherCustomer(): void
    {
        $orderService = new class extends OrderService {
            public function getOrder(int $orderId): ?Order
            {
                return null;
            }
        };

        $service = new OrderDetailPageDataService($orderService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order not found.');

        $service->build(5, 99);
    }
}
