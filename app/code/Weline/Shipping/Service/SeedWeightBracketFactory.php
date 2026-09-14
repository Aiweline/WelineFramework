<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Shipping\Model\RateTemplate;

/**
 * Builds Chapter-1 seed weight brackets from base+rate×upper.
 */
final class SeedWeightBracketFactory
{
    /** @var list<float> */
    public const GENERAL_BOUNDS = [0.0, 0.5, 1.0, 2.0, 5.0, 10.0, 20.0, 30.0];

    /** @var list<float> */
    public const HEAVY_BOUNDS = [0.0, 50.0, 100.0, 300.0, 1000.0];

    /**
     * @param list<float> $bounds
     * @return list<array{min:float,max:float,price:string}>
     */
    public static function fromLinear(float $base, float $ratePerKg, array $bounds): array
    {
        $out = [];
        for ($i = 0, $n = count($bounds) - 1; $i < $n; $i++) {
            $min = $bounds[$i];
            $max = $bounds[$i + 1];
            $price = round($base + $ratePerKg * $max, 2);
            $out[] = [
                'min' => $min,
                'max' => $max,
                'price' => number_format($price, 2, '.', ''),
            ];
        }

        return $out;
    }

    public static function generalMaxKg(): float
    {
        return 30.0;
    }

    public static function heavyMaxKg(): float
    {
        return 1000.0;
    }
}
