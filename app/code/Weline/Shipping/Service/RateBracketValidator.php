<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * Validates Shopify-style continuous rate brackets from 0.
 */
final class RateBracketValidator
{
    /**
     * @param list<array<string,mixed>> $brackets
     * @return list<array{min:float,max:float|null,price:string}>
     */
    public function normalizeAndValidate(array $brackets, ?float $maxWeightKg = null): array
    {
        if ($brackets === []) {
            throw new \InvalidArgumentException('rate_brackets_empty');
        }
        $normalized = [];
        foreach ($brackets as $i => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('rate_bracket_row_invalid');
            }
            $min = (float)($row['min'] ?? -1);
            $maxRaw = $row['max'] ?? null;
            $max = ($maxRaw === null || $maxRaw === '') ? null : (float)$maxRaw;
            $price = trim((string)($row['price'] ?? ''));
            if ($min < 0 || ($max !== null && $max <= $min)) {
                throw new \InvalidArgumentException('rate_bracket_range_invalid:' . ($i + 1));
            }
            if ($price === '' || (float)$price < 0) {
                throw new \InvalidArgumentException('rate_bracket_price_invalid:' . ($i + 1));
            }
            if (!preg_match('/^\+?[0-9]+(?:\.[0-9]+)?$/D', $price)) {
                throw new \InvalidArgumentException('rate_bracket_price_format:' . ($i + 1));
            }
            $normalized[] = ['min' => $min, 'max' => $max, 'price' => $price];
        }
        usort($normalized, static fn(array $a, array $b): int => $a['min'] <=> $b['min']);
        if (abs($normalized[0]['min'] - 0.0) > 0.0000001) {
            throw new \InvalidArgumentException('rate_brackets_must_start_at_zero');
        }
        $count = count($normalized);
        for ($i = 0; $i < $count; $i++) {
            $isLast = $i === $count - 1;
            if ($isLast) {
                if ($maxWeightKg !== null && $maxWeightKg > 0) {
                    if ($normalized[$i]['max'] === null
                        || abs((float)$normalized[$i]['max'] - $maxWeightKg) > 0.0001
                    ) {
                        throw new \InvalidArgumentException('rate_bracket_last_max_mismatch');
                    }
                } elseif ($normalized[$i]['max'] === null) {
                    throw new \InvalidArgumentException('rate_bracket_last_must_be_finite');
                }
            } else {
                if ($normalized[$i]['max'] === null) {
                    throw new \InvalidArgumentException('rate_bracket_only_last_open');
                }
                if (abs((float)$normalized[$i]['max'] - $normalized[$i + 1]['min']) > 0.0001) {
                    throw new \InvalidArgumentException('rate_brackets_not_contiguous');
                }
            }
        }

        return $normalized;
    }
}
