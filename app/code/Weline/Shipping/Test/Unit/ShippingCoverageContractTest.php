<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Api\Data\Shipping\ShippingCoverageRow;
use Weline\Shipping\Api\ShippingCoverageSourceInterface;
use Weline\Shipping\Service\ShippingCoverageService;

/**
 * 配送覆盖读模型契约：起运费取值、同国多条服务的取舍、行对象语义。
 */
final class ShippingCoverageContractTest extends TestCase
{
    public function testMinCostUsesLowestPositiveBracket(): void
    {
        $template = [
            'calculation_type' => 'weight_table',
            'base_fee' => 0,
            'rate_brackets' => '[
                {"min":0,"max":0.5,"price":"52.00"},
                {"min":0.5,"max":1,"price":"59.00"},
                {"min":1,"max":2,"price":"73.00"}
            ]',
        ];

        self::assertSame(5200, ShippingCoverageService::minCostMinorFromTemplate($template));
    }

    public function testMinCostFallsBackToBaseFee(): void
    {
        $template = [
            'calculation_type' => 'fixed',
            'base_fee' => 9.9,
            'rate_brackets' => null,
        ];

        self::assertSame(990, ShippingCoverageService::minCostMinorFromTemplate($template));
    }

    public function testMinCostIsZeroWhenTemplateHasNoPrice(): void
    {
        self::assertSame(0, ShippingCoverageService::minCostMinorFromTemplate([
            'calculation_type' => 'weight_table',
            'base_fee' => 0,
            'rate_brackets' => '[{"min":0,"max":0.5,"price":"0.00"}]',
        ]));
        self::assertSame(0, ShippingCoverageService::minCostMinorFromTemplate([]));
    }

    public function testPreferredRowPrefersTransitDaysOverNoDays(): void
    {
        $noDays = new ShippingCoverageRow('US', 0, 0, 1000, 'CNY', 'PUBLIC_STD_US');
        $withDays = new ShippingCoverageRow('US', 7, 15, 5200, 'CNY', 'SEED_LANE_AMERICAS');

        self::assertSame($withDays, ShippingCoverageService::preferredRow($noDays, $withDays));
        self::assertSame($withDays, ShippingCoverageService::preferredRow($withDays, $noDays));
    }

    public function testPreferredRowPrefersLowerCostThenShorterTransit(): void
    {
        $cheap = new ShippingCoverageRow('FR', 6, 14, 4600, 'CNY', 'A');
        $pricey = new ShippingCoverageRow('FR', 6, 14, 5200, 'CNY', 'B');
        self::assertSame($cheap, ShippingCoverageService::preferredRow($pricey, $cheap));

        $fast = new ShippingCoverageRow('FR', 4, 9, 4600, 'CNY', 'C');
        self::assertSame($fast, ShippingCoverageService::preferredRow($cheap, $fast));
    }

    public function testPreferredRowIsStableOnFullTie(): void
    {
        $first = new ShippingCoverageRow('DE', 5, 12, 4000, 'CNY', 'AAA');
        $second = new ShippingCoverageRow('DE', 5, 12, 4000, 'CNY', 'BBB');

        self::assertSame($first, ShippingCoverageService::preferredRow($first, $second));
    }

    public function testRowSemanticsAndArrayShape(): void
    {
        $row = new ShippingCoverageRow('US', 7, 15, 5200, 'CNY', 'SEED_LANE_AMERICAS');
        self::assertTrue($row->hasTransitDays());
        self::assertTrue($row->hasCost());
        self::assertSame([
            'country_code' => 'US',
            'days_min' => 7,
            'days_max' => 15,
            'cost_minor' => 5200,
            'currency' => 'CNY',
            'service_code' => 'SEED_LANE_AMERICAS',
        ], $row->toArray());

        $empty = new ShippingCoverageRow('US', 0, 0, 0, '', '');
        self::assertFalse($empty->hasTransitDays());
        self::assertFalse($empty->hasCost());
    }

    public function testServiceImplementsCoverageSourceInterface(): void
    {
        self::assertTrue(is_subclass_of(ShippingCoverageService::class, ShippingCoverageSourceInterface::class));
        self::assertTrue(interface_exists(ShippingCoverageSourceInterface::class));
    }
}
