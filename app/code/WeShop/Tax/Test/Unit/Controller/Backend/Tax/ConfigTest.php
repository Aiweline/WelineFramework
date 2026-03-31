<?php

declare(strict_types=1);

namespace WeShop\Tax\Test\Unit\Controller\Backend\Tax;

use PHPUnit\Framework\TestCase;
use WeShop\Tax\Controller\Backend\Tax\Config;
use WeShop\Tax\Service\TaxService;
use Weline\Framework\Http\Request\Request;

class ConfigTest extends TestCase
{
    public function testSaveRejectsInvalidTaxRate(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate(null, null, [
            'default_rate' => -0.1,
        ]);

        $this->assertGreaterThanOrEqual(0.0, $rate);
    }

    public function testSaveAcceptsValidTaxRate(): void
    {
        $taxService = new TaxService();

        $rate = $taxService->getTaxRate(null, null, [
            'default_rate' => 0.085,
        ]);

        $this->assertSame(0.085, $rate);
    }

    public function testSaveCalculatesTestTaxCorrectly(): void
    {
        $taxService = new TaxService();

        $context = [
            'default_rate' => 0.08,
            'apply_to_shipping' => true,
            'prices_include_tax' => false,
        ];

        $taxAmount = $taxService->calculateTax(100.0, 'US', null, $context);
        $taxRate = $taxService->getTaxRate('US', null, $context);

        $this->assertSame(8.0, $taxAmount);
        $this->assertSame(0.08, $taxRate);
    }

    public function testSaveCalculatesTaxWithCountryOverride(): void
    {
        $taxService = new TaxService();

        $context = [
            'default_rate' => 0.05,
            'country_rates' => ['DE' => 0.19],
        ];

        $taxAmount = $taxService->calculateTax(100.0, 'DE', null, $context);
        $taxRate = $taxService->getTaxRate('DE', null, $context);

        $this->assertSame(19.0, $taxAmount);
        $this->assertSame(0.19, $taxRate);
    }

    public function testSaveCalculatesTaxWithRegionOverride(): void
    {
        $taxService = new TaxService();

        $context = [
            'default_rate' => 0.05,
            'country_rates' => ['US' => 0.05],
            'region_rates' => ['US-CA' => 0.0925],
        ];

        $taxAmount = $taxService->calculateTax(100.0, 'US', 'CA', $context);
        $taxRate = $taxService->getTaxRate('US', 'CA', $context);

        $this->assertSame(9.25, $taxAmount);
        $this->assertSame(0.0925, $taxRate);
    }

    public function testSaveCalculatesTaxWithDiscount(): void
    {
        $taxService = new TaxService();

        $breakdown = $taxService->calculateTaxBreakdown(100.0, 'US', null, [
            'discount' => 15.0,
            'default_rate' => 0.10,
        ]);

        $this->assertSame(8.5, $breakdown['tax_amount']);
        $this->assertSame(85.0, $breakdown['taxable_subtotal']);
    }

    public function testSaveCalculatesTaxWithShipping(): void
    {
        $taxService = new TaxService();

        $breakdown = $taxService->calculateTaxBreakdown(100.0, 'US', null, [
            'shipping_amount' => 15.0,
            'apply_to_shipping' => true,
            'default_rate' => 0.10,
        ]);

        $this->assertSame(11.5, $breakdown['tax_amount']);
        $this->assertSame(15.0, $breakdown['taxable_shipping_amount']);
    }

    public function testSaveHandlesEmptyCountryRates(): void
    {
        $taxService = new TaxService();

        $context = [
            'default_rate' => 0.08,
            'country_rates' => [],
        ];

        $taxAmount = $taxService->calculateTax(100.0, 'XX', null, $context);

        $this->assertSame(8.0, $taxAmount);
    }

    public function testSaveHandlesEmptyRegionRates(): void
    {
        $taxService = new TaxService();

        $context = [
            'default_rate' => 0.08,
            'region_rates' => [],
        ];

        $taxAmount = $taxService->calculateTax(100.0, 'US', 'UNKNOWN', $context);

        $this->assertSame(8.0, $taxAmount);
    }

    public function testSaveCalculatesTaxWithPricesIncludingTax(): void
    {
        $taxService = new TaxService();

        $breakdown = $taxService->calculateTaxBreakdown(110.0, 'DE', null, [
            'prices_include_tax' => true,
            'default_rate' => '10%',
        ]);

        $this->assertSame(10.0, $breakdown['included_tax']);
        $this->assertSame(0.0, $breakdown['chargeable_tax']);
        $this->assertSame(110.0, $breakdown['taxable_amount']);
    }

    public function testSaveCalculatesTaxWithShippingIncludingTax(): void
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
        $this->assertTrue($breakdown['shipping_includes_tax']);
    }
}
