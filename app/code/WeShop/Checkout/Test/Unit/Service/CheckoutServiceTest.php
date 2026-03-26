<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Service\AddressService;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;

class CheckoutServiceTest extends TestCase
{
    public function testPreviewCheckoutSummaryBuildsSummaryFromCartAndQuoteQueries(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->never())->method('getRetryPaymentContext');

        $queries = [];
        $service = new class($orderService, $queries) extends CheckoutService {
            public function __construct(
                OrderService $orderService,
                private array &$queries
            ) {
                parent::__construct($orderService);
            }

            protected function query(string $provider, string $operation, array $params = []): mixed
            {
                $this->queries[] = [$provider, $operation, $params];

                return match ($provider . ':' . $operation) {
                    'cart:getCartItems' => [
                        [
                            'product_id' => 10,
                            'quantity' => 2,
                            'price' => 25.0,
                            'product' => ['sku' => 'SKU-10', 'name' => 'Travel Bag', 'weight' => 1.2],
                        ],
                    ],
                    'cart:calculateTotals' => [
                        'subtotal' => 50.0,
                        'discount' => 5.0,
                    ],
                    'shipping:calculateShipping' => 12.5,
                    'tax:calculateTax' => 5.63,
                    default => null,
                };
            }
        };

        $summary = $service->previewCheckoutSummary(8, [
            'shipping_address' => ['country_id' => 'US', 'region' => 'CA'],
            'shipping_method' => 'flat_rate',
            'currency' => 'USD',
        ]);

        $this->assertSame(50.0, $summary['subtotal']);
        $this->assertSame(12.5, $summary['shipping']);
        $this->assertSame(5.0, $summary['discount']);
        $this->assertSame(5.63, $summary['tax']);
        $this->assertSame(63.13, $summary['grand_total']);
        $this->assertSame('cart', $queries[0][0]);
        $this->assertSame('getCartItems', $queries[0][1]);
        $this->assertSame(8, $queries[0][2]['customer_id']);
        $this->assertSame('cart', $queries[1][0]);
        $this->assertSame('calculateTotals', $queries[1][1]);
        $this->assertSame('flat_rate', $queries[2][2]['shipping_method']);
        $this->assertSame('US', $queries[2][2]['shipping_data']['address']['country_id']);
        $this->assertSame(12.5, $queries[3][2]['shipping_amount']);
    }

    public function testPreviewCheckoutSummaryUsesPersistedRetrySummaryWhenRetryOrderIdIsPresent(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getRetryPaymentContext')
            ->with(88, 12)
            ->willReturn([
                'summary' => [
                    'subtotal' => 59.5,
                    'shipping' => 0.0,
                    'discount' => 0.0,
                    'tax' => 0.0,
                    'grand_total' => 59.5,
                ],
            ]);

        $service = new class($orderService) extends CheckoutService {
            public function __construct(OrderService $orderService)
            {
                parent::__construct($orderService);
            }

            protected function query(string $provider, string $operation, array $params = []): mixed
            {
                throw new \RuntimeException('Quote preview should not query cart/shipping/tax during retry.');
            }
        };

        $summary = $service->previewCheckoutSummary(12, [
            'retry_order_id' => 88,
            'shipping_method' => 'flat_rate',
            'currency' => 'USD',
        ]);

        $this->assertSame(59.5, $summary['subtotal']);
        $this->assertSame(0.0, $summary['shipping']);
        $this->assertSame(59.5, $summary['grand_total']);
    }

    public function testCreateOrderFromCartBuildsSummaryFromShippingAndTaxQueries(): void
    {
        $order = new class extends Order {
            public function getId(mixed $default = 0)
            {
                return 321;
            }

            public function getIncrementId(): string
            {
                return 'WS202603250321';
            }
        };

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrder')
            ->with(321)
            ->willReturn($order);

        $queries = [];
        $service = new class($orderService, $queries) extends CheckoutService {
            public function __construct(
                OrderService $orderService,
                private array &$queries
            ) {
                parent::__construct($orderService);
            }

            protected function query(string $provider, string $operation, array $params = []): mixed
            {
                $this->queries[] = [$provider, $operation, $params];

                return match ($provider . ':' . $operation) {
                    'cart:getCartItems' => [
                        [
                            'product_id' => 10,
                            'quantity' => 2,
                            'price' => 25.0,
                            'product' => ['sku' => 'SKU-10', 'name' => 'Travel Bag', 'weight' => 1.2],
                        ],
                    ],
                    'cart:calculateTotals' => [
                        'subtotal' => 50.0,
                        'discount' => 5.0,
                    ],
                    'shipping:calculateShipping' => 12.5,
                    'tax:calculateTax' => 5.63,
                    'order:createOrder' => [
                        'order_id' => 321,
                    ],
                    'order:addOrderItems' => [
                        ['item_id' => 1],
                    ],
                    'cart:clearCart' => true,
                    default => null,
                };
            }
        };

        $createdOrder = $service->createOrderFromCart(8, [
            'customer_id' => 8,
            'shipping_address' => ['country_id' => 'US', 'region' => 'CA'],
            'shipping_method' => 'flat_rate',
            'currency' => 'USD',
        ]);

        $summary = $createdOrder->getData('weshop_checkout_summary');

        $this->assertSame($order, $createdOrder);
        $this->assertIsArray($summary);
        $this->assertSame(50.0, $summary['subtotal']);
        $this->assertSame(12.5, $summary['shipping']);
        $this->assertSame(5.0, $summary['discount']);
        $this->assertSame(5.63, $summary['tax']);
        $this->assertSame(63.13, $summary['grand_total']);

        $shippingCall = $queries[2];
        $this->assertSame('shipping', $shippingCall[0]);
        $this->assertSame('calculateShipping', $shippingCall[1]);
        $this->assertSame('flat_rate', $shippingCall[2]['shipping_method']);
        $this->assertSame('US', $shippingCall[2]['shipping_data']['address']['country_id']);

        $taxCall = $queries[3];
        $this->assertSame('tax', $taxCall[0]);
        $this->assertSame('calculateTax', $taxCall[1]);
        $this->assertSame(12.5, $taxCall[2]['shipping_amount']);
        $this->assertSame(5.0, $taxCall[2]['discount']);

        $orderCreateCall = $queries[4];
        $this->assertSame('order', $orderCreateCall[0]);
        $this->assertSame('createOrder', $orderCreateCall[1]);
        $this->assertSame(50.0, $orderCreateCall[2]['order_data']['subtotal']);
        $this->assertSame(12.5, $orderCreateCall[2]['order_data']['shipping_amount']);
        $this->assertSame(5.0, $orderCreateCall[2]['order_data']['discount_amount']);
        $this->assertSame(5.63, $orderCreateCall[2]['order_data']['tax_amount']);
        $this->assertSame(63.13, $orderCreateCall[2]['order_data']['total']);
    }

    public function testPlaceOrderCreatesOrderAndProcessesPaymentViaQueryProvider(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
                $this->setData('weshop_checkout_summary', [
                    'subtotal' => 58.5,
                    'shipping' => 5.0,
                    'discount' => 0.0,
                    'tax' => 0.0,
                    'grand_total' => 63.5,
                ]);
            }

            public function getId(mixed $default = 0)
            {
                return 88;
            }

            public function getIncrementId(): string
            {
                return 'WS202603230088';
            }
        };

        $queries = [];

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('updatePaymentStatus')
            ->with(88, OrderService::PAYMENT_STATUS_PENDING);
        $orderService->expects($this->once())
            ->method('getOrder')
            ->with(88)
            ->willReturn($order);

        $service = new class($orderService, $order, $queries) extends CheckoutService {
            public function __construct(
                OrderService $orderService,
                private readonly Order $fakeOrder,
                private array &$queries
            ) {
                parent::__construct($orderService);
            }

            public function createOrderFromCart(int $customerId, array $checkoutData): Order
            {
                $this->queries[] = ['createOrderFromCart', $customerId, $checkoutData['payment_method'] ?? null];
                return $this->fakeOrder;
            }

            protected function query(string $provider, string $operation, array $params = []): mixed
            {
                $this->queries[] = [$provider, $operation, $params];

                return match ($provider . ':' . $operation) {
                    'payment:processPayment' => [
                        'status' => 'pending',
                        'payment_method' => 'paypal',
                        'redirect_url' => 'https://paypal.test/checkout',
                    ],
                    default => null,
                };
            }
        };

        $result = $service->placeOrder([
            'customer_id' => 5,
            'shipping_address' => ['country_id' => 'US'],
            'shipping_method' => 'flat_rate',
            'payment_method' => 'paypal',
        ]);

        $this->assertSame($order, $result['order']);
        $this->assertSame('pending', $result['payment']['status']);
        $this->assertSame('paypal', $result['payment_method']['code']);
        $this->assertSame('WS202603230088', $result['order_increment_id']);
        $this->assertSame(63.5, $result['order_summary']['grand_total']);
        $this->assertCount(2, $queries);
        $this->assertSame(['createOrderFromCart', 5, 'paypal'], $queries[0]);
        $this->assertSame('payment', $queries[1][0]);
        $this->assertSame('processPayment', $queries[1][1]);
    }

    public function testGetCheckoutPaymentMethodsDelegatesToPaymentQueryProvider(): void
    {
        $orderService = $this->createMock(OrderService::class);

        $service = new class($orderService) extends CheckoutService {
            public function __construct(OrderService $orderService)
            {
                parent::__construct($orderService);
            }

            protected function query(string $provider, string $operation, array $params = []): mixed
            {
                return [
                    'provider' => $provider,
                    'operation' => $operation,
                    'params' => $params,
                ];
            }
        };

        $result = $service->getCheckoutPaymentMethods(7, [
            'area' => 'frontend',
            'currency' => 'USD',
        ]);

        $this->assertSame('payment', $result['provider']);
        $this->assertSame('getCheckoutPaymentMethods', $result['operation']);
        $this->assertSame(7, $result['params']['customer_id']);
        $this->assertSame('USD', $result['params']['currency']);
    }

    public function testPlaceOrderResolvesSavedShippingAddressForQuoteQueries(): void
    {
        $order = new class extends Order {
            public function getId(mixed $default = 0)
            {
                return 654;
            }

            public function getIncrementId(): string
            {
                return 'WS202603260654';
            }
        };

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->exactly(2))
            ->method('getOrder')
            ->with(654)
            ->willReturn($order);
        $orderService->expects($this->once())
            ->method('updatePaymentStatus')
            ->with(654, OrderService::PAYMENT_STATUS_PENDING);

        $addressService = $this->createMock(AddressService::class);
        $addressService->expects($this->atLeastOnce())
            ->method('getAddress')
            ->with(44, 8)
            ->willReturn([
                'address_id' => 44,
                'customer_id' => 8,
                'country_id' => 'GB',
                'country' => 'GB',
                'region' => 'LDN',
                'street' => '123 Market Street',
                'city' => 'London',
            ]);

        $queries = [];
        $service = new class($orderService, $addressService, $queries) extends CheckoutService {
            public function __construct(
                OrderService $orderService,
                AddressService $addressService,
                private array &$queries
            ) {
                parent::__construct($orderService, $addressService);
            }

            protected function query(string $provider, string $operation, array $params = []): mixed
            {
                $this->queries[] = [$provider, $operation, $params];

                return match ($provider . ':' . $operation) {
                    'cart:getCartItems' => [
                        [
                            'product_id' => 10,
                            'quantity' => 1,
                            'price' => 80.0,
                            'product' => ['sku' => 'SKU-10', 'name' => 'Travel Bag', 'weight' => 1.2],
                        ],
                    ],
                    'cart:calculateTotals' => [
                        'subtotal' => 80.0,
                        'discount' => 0.0,
                    ],
                    'shipping:calculateShipping' => 15.0,
                    'tax:calculateTax' => 7.6,
                    'order:createOrder' => [
                        'order_id' => 654,
                    ],
                    'order:addOrderItems' => [
                        ['item_id' => 1],
                    ],
                    'cart:clearCart' => true,
                    'payment:processPayment' => [
                        'status' => 'pending',
                        'payment_method' => 'paypal',
                    ],
                    default => null,
                };
            }
        };

        $result = $service->placeOrder([
            'customer_id' => 8,
            'shipping_address_id' => 44,
            'shipping_method' => 'flat_rate',
            'payment_method' => 'paypal',
            'currency' => 'USD',
        ]);

        $shippingCall = $queries[2];
        $this->assertSame('shipping', $shippingCall[0]);
        $this->assertSame('calculateShipping', $shippingCall[1]);
        $this->assertSame('GB', $shippingCall[2]['shipping_data']['address']['country_id']);
        $this->assertSame('LDN', $shippingCall[2]['shipping_data']['address']['region']);

        $taxCall = $queries[3];
        $this->assertSame('tax', $taxCall[0]);
        $this->assertSame('calculateTax', $taxCall[1]);
        $this->assertSame('GB', $taxCall[2]['country']);
        $this->assertSame('LDN', $taxCall[2]['region']);

        $this->assertSame($order, $result['order']);
        $this->assertSame(102.6, $result['order_summary']['grand_total']);
        $this->assertSame('paypal', $result['payment_method']['code']);
    }

    public function testGetCheckoutShippingMethodsDelegatesToShippingQueryProvider(): void
    {
        $orderService = $this->createMock(OrderService::class);

        $service = new class($orderService) extends CheckoutService {
            public function __construct(OrderService $orderService)
            {
                parent::__construct($orderService);
            }

            protected function query(string $provider, string $operation, array $params = []): mixed
            {
                return [
                    'provider' => $provider,
                    'operation' => $operation,
                    'params' => $params,
                ];
            }
        };

        $result = $service->getCheckoutShippingMethods(9, [
            'area' => 'frontend',
            'country' => 'US',
        ]);

        $this->assertSame('shipping', $result['provider']);
        $this->assertSame('getCheckoutShippingMethods', $result['operation']);
        $this->assertSame(9, $result['params']['customer_id']);
        $this->assertSame('US', $result['params']['country']);
    }
}
