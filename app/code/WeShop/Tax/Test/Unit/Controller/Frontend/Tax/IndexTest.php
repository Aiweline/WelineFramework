<?php

declare(strict_types=1);

namespace WeShop\Tax\Test\Unit\Controller\Frontend\Tax;

use PHPUnit\Framework\TestCase;
use WeShop\Tax\Service\TaxService;

class IndexTest extends TestCase
{
    public function testCalculateReturnsJsonWithTaxBreakdown(): void
    {
        $taxService = new TaxService();

        $breakdown = $taxService->calculateTaxBreakdown(100.0, 'US', 'CA', [
            'shipping_amount' => 10.0,
            'discount' => 5.0,
            'apply_to_shipping' => true,
            'default_rate' => 0.09,
        ]);

        $this->assertArrayHasKey('tax_amount', $breakdown);
        $this->assertArrayHasKey('tax_rate', $breakdown);
        $this->assertArrayHasKey('taxable_amount', $breakdown);
        $this->assertArrayHasKey('included_tax', $breakdown);
        $this->assertArrayHasKey('chargeable_tax', $breakdown);
        $this->assertArrayHasKey('apply_to_shipping', $breakdown);
        $this->assertArrayHasKey('prices_include_tax', $breakdown);

        $this->assertIsFloat($breakdown['tax_amount']);
        $this->assertGreaterThanOrEqual(0.0, $breakdown['tax_amount']);
    }

    public function testCalculateReturnsZeroForNegativeSubtotal(): void
    {
        $taxService = new TaxService();

        $tax = $taxService->calculateTax(-100.0, 'US', null, [
            'default_rate' => 0.1,
        ]);

        $this->assertSame(0.0, $tax);
    }

    public function testCalculateRespectsDiscount(): void
    {
        $taxService = new TaxService();

        $breakdown = $taxService->calculateTaxBreakdown(100.0, 'US', null, [
            'discount' => 20.0,
            'default_rate' => 0.1,
        ]);

        $taxableSubtotal = $breakdown['taxable_subtotal'];
        $this->assertSame(80.0, $taxableSubtotal);
    }

    public function testGetTaxRateWithCountryOverride(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate('DE', null, [
            'default_rate' => 0.10,
            'country_rates' => ['DE' => 0.19],
        ]);

        $this->assertSame(0.19, $rate);
    }

    public function testGetTaxRateWithRegionOverride(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate('US', 'CA', [
            'default_rate' => 0.10,
            'country_rates' => ['US' => 0.08],
            'region_rates' => ['US-CA' => 0.0925],
        ]);

        $this->assertSame(0.0925, $rate);
    }

    public function testGetTaxRateFallsBackToCountryRate(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate('US', 'NY', [
            'default_rate' => 0.10,
            'country_rates' => ['US' => 0.08],
            'region_rates' => ['US-CA' => 0.0925],
        ]);

        $this->assertSame(0.08, $rate);
    }

    public function testGetTaxRateNormalizesPercentFormat(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate('US', null, [
            'default_rate' => '8%',
        ]);

        $this->assertSame(0.08, $rate);
    }

    public function testGetTaxRateNormalizesWholeNumberFormat(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate('US', null, [
            'default_rate' => 8,
        ]);

        $this->assertSame(0.08, $rate);
    }

    public function testGetTaxRateNeverReturnsNegative(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate('US', null, [
            'default_rate' => -0.5,
        ]);

        $this->assertGreaterThanOrEqual(0.0, $rate);
    }

    public function testCalculateTaxBreakdownSeparatesIncludedAndChargeableTax(): void
    {
        $taxService = new TaxService();

        $breakdown = $taxService->calculateTaxBreakdown(100.0, 'US', null, [
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

    public function testCalculateTaxAppliesToShippingWhenConfigured(): void
    {
        $taxService = new TaxService();

        $breakdownWithShipping = $taxService->calculateTaxBreakdown(100.0, 'US', null, [
            'shipping_amount' => 20.0,
            'apply_to_shipping' => true,
            'default_rate' => 0.10,
        ]);

        $breakdownWithoutShipping = $taxService->calculateTaxBreakdown(100.0, 'US', null, [
            'shipping_amount' => 20.0,
            'apply_to_shipping' => false,
            'default_rate' => 0.10,
        ]);

        $this->assertSame(12.0, $breakdownWithShipping['tax_amount']);
        $this->assertSame(10.0, $breakdownWithoutShipping['tax_amount']);
    }
}
