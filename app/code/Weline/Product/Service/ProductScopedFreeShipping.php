<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Product-scoped free shipping: waive freight for qualifying lines only.
 * Never promotes whole-cart free shipping.
 */
final class ProductScopedFreeShipping
{
    /**
     * @param array<string, mixed> $line Cart/checkout/shipping quote line
     */
    public static function isEnabled(array $line): bool
    {
        if (!empty($line['is_free_shipping'])) {
            return true;
        }
        $meta = $line['fulfillment_metadata'] ?? null;
        if (!\is_array($meta)) {
            return false;
        }
        $flag = $meta['is_free_shipping'] ?? false;

        return $flag === true || $flag === 1 || $flag === '1';
    }

    /**
     * Catalog major-unit threshold (0 = no minimum once enabled).
     *
     * @param array<string, mixed> $line
     */
    public static function minAmountMajor(array $line): float
    {
        if (isset($line['free_shipping_min_amount']) && is_numeric($line['free_shipping_min_amount'])) {
            return max(0.0, (float)$line['free_shipping_min_amount']);
        }
        $meta = $line['fulfillment_metadata'] ?? null;
        if (\is_array($meta) && isset($meta['free_shipping_min_amount']) && is_numeric($meta['free_shipping_min_amount'])) {
            return max(0.0, (float)$meta['free_shipping_min_amount']);
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function rowTotalMinor(array $line): int
    {
        if (array_key_exists('row_total_minor', $line)) {
            return max(0, (int)$line['row_total_minor']);
        }
        $qty = max(0, (int)($line['qty_minor'] ?? $line['qty'] ?? 0));
        $unit = max(0, (int)($line['unit_price_minor'] ?? 0));

        return $qty * $unit;
    }

    /**
     * Line qualifies for product-scoped freight waive (not cart-wide free shipping).
     *
     * @param array<string, mixed> $line
     */
    public static function lineQualifies(array $line, int $currencyPrecision = 2): bool
    {
        if (!self::isEnabled($line)) {
            return false;
        }
        if (array_key_exists('requires_shipping', $line) && empty($line['requires_shipping'])) {
            return false;
        }
        $minMajor = self::minAmountMajor($line);
        if ($minMajor <= 0.0) {
            return true;
        }
        $precision = max(0, min(6, $currencyPrecision));
        $scale = 10 ** $precision;
        $minMinor = (int)round($minMajor * $scale);

        return self::rowTotalMinor($line) >= $minMinor;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [billable, waived]
     */
    public static function partition(array $lines, int $currencyPrecision = 2): array
    {
        $billable = [];
        $waived = [];
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            if (self::lineQualifies($line, $currencyPrecision)) {
                $waived[] = $line;
            } else {
                $billable[] = $line;
            }
        }

        return [$billable, $waived];
    }

    /**
     * Shelf badge: enabled and (no threshold or shelf price meets threshold).
     *
     * @param array<string, mixed> $product
     */
    public static function shelfBadgeVisible(array $product): bool
    {
        $enabled = !empty($product['is_free_shipping']) || !empty($product['free_shipping']);
        if (!$enabled) {
            $meta = $product['fulfillment_metadata'] ?? null;
            if (\is_array($meta)) {
                $flag = $meta['is_free_shipping'] ?? false;
                $enabled = $flag === true || $flag === 1 || $flag === '1';
            }
        }
        if (!$enabled) {
            return false;
        }
        $minMajor = 0.0;
        if (isset($product['free_shipping_min_amount']) && is_numeric($product['free_shipping_min_amount'])) {
            $minMajor = max(0.0, (float)$product['free_shipping_min_amount']);
        } elseif (\is_array($product['fulfillment_metadata'] ?? null)
            && isset($product['fulfillment_metadata']['free_shipping_min_amount'])
            && is_numeric($product['fulfillment_metadata']['free_shipping_min_amount'])
        ) {
            $minMajor = max(0.0, (float)$product['fulfillment_metadata']['free_shipping_min_amount']);
        }
        if ($minMajor <= 0.0) {
            return true;
        }
        $price = (float)($product['price'] ?? 0);

        return $price + 1e-9 >= $minMajor;
    }
}
