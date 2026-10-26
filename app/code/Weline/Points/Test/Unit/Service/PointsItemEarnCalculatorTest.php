<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Points\Service\PointsBaseCurrencyResolver;
use Weline\Points\Service\PointsItemEarnCalculator;

final class PointsItemEarnCalculatorTest extends TestCase
{
    private function resolver(): PointsBaseCurrencyResolver
    {
        return new class extends PointsBaseCurrencyResolver {
            public function forWebsite(int $websiteId): string
            {
                return 'CNY';
            }
        };
    }

    public function testProductRatioMultipliesLinePoints(): void
    {
        $result = (new PointsItemEarnCalculator($this->resolver()))->calculate(
            [[
                'product_id' => 10,
                'qty_minor' => 2,
                'row_total_minor' => 5000,
            ]],
            5000,
            5000,
            'CNY',
            0,
            2,
            2,
            1.0,
            1.0,
            1.0,
            0,
            static fn (int $productId): array => ['ratio' => 2.0, 'fixed' => null],
        );

        self::assertTrue($result['ok']);
        self::assertSame(100, $result['points']);
        self::assertSame(2.0, $result['items'][0]['ratio']);
    }

    public function testFixedPointsPerUnitOverridesRatio(): void
    {
        $result = (new PointsItemEarnCalculator($this->resolver()))->calculate(
            [[
                'product_id' => 10,
                'qty_minor' => 3,
                'row_total_minor' => 5000,
            ]],
            5000,
            5000,
            'CNY',
            0,
            2,
            2,
            1.0,
            1.0,
            1.0,
            0,
            static fn (int $productId): array => ['ratio' => 99.0, 'fixed' => 7],
        );

        self::assertTrue($result['ok']);
        self::assertSame(21, $result['points']);
        self::assertTrue($result['items'][0]['fixed'] === 7);
    }

    public function testCashRatioExcludesPointsPaidPortion(): void
    {
        $result = (new PointsItemEarnCalculator($this->resolver()))->calculate(
            [[
                'product_id' => 10,
                'qty_minor' => 1,
                'row_total_minor' => 10000,
            ]],
            10000,
            7000,
            'CNY',
            0,
            2,
            2,
            1.0,
            1.0,
            1.0,
            0,
            static fn (int $productId): array => ['ratio' => null, 'fixed' => null],
        );

        self::assertTrue($result['ok']);
        self::assertSame(70, $result['points']);
        self::assertSame(7000, $result['items'][0]['line_cash_checkout_minor']);
    }

    public function testZeroProductIdIsSkipped(): void
    {
        $result = (new PointsItemEarnCalculator($this->resolver()))->calculate(
            [['product_id' => 0, 'qty_minor' => 1, 'row_total_minor' => 10000]],
            10000,
            10000,
            'CNY',
            0,
            2,
            2,
            1.0,
            1.0,
            1.0,
            0,
            static fn (int $productId): array => ['ratio' => 1.0, 'fixed' => null],
        );

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['points']);
    }
}
