<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\Order\Service\OrderService;

class OrderServiceTest extends TestCase
{
    public function testCreateOrderPersistsOrderSummaryFields(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
            }

            public function clearData(bool $with_query = true): static
            {
                parent::clearData($with_query);

                return $this;
            }

            public function save(\Weline\Framework\Database\AbstractModel|array|string|bool $data = [], array|string $sequence = ''): int|bool
            {
                return true;
            }
        };

        $service = $this->makeService($order);
        $createdOrder = $service->createOrder([
            'customer_id' => 9,
            'status' => OrderService::STATUS_PENDING,
            'subtotal' => 59.5,
            'shipping_amount' => 5.0,
            'discount_amount' => 7.0,
            'tax_amount' => 2.0,
            'total' => 59.5,
            'currency_code' => 'cny',
        ]);

        $this->assertSame(59.5, (float) $createdOrder->getData(Order::schema_fields_subtotal));
        $this->assertSame(5.0, (float) $createdOrder->getData(Order::schema_fields_shipping_amount));
        $this->assertSame(7.0, (float) $createdOrder->getData(Order::schema_fields_discount_amount));
        $this->assertSame(2.0, (float) $createdOrder->getData(Order::schema_fields_tax_amount));
        $this->assertSame(59.5, (float) $createdOrder->getData(Order::schema_fields_total));
        $this->assertSame('CNY', $createdOrder->getData(Order::schema_fields_currency_code));
    }

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
        $order->setData(Order::schema_fields_subtotal, 59.5);
        $order->setData(Order::schema_fields_shipping_amount, 5.0);
        $order->setData(Order::schema_fields_discount_amount, 7.0);
        $order->setData(Order::schema_fields_tax_amount, 2.0);
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
        $this->assertSame(5.0, $result['summary']['shipping']);
        $this->assertSame(7.0, $result['summary']['discount']);
        $this->assertSame(2.0, $result['summary']['tax']);
    }

    public function testGetOrderByIncrementIdQueriesIncrementField(): void
    {
        $order = new class extends Order {
            public array $whereArgs = [];

            public function __construct()
            {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                $this->whereArgs[] = [$field, $value, $condition, $where_logic, $array_where_logic_type];
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetch(): static
            {
                $this->setData(self::schema_fields_ID, 88);
                return $this;
            }

            public function getItems(): array
            {
                return [$this];
            }
        };

        $service = $this->makeService($order);
        $result = $service->getOrderByIncrementId('WS000088');

        $this->assertInstanceOf(Order::class, $result);
        $this->assertSame([[Order::schema_fields_increment_id, 'WS000088', '=', 'AND', 'AND']], $result->whereArgs);
        $this->assertSame(88, $result->getId());
    }

    public function testGetUnpaidOrderCountFallsBackToZeroWhenUnderlyingTotalCountIsNull(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                return $this;
            }

            public function hasField(string $field): bool
            {
                return $field === 'payment_status';
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function getTotalCount(): mixed
            {
                return null;
            }
        };

        $service = $this->makeService($order);

        $this->assertSame(0, $service->getUnpaidOrderCount(9));
    }

    public function testCreateShipmentRequiresPaidOrder(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
            }

            public function load(mixed $id = '', mixed $field = null, bool $force = false): static
            {
                $this->setData(self::schema_fields_ID, 10);
                $this->setData(self::schema_fields_status, OrderService::STATUS_PENDING);
                $this->setData(self::schema_fields_payment_status, OrderService::PAYMENT_STATUS_PENDING);
                return $this;
            }

            public function getId(mixed $default = 0): mixed
            {
                return $this->getData(self::schema_fields_ID) ?? $default;
            }

            public function hasField(string $field): bool
            {
                return true;
            }
        };

        $service = $this->makeService($order);

        $this->expectException(\InvalidArgumentException::class);
        $service->createShipment(10, 'DHL', 'TRACK-1');
    }

    public function testCreateShipmentMarksPaidOrderFulfilled(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
            }

            public function load(mixed $id = '', mixed $field = null, bool $force = false): static
            {
                $this->setData(self::schema_fields_ID, 10);
                $this->setData(self::schema_fields_status, OrderService::STATUS_PAID);
                $this->setData(self::schema_fields_payment_status, OrderService::PAYMENT_STATUS_PAID);
                return $this;
            }

            public function getId(mixed $default = 0): mixed
            {
                return $this->getData(self::schema_fields_ID) ?? $default;
            }

            public function hasField(string $field): bool
            {
                return true;
            }

            public function save(\Weline\Framework\Database\AbstractModel|array|string|bool $data = [], array|string $sequence = ''): int|bool
            {
                return true;
            }
        };

        $service = $this->makeService($order);
        $shipment = $service->createShipment(10, 'DHL', 'TRACK-1');

        $this->assertSame(OrderService::STATUS_FULFILLED, $shipment->getData(Order::schema_fields_status));
        $this->assertSame(OrderService::FULFILLMENT_STATUS_SHIPPED, $shipment->getData(Order::schema_fields_fulfillment_status));
        $this->assertSame('DHL', $shipment->getData(Order::schema_fields_fulfillment_carrier));
        $this->assertSame('TRACK-1', $shipment->getData(Order::schema_fields_fulfillment_tracking_number));
    }

    public function testMarkDeliveredCompletesShippedOrder(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
            }

            public function load(mixed $id = '', mixed $field = null, bool $force = false): static
            {
                $this->setData(self::schema_fields_ID, 10);
                $this->setData(self::schema_fields_status, OrderService::STATUS_FULFILLED);
                $this->setData(self::schema_fields_fulfillment_status, OrderService::FULFILLMENT_STATUS_SHIPPED);
                return $this;
            }

            public function getId(mixed $default = 0): mixed
            {
                return $this->getData(self::schema_fields_ID) ?? $default;
            }

            public function save(\Weline\Framework\Database\AbstractModel|array|string|bool $data = [], array|string $sequence = ''): int|bool
            {
                return true;
            }
        };

        $service = $this->makeService($order);
        $delivered = $service->markDelivered(10);

        $this->assertSame(OrderService::STATUS_COMPLETED, $delivered->getData(Order::schema_fields_status));
        $this->assertSame(OrderService::FULFILLMENT_STATUS_DELIVERED, $delivered->getData(Order::schema_fields_fulfillment_status));
    }

    public function testAddOrderItemsPersistsColorAndImageOptionPayload(): void
    {
        $orderItem = new class extends OrderItem {
            public function __construct()
            {
            }

            public function clearData(bool $with_query = true): static
            {
                return $this;
            }

            public function save(\Weline\Framework\Database\AbstractModel|array|string|bool $data = [], array|string $sequence = ''): int|bool
            {
                $this->setData(self::schema_fields_ID, 501);

                return true;
            }

            public function getId(mixed $default = 0): mixed
            {
                return $this->getData(self::schema_fields_ID) ?? $default;
            }
        };

        $service = new class($orderItem) extends OrderService {
            public function __construct(OrderItem $orderItem)
            {
                parent::__construct(null, $orderItem);
            }

            protected function loadProductSnapshot(int $productId, string $sku = ''): array
            {
                return [];
            }
        };
        $saved = $service->addOrderItems(77, [
            [
                'product_id' => 10,
                'product_name' => 'Demo Dress',
                'product_sku' => 'DEMO-10',
                'options' => [
                    [
                        'label' => 'Color',
                        'value' => 'Black',
                        'swatch_type' => 'color',
                        'swatch_value' => '#111827',
                    ],
                    [
                        'label' => 'Style',
                        'value' => 'Lifestyle',
                        'swatch_type' => 'image',
                        'option_image' => 'https://cdn.test/style.jpg',
                    ],
                ],
                'quantity' => 1,
                'price' => 19.5,
            ],
        ]);

        $this->assertSame('#111827', $saved[0]['options'][0]['swatch_value'] ?? null);
        $this->assertSame('https://cdn.test/style.jpg', $saved[0]['options'][1]['swatch_value'] ?? null);
        $this->assertSame('https://cdn.test/style.jpg', $saved[0]['options'][1]['option_image'] ?? null);
        $this->assertStringContainsString('option_image', (string) ($saved[0]['product_options'] ?? ''));
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
