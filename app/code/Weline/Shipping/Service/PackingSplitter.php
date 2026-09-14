<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * Split cart lines into virtual boxes by max chargeable weight / volume.
 */
final class PackingSplitter
{
    public function __construct(
        private readonly ChargeableWeightService $chargeableWeight = new ChargeableWeightService(),
    ) {
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<list<array<string,mixed>>>
     */
    public function splitBoxes(array $lines, float $maxWeightKg, float $maxVolumeCm3): array
    {
        $maxWeightKg = max(0.001, $maxWeightKg);
        $maxVolumeCm3 = max(1.0, $maxVolumeCm3);
        $units = [];
        foreach ($lines as $line) {
            if (!(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            $qty = max(1, (int)($line['qty_minor'] ?? $line['qty'] ?? 1));
            $unit = $line;
            $unit['qty_minor'] = 1;
            $unit['qty'] = 1;
            if (isset($unit['row_total_minor'])) {
                $unit['row_total_minor'] = (int)round(((int)$unit['row_total_minor']) / $qty);
            }
            $summary = $this->chargeableWeight->summarize([$unit]);
            $weight = max(0.0, (float)$summary['weight_kg']);
            $length = (float)($line['length_cm'] ?? $line['length'] ?? 0);
            $width = (float)($line['width_cm'] ?? $line['width'] ?? 0);
            $height = (float)($line['height_cm'] ?? $line['height'] ?? 0);
            $volume = ($length > 0 && $width > 0 && $height > 0)
                ? ($length * $width * $height)
                : 0.0;
            for ($i = 0; $i < $qty; $i++) {
                $units[] = [
                    'line' => $unit,
                    'weight_kg' => $weight,
                    'volume_cm3' => $volume,
                ];
            }
        }
        if ($units === []) {
            return [];
        }

        $boxes = [];
        $current = [];
        $curW = 0.0;
        $curV = 0.0;
        foreach ($units as $u) {
            $w = (float)$u['weight_kg'];
            $v = (float)$u['volume_cm3'];
            $overflow = $current !== [] && (
                ($curW + $w) > $maxWeightKg + 0.0001
                || ($v > 0 && ($curV + $v) > $maxVolumeCm3 + 0.0001)
            );
            if ($overflow) {
                $boxes[] = array_map(static fn(array $x) => $x['line'], $current);
                $current = [];
                $curW = 0.0;
                $curV = 0.0;
            }
            $current[] = $u;
            $curW += $w;
            $curV += $v;
        }
        if ($current !== []) {
            $boxes[] = array_map(static fn(array $x) => $x['line'], $current);
        }

        return $boxes;
    }
}
