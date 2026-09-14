<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * Address point-type and hazard capability gates for lanes.
 */
final class ShippingCapabilityGate
{
    public const POINT_RESIDENTIAL = 'residential';
    public const POINT_COMMERCIAL = 'commercial';
    public const POINT_PICKUP = 'pickup_point';
    public const POINT_POBOX = 'pobox';
    public const POINT_MILITARY = 'military';

    public const DEFAULT_ALLOWED_POINTS = 'residential,commercial,pickup_point';

    /**
     * @param list<string>|string $allowedCsv
     */
    public function allowsPointType(string|array $allowedCsv, string $pointType): bool
    {
        $point = strtolower(trim($pointType));
        if ($point === '') {
            $point = self::POINT_RESIDENTIAL;
        }
        $allowed = $this->splitCsv($allowedCsv);
        if ($allowed === []) {
            $allowed = $this->splitCsv(self::DEFAULT_ALLOWED_POINTS);
        }

        return in_array($point, $allowed, true);
    }

    /**
     * Empty accepted list = general cargo only (no hazard classes on lines).
     *
     * @param list<string>|string $acceptedCsv
     * @param list<string> $lineHazardClasses
     */
    public function allowsHazards(string|array $acceptedCsv, array $lineHazardClasses): bool
    {
        $needed = [];
        foreach ($lineHazardClasses as $c) {
            $c = strtolower(trim((string)$c));
            if ($c !== '' && $c !== 'none' && $c !== 'general') {
                $needed[$c] = true;
            }
        }
        if ($needed === []) {
            return true;
        }
        $accepted = $this->splitCsv($acceptedCsv);
        if ($accepted === []) {
            return false;
        }
        foreach (array_keys($needed) as $c) {
            if (!in_array($c, $accepted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    public function collectLineHazards(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            $raw = $line['shipping_hazard_class']
                ?? ($line['fulfillment_metadata']['shipping_hazard_class'] ?? '');
            foreach ($this->splitCsv(is_array($raw) ? implode(',', $raw) : (string)$raw) as $c) {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string>|string $csv
     * @return list<string>
     */
    private function splitCsv(string|array $csv): array
    {
        if (is_array($csv)) {
            $parts = $csv;
        } else {
            $parts = preg_split('/\s*,\s*/', trim($csv)) ?: [];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim((string)$p));
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
