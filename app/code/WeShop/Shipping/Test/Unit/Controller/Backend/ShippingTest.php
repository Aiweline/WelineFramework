<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Shipping\Controller\Backend\Shipping;
use WeShop\Shipping\Service\ShippingService;

class ShippingTest extends TestCase
{
    public function testIndexReturnsShippingMethodsData(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('getCheckoutShippingMethods')
            ->willReturn([
                [
                    'code' => 'flat_rate',
                    'name' => 'Flat Rate',
                    'description' => 'Standard delivery with a fixed shipping fee.',
                    'enabled' => true,
                    'is_default' => true,
                    'sort_order' => 10,
                    'price' => 5.00,
                    'areas' => ['frontend', 'backend'],
                ],
                [
                    'code' => 'free_shipping',
                    'name' => 'Free Shipping',
                    'description' => 'Free delivery for eligible orders.',
                    'enabled' => true,
                    'is_default' => false,
                    'sort_order' => 20,
                    'price' => 0.00,
                    'areas' => ['frontend', 'backend'],
                ],
            ]);

        $shippingService->expects($this->once())
            ->method('getAvailableShippingMethods')
            ->willReturn([
                'flat_rate' => 'Flat Rate',
                'free_shipping' => 'Free Shipping',
            ]);

        $controller = new Shipping($shippingService);

        $result = $controller->index();

        $this->assertIsString($result);
    }

    public function testBuildStatsReturnsCorrectStatistics(): void
    {
        $shippingService = $this->createMock(ShippingService::class);

        $controller = new Shipping($shippingService);

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('buildStats');
        $method->setAccessible(true);

        $methods = [
            [
                'code' => 'flat_rate',
                'name' => 'Flat Rate',
                'enabled' => true,
                'is_default' => true,
            ],
            [
                'code' => 'free_shipping',
                'name' => 'Free Shipping',
                'enabled' => true,
                'is_default' => false,
            ],
            [
                'code' => 'dhl',
                'name' => 'DHL',
                'enabled' => false,
                'is_default' => false,
            ],
        ];

        $stats = $method->invoke($controller, $methods);

        $this->assertIsArray($stats);
        $this->assertEquals(3, $stats['total_methods']);
        $this->assertEquals(2, $stats['enabled_methods']);
        $this->assertEquals(1, $stats['reserved_methods']);
        $this->assertEquals('flat_rate', $stats['default_method']);
        $this->assertEquals('Flat Rate', $stats['default_method_title']);
    }

    public function testBuildStatsWithNoMethodsReturnsZeroStats(): void
    {
        $shippingService = $this->createMock(ShippingService::class);

        $controller = new Shipping($shippingService);

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('buildStats');
        $method->setAccessible(true);

        $stats = $method->invoke($controller, []);

        $this->assertIsArray($stats);
        $this->assertEquals(0, $stats['total_methods']);
        $this->assertEquals(0, $stats['enabled_methods']);
        $this->assertEquals(0, $stats['reserved_methods']);
        $this->assertEquals('', $stats['default_method']);
        $this->assertEquals('', $stats['default_method_title']);
    }
}
