<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Extends\Module\Weline_Framework\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Shipping\Extends\Module\Weline_Framework\Query\ShippingQueryProvider;
use WeShop\Shipping\Service\ShippingService;

class ShippingQueryProviderTest extends TestCase
{
    public function testExecuteReturnsCheckoutShippingMethodsFromService(): void
    {
        $expected = [
            ['code' => 'flat_rate', 'name' => 'Flat Rate'],
            ['code' => 'local_pickup', 'name' => 'Local Pickup'],
        ];

        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('getCheckoutShippingMethods')
            ->with([
                'area' => 'frontend',
                'country' => 'US',
            ])
            ->willReturn($expected);

        $provider = new ShippingQueryProvider($shippingService);

        $result = $provider->execute('getCheckoutShippingMethods', [
            'area' => 'frontend',
            'country' => 'US',
        ]);

        $this->assertSame($expected, $result);
    }

    public function testExecuteCalculatesShippingThroughService(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('calculateShipping')
            ->with(['subtotal' => 99.5], 'flat_rate')
            ->willReturn(5.0);

        $provider = new ShippingQueryProvider($shippingService);

        $result = $provider->execute('calculateShipping', [
            'shipping_method' => 'flat_rate',
            'shipping_data' => ['subtotal' => 99.5],
        ]);

        $this->assertSame(5.0, $result);
    }
}
