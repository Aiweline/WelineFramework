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

    public function testGetTaxRateNormalizesPercentAndWholeNumberRates(): void
    {
        $service = new TaxService();

        $rate = $service->getTaxRate('US', 'CA', [
            'default_rate' => '17%',
            'country_rates' => ['US' => 8],
            'region_rates' => ['US-CA' => '9.5%'],
        ]);

        $this->assertSame(0.095, $rate);
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

    public function testCalculateTaxExtractsIncludedTaxWhenPricesAlreadyContainTax(): void
    {
        $service = new TaxService();

        $tax = $service->calculateTax(110.0, 'DE', null, [
            'shipping_amount' => 11.0,
            'apply_to_shipping' => true,
            'prices_include_tax' => true,
            'shipping_includes_tax' => true,
            'default_rate' => '10%',
        ]);

        $this->assertSame(11.0, $tax);
    }

    public function testCalculateTaxBreakdownSeparatesIncludedAndChargeableTax(): void
    {
        $service = new TaxService();

        $breakdown = $service->calculateTaxBreakdown(100.0, 'US', null, [
            'shipping_amount' => 11.0,
            'apply_to_shipping' => true,
            'shipping_includes_tax' => true,
            'default_rate' => '10%',
        ]);

        $this->assertSame(11.0, $breakdown['tax_amount']);
        $this->assertSame(10.0, $breakdown['chargeable_tax']);
        $this->assertSame(1.0, $breakdown['included_tax']);
        $this->assertFalse($breakdown['prices_include_tax']);
        $this->assertTrue($breakdown['shipping_includes_tax']);
    }
}
