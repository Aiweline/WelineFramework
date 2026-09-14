<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * Chargeable weight from cart lines (actual vs volumetric).
 */
final class ChargeableWeightService
{
    public const VOLUME_DIVISOR_CM = 5000.0;

    /**
     * @param list<array<string,mixed>> $lines
     * @return array{weight_kg:float,has_shipping:bool,missing_weight:bool,missing_dims:bool}
     */
    public function summarize(array $lines): array
    {
        $weightKg = 0.0;
        $hasShipping = false;
        $missingWeight = false;
        $missingDims = false;
        foreach ($lines as $line) {
            if (!(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            $hasShipping = true;
            $qty = max(0, (int)($line['qty_minor'] ?? 0));
            if ($qty <= 0) {
                $qty = 1;
            }
            $lineWeightMinor = max(0, (int)($line['weight_minor'] ?? 0));
            $actualKg = $lineWeightMinor / 1000.0;
            if ($actualKg <= 0) {
                $missingWeight = true;
            }
            $length = (float)($line['length_cm'] ?? $line['length'] ?? 0);
            $width = (float)($line['width_cm'] ?? $line['width'] ?? 0);
            $height = (float)($line['height_cm'] ?? $line['height'] ?? 0);
            $volKg = 0.0;
            if ($length > 0 && $width > 0 && $height > 0) {
                $volKg = ($length * $width * $height) / self::VOLUME_DIVISOR_CM;
            } else {
                $missingDims = true;
            }
            $unitChargeable = max($actualKg, $volKg);
            $weightKg += $unitChargeable * $qty;
        }

        return [
            'weight_kg' => $weightKg,
            'has_shipping' => $hasShipping,
            'missing_weight' => $missingWeight,
            'missing_dims' => $missingDims,
        ];
    }
}
