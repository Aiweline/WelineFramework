<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Shipping\Interface\ShippingProviderInterface;
use WeShop\Shipping\Provider\DHL;
use WeShop\Shipping\Provider\FedEx;

class ShippingProviderContractTest extends TestCase
{
    /**
     * @return array<int, array{0: class-string}>
     */
    public static function providerClasses(): array
    {
        return [
            [DHL::class],
            [FedEx::class],
        ];
    }

    /**
     * @dataProvider providerClasses
     */
    public function testCarrierProvidersImplementExpectedShippingContract(string $providerClass): void
    {
        $this->assertContains(ShippingProviderInterface::class, class_implements($providerClass) ?: []);
        $this->assertTrue(method_exists($providerClass, 'calculateShipping'));
        $this->assertTrue(method_exists($providerClass, 'createShipping'));
        $this->assertTrue(method_exists($providerClass, 'trackShipping'));
    }
}
