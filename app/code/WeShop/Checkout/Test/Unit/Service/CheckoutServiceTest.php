<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;

class CheckoutServiceTest extends TestCase
{
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
