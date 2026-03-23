<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Order\Model\Order;

class CheckoutServiceTest extends TestCase
{
    public function testPlaceOrderCreatesOrderAndProcessesPaymentViaQueryProvider(): void
    {
        $order = new class extends Order {
            public function __construct()
            {
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

        $service = new class($order, $queries) extends CheckoutService {
            public function __construct(
                private readonly Order $fakeOrder,
                private array &$queries
            ) {
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
        $this->assertCount(2, $queries);
        $this->assertSame(['createOrderFromCart', 5, 'paypal'], $queries[0]);
        $this->assertSame('payment', $queries[1][0]);
        $this->assertSame('processPayment', $queries[1][1]);
    }

    public function testGetCheckoutPaymentMethodsDelegatesToPaymentQueryProvider(): void
    {
        $service = new class() extends CheckoutService {
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
}
