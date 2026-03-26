<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Service\OrderSuccessPageDataService;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\Product\Service\ProductRecommendationService;

class OrderSuccessPageDataServiceTest extends TestCase
{
    public function testBuildMapsOrderItemsSummaryAndCheckoutContextForSuccessPage(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrderItems')
            ->with(88)
            ->willReturn([
                [
                    'item_id' => 1,
                    'product_id' => 101,
                    'product_name' => 'Travel Backpack',
                    'product_sku' => 'TB-001',
                    'quantity' => 2,
                    'price' => 55,
                    'total' => 110,
                ],
            ]);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $recommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([101], 4)
            ->willReturn([
                ['product_id' => 201, 'name' => 'Packing Cube', 'price' => 18],
            ]);

        $order = new class() extends Order {
            public function __construct()
            {
            }

            public function getId(mixed $default = 0)
            {
                return 88;
            }
        };
        $order->setData(Order::schema_fields_increment_id, 'WS000088');
        $order->setData(Order::schema_fields_status, 'pending');
        $order->setData(Order::schema_fields_created_at, '2026-03-23 11:00:00');
        $order->setData(Order::schema_fields_total, 120.5);

        $service = new OrderSuccessPageDataService($orderService, $recommendationService);
        $result = $service->build($order, [
            'shipping_address' => [
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'street' => '123 Market St',
                'city' => 'London',
                'region' => 'LDN',
                'postcode' => 'EC1A',
                'country_id' => 'GB',
            ],
            'payment_method' => [
                'code' => 'paypal',
                'title' => 'PayPal',
            ],
            'cart_summary' => [
                'subtotal' => 110,
                'shipping' => 5,
                'discount' => 2,
                'tax' => 7.5,
                'grand_total' => 120.5,
            ],
        ]);

        $this->assertSame('WS000088', $result['order']['increment_id']);
        $this->assertSame(110.0, $result['order']['subtotal']);
        $this->assertSame('Ada Lovelace', $result['shipping_address']['name']);
        $this->assertSame('PayPal', $result['payment_method']['name']);
        $this->assertSame(110.0, $result['order_items'][0]['row_total']);
        $this->assertSame(201, $result['recommendations'][0]['product_id']);
    }

    public function testBuildFallsBackToPersistedOrderSummaryWhenCheckoutContextIsMissing(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrderItems')
            ->with(89)
            ->willReturn([]);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $recommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([], 4)
            ->willReturn([]);

        $order = new class() extends Order {
            public function getId(mixed $default = 0)
            {
                return 89;
            }
        };
        $order->setData(Order::schema_fields_increment_id, 'WS000089');
        $order->setData(Order::schema_fields_total, 66.5);
        $order->setData(Order::schema_fields_subtotal, 59.5);
        $order->setData(Order::schema_fields_shipping_amount, 5.0);
        $order->setData(Order::schema_fields_discount_amount, 3.0);
        $order->setData(Order::schema_fields_tax_amount, 5.0);

        $service = new OrderSuccessPageDataService($orderService, $recommendationService);
        $result = $service->build($order, []);

        $this->assertSame(59.5, $result['order']['subtotal']);
        $this->assertSame(5.0, $result['order']['shipping_amount']);
        $this->assertSame(3.0, $result['order']['discount_amount']);
        $this->assertSame(5.0, $result['order']['tax_amount']);
        $this->assertSame(66.5, $result['order']['grand_total']);
        $this->assertSame(59.5, $result['subtotal']);
        $this->assertSame(66.5, $result['grand_total']);
    }
}
