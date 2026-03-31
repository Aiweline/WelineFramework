<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Shipping\Service\ShippingService;
use Weline\Shipping\Service\ShippingServiceManager as FrameworkShippingServiceManager;

class ShippingServiceTest extends TestCase
{
    public function testCalculateShippingReturnsConfiguredBuiltinAmounts(): void
    {
        $service = new ShippingService();

        $this->assertSame(5.0, $service->calculateShipping([], 'flat_rate'));
        $this->assertSame(0.0, $service->calculateShipping([], 'free_shipping'));
        $this->assertSame(0.0, $service->calculateShipping([], 'local_pickup'));
    }

    public function testCalculateShippingRejectsDisabledCarrierMethod(): void
    {
        $service = new ShippingService();

        $this->expectException(\InvalidArgumentException::class);
        $service->calculateShipping([], 'dhl');
    }

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

    public function testGetCheckoutShippingMethodsUsesFrameworkServicesWhenAvailable(): void
    {
        $frameworkManager = $this->createMock(FrameworkShippingServiceManager::class);
        $frameworkManager->expects($this->once())
            ->method('getAvailableServices')
            ->with('US', 'CA', 'Los Angeles', null)
            ->willReturn([
                [
                    'service_id' => 11,
                    'service_name' => 'US Express',
                    'service_code' => 'us_express',
                    'estimated_days_min' => 2,
                    'estimated_days_max' => 4,
                    'is_free_shipping' => false,
                ],
                [
                    'service_id' => 12,
                    'service_name' => 'US Pickup',
                    'service_code' => 'us_pickup',
                    'estimated_days_min' => 0,
                    'estimated_days_max' => 0,
                    'is_free_shipping' => true,
                ],
            ]);

        $service = new ShippingService($frameworkManager);
        $methods = $service->getCheckoutShippingMethods([
            'country' => 'us',
            'region' => 'CA',
            'city' => 'Los Angeles',
        ]);

        $this->assertSame(['us_express', 'us_pickup'], array_column($methods, 'code'));
        $this->assertTrue((bool) ($methods[0]['is_default'] ?? false));
        $this->assertSame('US Express', $methods[0]['name']);
        $this->assertStringContainsString('Estimated delivery', (string) ($methods[0]['description'] ?? ''));
        $this->assertStringContainsString('Free shipping', (string) ($methods[1]['description'] ?? ''));
    }

    public function testCalculateShippingUsesFrameworkFeeWhenFrameworkServiceMatches(): void
    {
        $frameworkManager = $this->createMock(FrameworkShippingServiceManager::class);
        $frameworkManager->expects($this->once())
            ->method('getAvailableServices')
            ->with('US', 'CA', 'Los Angeles', null)
            ->willReturn([
                [
                    'service_id' => 22,
                    'service_name' => 'US Express',
                    'service_code' => 'us_express',
                    'estimated_days_min' => 2,
                    'estimated_days_max' => 4,
                    'is_free_shipping' => false,
                ],
            ]);
        $frameworkManager->expects($this->once())
            ->method('calculateShippingFee')
            ->with(22, 120.0, 3.0, 0.4, 3, null, null, null)
            ->willReturn([
                'fee' => 18.5,
                'is_free' => false,
                'reason' => 'calculated',
            ]);

        $service = new ShippingService($frameworkManager);
        $amount = $service->calculateShipping([
            'subtotal' => 120.0,
            'address' => [
                'country_id' => 'US',
                'region' => 'CA',
                'city' => 'Los Angeles',
            ],
            'items' => [
                ['qty' => 2, 'weight' => 1.0, 'volume' => 0.1],
                ['qty' => 1, 'weight' => 1.0, 'volume' => 0.2],
            ],
        ], 'us_express');

        $this->assertSame(18.5, $amount);
    }
}
