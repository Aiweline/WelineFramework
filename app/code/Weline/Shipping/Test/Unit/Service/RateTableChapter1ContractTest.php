<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Exception\ShippingRateUnavailableException;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Service\ChargeableWeightService;
use Weline\Shipping\Service\RateBracketValidator;
use Weline\Shipping\Service\RateCalculationService;
use Weline\Shipping\Service\SeedWeightBracketFactory;

final class RateTableChapter1ContractTest extends TestCase
{
    public function testSeedAmericasBracketsMatchPlan(): void
    {
        $brackets = SeedWeightBracketFactory::fromLinear(45, 14, SeedWeightBracketFactory::GENERAL_BOUNDS);
        self::assertCount(7, $brackets);
        self::assertSame('52.00', $brackets[0]['price']);
        self::assertSame('465.00', $brackets[6]['price']);
        self::assertSame(30.0, $brackets[6]['max']);
    }

    public function testAmericas2kgHits115Bracket(): void
    {
        $brackets = SeedWeightBracketFactory::fromLinear(45, 14, SeedWeightBracketFactory::GENERAL_BOUNDS);
        // 2kg ∈ [2,5) → price at max=5 → 45+14*5=115
        self::assertSame('115.00', $brackets[3]['price']);
        self::assertSame(2.0, $brackets[3]['min']);
        self::assertSame(5.0, $brackets[3]['max']);
    }

    public function testWeightTableQuotesAmericas2kgAndRefusesOverMax(): void
    {
        $tpl = new RateTemplate();
        $tpl->setData([
            RateTemplate::schema_fields_IS_ACTIVE => 1,
            RateTemplate::schema_fields_CALCULATION_TYPE => RateTemplate::CALC_TYPE_WEIGHT_TABLE,
            RateTemplate::schema_fields_MAX_WEIGHT_KG => 30,
            RateTemplate::schema_fields_RATE_BRACKETS => json_encode(
                SeedWeightBracketFactory::fromLinear(45, 14, SeedWeightBracketFactory::GENERAL_BOUNDS),
                JSON_UNESCAPED_UNICODE,
            ),
        ]);
        $calc = new RateCalculationService(
            $this->createMock(\Weline\Framework\Manager\ObjectManager::class),
        );
        $minor = $calc->calculateTemplateMinor($tpl, [
            [
                'requires_shipping' => true,
                'qty_minor' => 1,
                'weight_minor' => 2000,
                'length_cm' => 10,
                'width_cm' => 10,
                'height_cm' => 10,
            ],
        ], 2);
        self::assertSame(11500, $minor);

        $this->expectException(ShippingRateUnavailableException::class);
        $calc->calculateTemplateMinor($tpl, [
            [
                'requires_shipping' => true,
                'qty_minor' => 1,
                'weight_minor' => 35000,
                'length_cm' => 20,
                'width_cm' => 20,
                'height_cm' => 20,
            ],
        ], 2);
    }

    public function testBracketValidatorRejectsGaps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RateBracketValidator())->normalizeAndValidate([
            ['min' => 0, 'max' => 1, 'price' => '10'],
            ['min' => 2, 'max' => 3, 'price' => '20'],
        ], 3.0);
    }

    public function testChargeableWeightUsesVolumetric(): void
    {
        $summary = (new ChargeableWeightService())->summarize([
            [
                'requires_shipping' => true,
                'qty_minor' => 1,
                'weight_minor' => 500, // 0.5kg
                'length_cm' => 50,
                'width_cm' => 40,
                'height_cm' => 30, // 12kg volumetric
            ],
        ]);
        self::assertEqualsWithDelta(12.0, $summary['weight_kg'], 0.01);
        self::assertFalse($summary['missing_weight']);
        self::assertFalse($summary['missing_dims']);
    }

    public function testMissingWeightAndDimsFlags(): void
    {
        $svc = new ChargeableWeightService();
        $noWeight = $svc->summarize([[
            'requires_shipping' => true,
            'qty_minor' => 1,
            'weight_minor' => 0,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
        ]]);
        self::assertTrue($noWeight['missing_weight']);
        $noDims = $svc->summarize([[
            'requires_shipping' => true,
            'qty_minor' => 1,
            'weight_minor' => 2000,
            'length_cm' => 0,
            'width_cm' => 0,
            'height_cm' => 0,
        ]]);
        self::assertTrue($noDims['missing_dims']);
    }
}
