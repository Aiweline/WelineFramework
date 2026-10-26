<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Points\Service\EarnCalculator;

final class EarnCalculatorTest extends TestCase
{
    public function testCalculatesWholeMajorUnitsAndFloors(): void
    {
        $result = (new EarnCalculator())->calculate(1280, 2, 1.0, 1.0);

        self::assertSame(12, $result['major_units']);
        self::assertSame(12, $result['base_points']);
        self::assertSame(12, $result['points']);
        self::assertSame(12, $result['growth']);
    }

    public function testAmountBelowOneMajorUnitEarnsNothing(): void
    {
        $result = (new EarnCalculator())->calculate(99, 2, 1.0, 1.0);

        self::assertSame(0, $result['points']);
        self::assertSame(0, $result['growth']);
    }

    public function testZeroPrecisionCurrencyUsesWholeUnits(): void
    {
        $result = (new EarnCalculator())->calculate(1500, 0, 1.0, 1.0);

        self::assertSame(1500, $result['major_units']);
        self::assertSame(1500, $result['points']);
    }

    public function testVipMultiplierOnlyScalesPoints(): void
    {
        $result = (new EarnCalculator())->calculate(1000, 2, 1.0, 1.0, 1.5);

        self::assertSame(10, $result['base_points']);
        self::assertSame(15, $result['points']);
        self::assertSame(10, $result['growth']);
    }

    public function testFractionalRateFloorsPoints(): void
    {
        $result = (new EarnCalculator())->calculate(1000, 2, 0.5, 2.0);

        self::assertSame(5, $result['points']);
        self::assertSame(20, $result['growth']);
    }
}
