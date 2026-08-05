<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Service\RateCalculationService;

/**
 * TEST-P2E-06: production template arithmetic stays in integer minor units.
 */
final class RateCalculationServiceTest extends TestCase
{
    private function calculator(): RateCalculationService
    {
        return (new \ReflectionClass(RateCalculationService::class))
            ->newInstanceWithoutConstructor();
    }

    /** @param array<string, mixed> $data */
    private function template(string $type, array $data = []): RateTemplate
    {
        $template = (new \ReflectionClass(RateTemplate::class))
            ->newInstanceWithoutConstructor();
        $template->setData([
            RateTemplate::schema_fields_CALCULATION_TYPE => $type,
            RateTemplate::schema_fields_BASE_FEE => '1.00',
            RateTemplate::schema_fields_WEIGHT_RATE => '0',
            RateTemplate::schema_fields_VOLUME_RATE => '0',
            RateTemplate::schema_fields_QUANTITY_RATE => '0',
            RateTemplate::schema_fields_MIXED_CONFIG => '{}',
            RateTemplate::schema_fields_IS_ACTIVE => 1,
            ...$data,
        ]);

        return $template;
    }

    public function testFixedQuantityWeightVolumeAndMixedUseMinorUnits(): void
    {
        $calculator = $this->calculator();
        $lines = [[
            'qty_minor' => 3,
            'weight_minor' => 1500,
            'volume_minor' => 500000,
        ]];

        self::assertSame(
            1234,
            $calculator->calculateTemplateMinor(
                $this->template(
                    RateTemplate::CALC_TYPE_FIXED,
                    [RateTemplate::schema_fields_BASE_FEE => '12.34'],
                ),
                $lines,
            ),
        );
        self::assertSame(
            850,
            $calculator->calculateTemplateMinor(
                $this->template(
                    RateTemplate::CALC_TYPE_QUANTITY,
                    [RateTemplate::schema_fields_QUANTITY_RATE => '2.50'],
                ),
                $lines,
            ),
        );
        self::assertSame(
            475,
            $calculator->calculateTemplateMinor(
                $this->template(
                    RateTemplate::CALC_TYPE_WEIGHT,
                    [RateTemplate::schema_fields_WEIGHT_RATE => '2.50'],
                ),
                $lines,
            ),
        );
        self::assertSame(
            300,
            $calculator->calculateTemplateMinor(
                $this->template(
                    RateTemplate::CALC_TYPE_VOLUME,
                    [RateTemplate::schema_fields_VOLUME_RATE => '4.00'],
                ),
                $lines,
            ),
        );
        self::assertSame(
            900,
            $calculator->calculateTemplateMinor(
                $this->template(
                    RateTemplate::CALC_TYPE_MIXED,
                    [
                        RateTemplate::schema_fields_MIXED_CONFIG => json_encode([
                            'weight' => ['enabled' => true, 'rate' => '2.00'],
                            'volume' => ['enabled' => true, 'rate' => '4.00'],
                            'quantity' => ['enabled' => true, 'rate' => '1.00'],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ),
                $lines,
            ),
        );
    }

    public function testRoundingInactiveTemplateAndOverflowFailDeterministically(): void
    {
        $calculator = $this->calculator();
        self::assertSame(
            101,
            $calculator->calculateTemplateMinor(
                $this->template(
                    RateTemplate::CALC_TYPE_FIXED,
                    [RateTemplate::schema_fields_BASE_FEE => '1.005'],
                ),
                [],
            ),
        );

        $this->expectException(\RuntimeException::class);
        $calculator->calculateTemplateMinor(
            $this->template(
                RateTemplate::CALC_TYPE_FIXED,
                [RateTemplate::schema_fields_IS_ACTIVE => 0],
            ),
            [],
        );
    }

    public function testQuantityOverflowIsRejected(): void
    {
        $calculator = $this->calculator();
        $this->expectException(\OverflowException::class);
        $calculator->calculateTemplateMinor(
            $this->template(
                RateTemplate::CALC_TYPE_QUANTITY,
                [RateTemplate::schema_fields_QUANTITY_RATE => '1.00'],
            ),
            [['qty_minor' => PHP_INT_MAX]],
        );
    }
}
