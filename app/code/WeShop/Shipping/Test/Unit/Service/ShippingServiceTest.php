<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Shipping\Service\ShippingService;

class ShippingServiceTest extends TestCase
{
    public function testGetCheckoutShippingMethodsReturnsEnabledMethodsSortedForCheckout(): void
    {
        $service = new ShippingService();

        $methods = $service->getCheckoutShippingMethods([
            'area' => 'frontend',
        ]);

        $codes = array_column($methods, 'code');
        $this->assertSame(['flat_rate', 'free_shipping', 'local_pickup'], $codes);
        $this->assertTrue((bool) ($methods[0]['is_default'] ?? false));
        $this->assertSame('Flat Rate', $methods[0]['name']);
    }

    public function testGetAvailableShippingMethodsReturnsCodeLabelMap(): void
    {
        $service = new ShippingService();

        $methods = $service->getAvailableShippingMethods([
            'area' => 'frontend',
        ]);

        $this->assertSame('Flat Rate', $methods['flat_rate'] ?? null);
        $this->assertSame('Free Shipping', $methods['free_shipping'] ?? null);
        $this->assertSame('Local Pickup', $methods['local_pickup'] ?? null);
    }
}

