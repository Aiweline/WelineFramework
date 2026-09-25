<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Product\Service\ProductScopedFreeShipping;

/**
 * Mini-cart chrome for product-scoped free-shipping lines.
 * Never marks the whole cart as free-shipping unlocked.
 */
final class FreeShippingProgressService
{
    /**
     * @param array<string, mixed> $summary Cart summary
     * @return array{
     *     enabled:bool,
     *     source?:string,
     *     qualified?:bool,
     *     progress_percent?:int,
     *     free_item_count?:int,
     *     paid_item_count?:int,
     *     message?:string,
     *     message_qualified?:string
     * }
     */
    public function build(array $summary): array
    {
        $items = \is_array($summary['items'] ?? null) ? $summary['items'] : [];
        $currencyPrecision = max(0, min(6, (int)($summary['currency_precision'] ?? 2)));
        $freeCount = 0;
        $paidCount = 0;
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (array_key_exists('requires_shipping', $item) && empty($item['requires_shipping'])) {
                continue;
            }
            if (ProductScopedFreeShipping::lineQualifies($item, $currencyPrecision)) {
                $freeCount++;
            } else {
                $paidCount++;
            }
        }

        // Only surface when at least one line is product-waived AND some lines still pay —
        // never advertise whole-cart free shipping from product flags.
        if ($freeCount <= 0 || $paidCount <= 0) {
            return ['enabled' => false];
        }

        $shippable = $freeCount + $paidCount;
        $percent = $shippable > 0
            ? (int)round(100 * $freeCount / $shippable)
            : 0;

        return [
            'enabled' => true,
            'source' => 'product_line',
            // Hard: product flags must not unlock whole-cart free shipping UX.
            'qualified' => false,
            'progress_percent' => max(0, min(99, $percent)),
            'free_item_count' => $freeCount,
            'paid_item_count' => $paidCount,
            'message' => $this->translate(
                '已有 %1 件本品包邮，另 %2 件仍需计运费',
                $freeCount,
                $paidCount,
            ),
            'message_qualified' => $this->translate(
                '已有 %1 件本品包邮，另 %2 件仍需计运费',
                $freeCount,
                $paidCount,
            ),
        ];
    }

    private function translate(string $source, int ...$args): string
    {
        if (\function_exists('__')) {
            return (string)__($source, ...$args);
        }
        $out = $source;
        foreach ($args as $i => $arg) {
            $out = str_replace('%' . ($i + 1), (string)$arg, $out);
        }

        return $out;
    }
}
