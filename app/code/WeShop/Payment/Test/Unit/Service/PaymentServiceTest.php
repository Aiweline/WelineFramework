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
}
