<?php

declare(strict_types=1);

namespace WeShop\Tax\Test\Unit\Extends\Module\Weline_Framework\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Tax\Extends\Module\Weline_Framework\Query\TaxQueryProvider;
use WeShop\Tax\Service\TaxService;

class TaxQueryProviderTest extends TestCase
{
    public function testExecuteCalculatesTaxThroughService(): void
    {
        $taxService = $this->createMock(TaxService::class);
        $taxService->expects($this->once())
            ->method('calculateTax')
            ->with(
                50.0,
                'US',
                'CA',
                [
                    'shipping_amount' => 12.5,
                    'discount' => 5.0,
                ]
            )
            ->willReturn(5.63);

        $provider = new TaxQueryProvider($taxService);

        $result = $provider->execute('calculateTax', [
            'subtotal' => 50.0,
            'country' => 'US',
            'region' => 'CA',
            'shipping_amount' => 12.5,
            'discount' => 5.0,
        ]);

        $this->assertSame(5.63, $result);
    }

    public function testExecuteReturnsTaxRateThroughService(): void
    {
        $taxService = $this->createMock(TaxService::class);
        $taxService->expects($this->once())
            ->method('getTaxRate')
            ->with('DE', null, ['default_rate' => 0.19])
            ->willReturn(0.19);

        $provider = new TaxQueryProvider($taxService);

        $result = $provider->execute('getTaxRate', [
            'country' => 'DE',
            'context' => ['default_rate' => 0.19],
        ]);

        $this->assertSame(0.19, $result);
    }

    public function testExecuteReturnsTaxBreakdownThroughService(): void
    {
        $taxService = $this->createMock(TaxService::class);
        $taxService->expects($this->once())
            ->method('calculateTaxBreakdown')
            ->with(
                110.0,
                'DE',
                'BE',
                [
                    'shipping_amount' => 11.0,
                    'discount' => 0.0,
                    'prices_include_tax' => true,
                    'shipping_includes_tax' => true,
                ]
            )
            ->willReturn([
                'tax_amount' => 11.0,
                'chargeable_tax' => 0.0,
            ]);

        $provider = new TaxQueryProvider($taxService);

        $result = $provider->execute('calculateTaxBreakdown', [
            'subtotal' => 110.0,
            'country' => 'DE',
            'region' => 'BE',
            'shipping_amount' => 11.0,
            'discount' => 0.0,
            'prices_include_tax' => true,
            'shipping_includes_tax' => true,
        ]);

        $this->assertSame(11.0, $result['tax_amount']);
        $this->assertSame(0.0, $result['chargeable_tax']);
    }
}
