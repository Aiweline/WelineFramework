<?php

declare(strict_types=1);

namespace WeShop\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Tax\Service\TaxService;

class TaxServiceTest extends TestCase
{
    public function testCalculateTaxUsesDiscountShippingAndRegionOverrides(): void
    {
        $service = new TaxService();

        $tax = $service->calculateTax(100.0, 'US', 'CA', [
            'discount' => 10.0,
            'shipping_amount' => 5.0,
            'apply_to_shipping' => true,
            'default_rate' => 0.1,
            'country_rates' => ['US' => 0.08],
            'region_rates' => ['US-CA' => 0.09],
        ]);

        $this->assertSame(8.55, $tax);
    }

    public function testGetTaxRateFallsBackToDefaultRate(): void
    {
        $service = new TaxService();

        $rate = $service->getTaxRate(null, null, [
            'default_rate' => 0.17,
        ]);

        $this->assertSame(0.17, $rate);
    }

    public function testCalculateTaxNeverReturnsNegativeAmount(): void
    {
        $service = new TaxService();

        $tax = $service->calculateTax(10.0, 'US', null, [
            'discount' => 999.0,
            'default_rate' => 0.2,
        ]);

        $this->assertSame(0.0, $tax);
    }
}
