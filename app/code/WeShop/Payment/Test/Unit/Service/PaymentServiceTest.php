<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use WeShop\Payment\Provider\PayPal;
use WeShop\Payment\Service\PaymentService;

class PaymentServiceTest extends TestCase
{
    public function testGetCheckoutPaymentMethodsReturnsEnabledMethodsSortedForCheckout(): void
    {
        $service = new PaymentService();

        $methods = $service->getCheckoutPaymentMethods([
            'area' => 'frontend',
            'currency' => 'USD',
        ]);

        $codes = array_column($methods, 'code');

        $this->assertSame(['manual_transfer', 'cash_on_delivery'], $codes);
        $this->assertSame('manual_transfer', $methods[0]['code']);
        $this->assertTrue((bool) ($methods[0]['is_default'] ?? false));
        $this->assertArrayHasKey('title', $methods[0]);
        $this->assertArrayHasKey('description', $methods[0]);
        $this->assertArrayHasKey('sort_order', $methods[0]);
        $this->assertContains('US', $methods[0]['country_tags'] ?? []);
    }

    public function testGetPaymentMethodReturnsDisabledMethodsForAdminInspection(): void
    {
        $service = new PaymentService();

        $method = $service->getPaymentMethod('alipay');

        $this->assertIsArray($method);
        $this->assertSame('alipay', $method['code']);
        $this->assertFalse((bool) ($method['enabled'] ?? true));
    }

    public function testCountrySpecificMethodsAreSortedByPopularityScore(): void
    {
        $service = new PaymentService();

        $methods = $service->getAvailablePaymentMethods([
            'area' => 'backend',
            'country' => 'NL',
        ]);

        $this->assertNotEmpty($methods);
        $this->assertSame('ideal', $methods[0]['code']);
        $this->assertContains('NL', $methods[0]['country_tags'] ?? []);
    }

    public function testRuntimeOverridesAreMergedIntoMethodMetadata(): void
    {
        $service = new class() extends PaymentService {
            protected function getScopedMethodOverrides(array $context): array
            {
                return [
                    'paypal' => [
                        'enabled' => false,
                        'sort_order' => 5,
                        'config' => [
                            'sandbox' => false,
                            'client_id' => 'live-client-id',
                        ],
                    ],
                ];
            }
        };

        $method = $service->getPaymentMethod('paypal');

        $this->assertIsArray($method);
        $this->assertFalse((bool) ($method['enabled'] ?? true));
        $this->assertSame(5, $method['sort_order']);
        $this->assertFalse((bool) ($method['config']['sandbox'] ?? true));
        $this->assertSame('live-client-id', $method['config']['client_id'] ?? '');
    }

    public function testGetCheckoutPaymentMethodsSkipsEnabledButMisconfiguredMethods(): void
    {
        $service = new class() extends PaymentService {
            protected function getMethodRegistry(): array
            {
                return [
                    'hosted_gateway' => [
                        'code' => 'hosted_gateway',
                        'title' => 'Hosted Gateway',
                        'description' => 'Hosted redirect gateway.',
                        'provider' => PayPal::class,
                        'enabled' => true,
                        'is_default' => false,
                        'sort_order' => 10,
                        'areas' => ['frontend'],
                        'config' => [
                            'client_id' => '',
                        ],
                        'required_config' => ['client_id'],
                    ],
                ];
            }
        };

        $method = $service->getPaymentMethod('hosted_gateway');
        $checkoutMethods = $service->getCheckoutPaymentMethods(['area' => 'frontend']);

        $this->assertIsArray($method);
        $this->assertFalse((bool) ($method['is_configured'] ?? true));
        $this->assertSame(['client_id'], $method['missing_config'] ?? []);
        $this->assertSame([], $checkoutMethods);
    }

    public function testGetCheckoutPaymentMethodsSkipsEnabledMethodsWithoutDocumentation(): void
    {
        $service = new class() extends PaymentService {
            protected function getMethodRegistry(): array
            {
                return [
                    'undocumented_gateway' => [
                        'code' => 'undocumented_gateway',
                        'title' => 'Undocumented Gateway',
                        'description' => 'Missing embedded configuration docs.',
                        'provider' => PayPal::class,
                        'enabled' => true,
                        'is_default' => false,
                        'sort_order' => 10,
                        'areas' => ['frontend'],
                        'config' => [],
                        'required_config' => [],
                        'documentation_path' => 'missing/undocumented_gateway.md',
                    ],
                ];
            }
        };

        $method = $service->getPaymentMethod('undocumented_gateway');
        $checkoutMethods = $service->getCheckoutPaymentMethods(['area' => 'frontend']);

        $this->assertIsArray($method);
        $this->assertFalse((bool) ($method['has_documentation'] ?? true));
        $this->assertSame([], $checkoutMethods);
    }

    public function testProcessPaymentPassesProviderContextWithMethodConfiguration(): void
    {
        $provider = new class() implements PaymentProviderInterface {
            public array $captured = [];

            public function processPayment(Order $order, array $paymentData = [], array $context = []): array
            {
                $this->captured = [
                    'payment_data' => $paymentData,
                    'context' => $context,
                ];

                return [
                    'status' => 'pending',
                    'redirect_url' => 'https://gateway.example.test/pay',
                ];
            }

            public function handleCallback(array $callbackData, array $context = []): bool
            {
                return true;
            }

            public function queryPaymentStatus(string $orderNumber, array $context = []): string
            {
                return 'pending';
            }
        };

        $service = new class($provider) extends PaymentService {
            public function __construct(
                private readonly PaymentProviderInterface $provider
            ) {
            }

            protected function getMethodRegistry(): array
            {
                return [
                    'custom_gateway' => [
                        'code' => 'custom_gateway',
                        'title' => 'Custom Gateway',
                        'description' => 'Context-aware payment provider.',
                        'provider' => get_class($this->provider),
                        'enabled' => true,
                        'is_default' => false,
                        'sort_order' => 10,
                        'areas' => ['frontend'],
                        'config' => [
                            'merchant_id' => 'merchant-001',
                            'sandbox' => true,
                        ],
                        'required_config' => ['merchant_id'],
                        'config_test_status' => 'passed',
                    ],
                ];
            }

            protected function resolveProvider(array $method): PaymentProviderInterface
            {
                return $this->provider;
            }
        };

        $order = new class() extends Order {
            public function __construct()
            {
            }

            public function getId(mixed $default = 0)
            {
                return 42;
            }
        };

        $result = $service->processPayment($order, 'custom_gateway', [
            'currency' => 'CNY',
            'client_ip' => '127.0.0.1',
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('custom_gateway', $provider->captured['context']['payment_method']['code'] ?? '');
        $this->assertSame('merchant-001', $provider->captured['context']['config']['merchant_id'] ?? '');
        $this->assertTrue((bool) ($provider->captured['context']['is_configured'] ?? false));
        $this->assertSame('CNY', $provider->captured['payment_data']['currency'] ?? '');
    }
}
