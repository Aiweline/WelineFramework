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
}

