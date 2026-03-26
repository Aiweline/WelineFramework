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
}
