<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Shipping\Controller\Backend\Shipping;
use WeShop\Shipping\Service\ShippingService;
use Weline\Framework\Http\Url;

final class ShippingTest extends TestCase
{
    public function testIndexReturnsShippingMethodsData(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('getCheckoutShippingMethods')
            ->with(['area' => 'backend'])
            ->willReturn([
                ['code' => 'flat_rate', 'name' => 'Flat Rate', 'enabled' => true, 'is_default' => true],
                ['code' => 'free_shipping', 'name' => 'Free Shipping', 'enabled' => true, 'is_default' => false],
            ]);
        $shippingService->expects($this->once())
            ->method('getAvailableShippingMethods')
            ->with(['area' => 'backend'])
            ->willReturn([
                'flat_rate' => 'Flat Rate',
                'free_shipping' => 'Free Shipping',
            ]);

        $controller = $this->getMockBuilder(Shipping::class)
            ->setConstructorArgs([$shippingService])
            ->onlyMethods(['assign', 'fetchBase'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetchBase')
            ->willReturn('backend-shipping-page');

        $this->setControllerUrl($controller);

        self::assertSame('backend-shipping-page', $controller->index());
        self::assertSame('Shipping Methods', $assignments['page_title'] ?? null);
        self::assertSame('/backend/backend/shipping/save', $assignments['save_url'] ?? null);
        self::assertSame(2, $assignments['stats']['total_methods'] ?? null);
        self::assertCount(2, $assignments['methods'] ?? []);
    }

    public function testBuildStatsReturnsCorrectStatistics(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $controller = new Shipping($shippingService);

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('buildStats');
        $method->setAccessible(true);

        $methods = [
            ['code' => 'flat_rate', 'name' => 'Flat Rate', 'enabled' => true, 'is_default' => true],
            ['code' => 'free_shipping', 'name' => 'Free Shipping', 'enabled' => true, 'is_default' => false],
            ['code' => 'dhl', 'name' => 'DHL', 'enabled' => false, 'is_default' => false],
        ];

        $stats = $method->invoke($controller, $methods);

        self::assertIsArray($stats);
        self::assertSame(3, $stats['total_methods']);
        self::assertSame(2, $stats['enabled_methods']);
        self::assertSame(1, $stats['reserved_methods']);
        self::assertSame('flat_rate', $stats['default_method']);
        self::assertSame('Flat Rate', $stats['default_method_title']);
    }

    public function testBuildStatsWithNoMethodsReturnsZeroStats(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $controller = new Shipping($shippingService);

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('buildStats');
        $method->setAccessible(true);

        $stats = $method->invoke($controller, []);

        self::assertIsArray($stats);
        self::assertSame(0, $stats['total_methods']);
        self::assertSame(0, $stats['enabled_methods']);
        self::assertSame(0, $stats['reserved_methods']);
        self::assertSame('', $stats['default_method']);
        self::assertSame('', $stats['default_method_title']);
    }

    private function setControllerUrl(object $controller): void
    {
        $url = $this->getMockBuilder(Url::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBackendUrl'])
            ->getMock();
        $url->method('getBackendUrl')
            ->willReturnCallback(static fn (string $path): string => '/backend/' . ltrim($path, '*/'));

        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('_url') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate _url property.');
        }

        $property = $reflection->getProperty('_url');
        $property->setAccessible(true);
        $property->setValue($controller, $url);
    }
}
