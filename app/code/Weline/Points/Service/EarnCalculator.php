<?php

declare(strict_types=1);

namespace Weline\Points\Service;

/**
 * Integer point/growth calculation.
 *
 * Points are whole points, never currency cents. Cash is converted to whole
 * major currency units first, as frozen by the product rules.
 */
final class EarnCalculator
{
    /**
     * @return array{
     *   major_units:int,
     *   base_points:int,
     *   points:int,
     *   growth:int
     * }
     */
    public function calculate(
        int $cashMinor,
        int $precision,
        float $pointsRate,
        float $growthRate,
        float $multiplier = 1.0,
    ): array {
        $cashMinor = max(0, $cashMinor);
        $precision = max(0, min(6, $precision));
        $scale = 10 ** $precision;
        $majorUnits = intdiv($cashMinor, $scale);

        $basePoints = (int) floor($majorUnits * max(0.0, $pointsRate));
        $points = (int) floor($basePoints * max(0.0, $multiplier));
        $growth = (int) floor($majorUnits * max(0.0, $growthRate));

        return [
            'major_units' => $majorUnits,
            'base_points' => $basePoints,
            'points' => $points,
            'growth' => $growth,
        ];
    }

    /**
     * Per-product line points. A positive fixed value overrides ratio earning.
     *
     * @return array{major_units:int, points:int, fixed:bool}
     */
    public function linePoints(
        int $cashBaseMinor,
        int $precision,
        float $pointsRate,
        float $multiplier,
        float $ratio,
        ?int $fixedPointsPerUnit,
        int $quantity,
    ): array {
        $quantity = max(1, $quantity);
        if ($fixedPointsPerUnit !== null && $fixedPointsPerUnit > 0) {
            return [
                'major_units' => 0,
                'points' => max(0, $fixedPointsPerUnit * $quantity),
                'fixed' => true,
            ];
        }

        $precision = max(0, min(6, $precision));
        $majorUnits = intdiv(max(0, $cashBaseMinor), 10 ** $precision);
        $points = (int) floor(
            $majorUnits
            * max(0.0, $pointsRate)
            * max(0.0, $multiplier)
            * max(0.0, $ratio),
        );

        return [
            'major_units' => $majorUnits,
            'points' => max(0, $points),
            'fixed' => false,
        ];
    }
}
