<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
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

        $this->assertSame(['manual_transfer', 'cash_on_delivery', 'paypal'], $codes);
        $this->assertSame('manual_transfer', $methods[0]['code']);
        $this->assertTrue((bool) ($methods[0]['is_default'] ?? false));
        $this->assertArrayHasKey('title', $methods[0]);
        $this->assertArrayHasKey('description', $methods[0]);
        $this->assertArrayHasKey('sort_order', $methods[0]);
    }

    public function testGetPaymentMethodReturnsDisabledMethodsForAdminInspection(): void
    {
        $service = new PaymentService();

        $method = $service->getPaymentMethod('alipay');

        $this->assertIsArray($method);
        $this->assertSame('alipay', $method['code']);
        $this->assertFalse((bool) ($method['enabled'] ?? true));
    }

    public function testRuntimeOverridesAreMergedIntoMethodMetadata(): void
    {
        $service = new class() extends PaymentService {
            protected function getMethodOverrides(): array
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
}
