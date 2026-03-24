<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;

class OrderServiceTest extends TestCase
{
    public function testUpdateOrderStatusRejectsIllegalTransition(): void
    {
        $order = $this->createMock(Order::class);
        $order->expects($this->once())
            ->method('load')
            ->with(10);
        $order->method('getId')->willReturn(10);
        $order->method('getData')->willReturnCallback(static fn(string $field): mixed => match ($field) {
            Order::schema_fields_status => OrderService::STATUS_COMPLETED,
            default => null,
        });
        $order->expects($this->never())->method('save');

        $service = $this->makeService($order);

        $this->expectException(\InvalidArgumentException::class);
        $service->updateOrderStatus(10, OrderService::STATUS_PENDING);
    }

    public function testGetRetryPaymentContextReturnsMappedOrderSummary(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
            }

            public function load(mixed $id = '', mixed $field = null, bool $force = false): static
            {
                return $this;
            }

            public function getId(mixed $default = 0): mixed
            {
                return 77;
            }

            public function hasField(string $field): bool
            {
                return false;
            }
        };
        $order->setData(Order::schema_fields_customer_id, 9);
        $order->setData(Order::schema_fields_status, OrderService::STATUS_PENDING);
        $order->setData(Order::schema_fields_increment_id, 'WS000077');
        $order->setData(Order::schema_fields_total, 59.5);

        $service = $this->makeService($order, [
            [
                'item_id' => 1,
                'product_id' => 101,
                'product_name' => 'Trail Backpack',
                'quantity' => 1,
                'price' => 19.5,
                'total' => 19.5,
            ],
            [
                'item_id' => 2,
                'product_id' => 102,
                'product_name' => 'Travel Bottle',
                'quantity' => 2,
                'price' => 20.0,
                'total' => 40.0,
            ],
        ]);

        $result = $service->getRetryPaymentContext(77, 9);

        $this->assertIsArray($result);
        $this->assertSame(77, $result['order_id']);
        $this->assertSame('WS000077', $result['increment_id']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('Trail Backpack', $result['items'][0]['name']);
        $this->assertSame(59.5, $result['summary']['grand_total']);
        $this->assertSame(59.5, $result['summary']['subtotal']);
    }

    public function testGetOrderByIncrementIdLoadsByIncrementField(): void
    {
        $order = new class extends Order {
            public array $loadedArgs = [];

            public function __construct()
            {
            }

            public function load(mixed $id = '', mixed $field = null, bool $force = false): static
            {
                $this->loadedArgs = [$id, $field, $force];
                $this->setData(self::schema_fields_ID, 88);
                return $this;
            }

            public function getId(mixed $default = 0): mixed
            {
                return $this->getData(self::schema_fields_ID) ?? $default;
            }

            public function hasField(string $field): bool
            {
                return false;
            }
        };

        $service = $this->makeService($order);
        $result = $service->getOrderByIncrementId('WS000088');

        $this->assertInstanceOf(Order::class, $result);
        $this->assertSame(['WS000088', Order::schema_fields_increment_id, false], $result->loadedArgs);
        $this->assertSame(88, $result->getId());
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function makeService(Order $order, array $items = []): OrderService
    {
        return new class($order, $items) extends OrderService {
            /**
             * @param array<int, array<string, mixed>> $testItems
             */
            public function __construct(
                private readonly Order $testOrder,
                private readonly array $testItems
            ) {
                parent::__construct($testOrder);
            }

            public function getOrderItems(int $orderId): array
            {
                return $this->testItems;
            }
        };
    }
}
