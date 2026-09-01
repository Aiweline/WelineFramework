<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Marketing\Api\Quote\DiscountQuoteRequest;

/**
 * Maps Checkout freeze facts into RuleEngine context (major currency units).
 */
final class DiscountQuoteContextBuilder
{
    public function build(DiscountQuoteRequest $request): array
    {
        $precision = max(0, $request->currencyPrecision);
        $divisor = 10 ** $precision;

        $subtotalMinor = 0;
        $itemCount = 0;
        $products = [];
        foreach ($request->lines as $line) {
            $qty = max(1, (int)($line['qty_minor'] ?? 1));
            $unit = max(0, (int)($line['unit_price_minor'] ?? 0));
            $subtotalMinor += $qty * $unit;
            $itemCount += $qty;
            $products[] = [
                'sku' => trim((string)($line['sku'] ?? '')),
                'product_id' => (int)($line['product_id'] ?? 0),
                'price' => $unit / $divisor,
                'qty' => $qty,
            ];
        }
        if ($subtotalMinor === 0 && $request->orders !== []) {
            foreach ($request->orders as $order) {
                $subtotalMinor += (int)($order['subtotal_minor'] ?? 0);
                foreach ($order['items'] ?? [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $qty = max(1, (int)($item['qty_minor'] ?? 1));
                    $unit = max(0, (int)($item['unit_price_minor'] ?? 0));
                    $itemCount += $qty;
                    $products[] = [
                        'sku' => trim((string)($item['sku'] ?? '')),
                        'product_id' => (int)($item['product_id'] ?? 0),
                        'price' => $unit / $divisor,
                        'qty' => $qty,
                    ];
                }
            }
        }

        $subtotalMajor = $subtotalMinor / $divisor;
        $shippingMajor = $request->shippingAmountMinor / $divisor;

        return [
            'customer_id' => $request->customerId,
            'currency' => strtoupper(trim($request->currency)),
            'subtotal' => $subtotalMajor,
            'shipping_amount' => $shippingMajor,
            'payment_method' => trim((string)($request->paymentMethod ?? '')),
            'cart_hash' => $request->cartHash,
            'order' => [
                'subtotal' => $subtotalMajor,
                'total' => $subtotalMajor,
                'shipping_amount' => $shippingMajor,
                'item_count' => $itemCount,
                'currency' => strtoupper(trim($request->currency)),
            ],
            'products' => $products,
            'items' => $products,
            'scope' => $request->scope,
            'address' => $request->address,
        ];
    }
}
