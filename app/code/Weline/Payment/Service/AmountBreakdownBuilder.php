<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;

/**
 * 站内 amount_snapshot + discount_lines → 中性 breakdown DTO（amount_minor）。
 * Provider 负责映射网关 JSON；壳不拼 PayPal body。
 */
final class AmountBreakdownBuilder
{
    /**
     * @param array<string, mixed> $amountSnapshot
     * @param list<array<string, mixed>>|null $discountLines
     * @return array{
     *   currency_code: string,
     *   value_minor: int,
     *   item_total_minor: int,
     *   shipping_minor: int,
     *   handling_minor: int,
     *   tax_total_minor: int,
     *   insurance_minor: int,
     *   shipping_discount_minor: int,
     *   discount_minor: int,
     *   description?: string
     * }
     */
    public function fromSnapshot(array $amountSnapshot, ?array $discountLines = null, string $fallbackCurrency = 'CNY'): array
    {
        $currency = strtoupper(trim((string) ($amountSnapshot['currency'] ?? $amountSnapshot['currency_code'] ?? $fallbackCurrency)));
        if ($currency === '') {
            $currency = strtoupper($fallbackCurrency !== '' ? $fallbackCurrency : 'CNY');
        }

        $lines = $discountLines;
        if ($lines === null) {
            $lines = \is_array($amountSnapshot['discount_lines'] ?? null) ? $amountSnapshot['discount_lines'] : [];
        }

        $shippingDiscount = 0;
        $discount = 0;
        $labels = [];
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $abs = abs((int) ($line['amount_minor'] ?? 0));
            if ($abs <= 0) {
                continue;
            }
            $source = strtolower(trim((string) ($line['source_type'] ?? '')));
            if ($source === 'shipping') {
                $shippingDiscount += $abs;
            } else {
                $discount += $abs;
            }
            $label = trim((string) ($line['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label . ' ' . (-1 * $abs);
            }
        }

        if ($discount <= 0) {
            $discount = max(0, (int) ($amountSnapshot['discount_amount_minor'] ?? 0));
        }

        $shipping = max(0, (int) ($amountSnapshot['shipping_amount_minor'] ?? 0));
        $tax = max(0, (int) ($amountSnapshot['tax_amount_minor'] ?? 0));
        $handling = max(0, (int) ($amountSnapshot['handling_amount_minor'] ?? 0));
        $insurance = max(0, (int) ($amountSnapshot['insurance_amount_minor'] ?? 0));
        $value = max(0, (int) ($amountSnapshot['grand_total_minor'] ?? $amountSnapshot['amount_minor'] ?? 0));

        $itemTotal = max(0, (int) ($amountSnapshot['subtotal_minor'] ?? 0));
        if ($itemTotal <= 0) {
            // value = item + tax + shipping + handling + insurance − shipping_discount − discount
            $itemTotal = max(0, $value - $tax - $shipping - $handling - $insurance + $shippingDiscount + $discount);
        }

        // 舍入守恒：以 value 为准，微调 discount
        $computed = $itemTotal + $tax + $shipping + $handling + $insurance - $shippingDiscount - $discount;
        if ($computed !== $value) {
            $delta = $computed - $value;
            $discount = max(0, $discount + $delta);
            $computed = $itemTotal + $tax + $shipping + $handling + $insurance - $shippingDiscount - $discount;
            if ($computed !== $value) {
                $itemTotal = max(0, $value - $tax - $shipping - $handling - $insurance + $shippingDiscount + $discount);
            }
        }

        $out = [
            'currency_code' => $currency,
            'value_minor' => $value,
            'item_total_minor' => $itemTotal,
            'shipping_minor' => $shipping,
            'handling_minor' => $handling,
            'tax_total_minor' => $tax,
            'insurance_minor' => $insurance,
            'shipping_discount_minor' => $shippingDiscount,
            'discount_minor' => $discount,
        ];
        if ($labels !== []) {
            $out['description'] = implode('; ', array_slice($labels, 0, 6));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $orderData
     * @return array<string, mixed>
     */
    public function fromOrderData(array $orderData): array
    {
        $currency = strtoupper(trim((string) ($orderData['currency'] ?? $orderData['currency_code'] ?? 'CNY')));
        $totals = \is_array($orderData['totals'] ?? null) ? $orderData['totals'] : [];
        $amountMinor = (int) ($orderData['amount_minor'] ?? round(((float) ($orderData['amount'] ?? 0)) * 100));
        $snapshot = [
            'currency' => $currency,
            'subtotal_minor' => (int) ($totals['subtotal_minor'] ?? 0),
            'shipping_amount_minor' => (int) ($totals['shipping_amount_minor'] ?? 0),
            'tax_amount_minor' => (int) ($totals['tax_amount_minor'] ?? 0),
            'handling_amount_minor' => (int) ($totals['handling_amount_minor'] ?? 0),
            'insurance_amount_minor' => (int) ($totals['insurance_amount_minor'] ?? 0),
            'discount_amount_minor' => (int) ($totals['discount_amount_minor'] ?? 0),
            'grand_total_minor' => (int) ($totals['grand_total_minor'] ?? $amountMinor),
            'discount_lines' => \is_array($orderData['discount_lines'] ?? null) ? $orderData['discount_lines'] : [],
        ];

        return $this->fromSnapshot($snapshot, $snapshot['discount_lines'], $currency);
    }

    /**
     * 按比例缩放至目标 value_minor（如 CNY→USD 沙箱折算），并保持代数守恒。
     *
     * @param array<string, mixed> $breakdown
     * @return array<string, mixed>
     */
    public function scaleToValue(array $breakdown, int $targetValueMinor, string $targetCurrency): array
    {
        $fromValue = max(0, (int) ($breakdown['value_minor'] ?? 0));
        $targetValueMinor = max(0, $targetValueMinor);
        $targetCurrency = strtoupper(trim($targetCurrency));
        if ($fromValue <= 0 || $fromValue === $targetValueMinor) {
            $breakdown['currency_code'] = $targetCurrency !== '' ? $targetCurrency : (string) ($breakdown['currency_code'] ?? 'USD');
            $breakdown['value_minor'] = $targetValueMinor;

            return $breakdown;
        }

        $ratio = $targetValueMinor / $fromValue;
        $keys = [
            'item_total_minor',
            'shipping_minor',
            'handling_minor',
            'tax_total_minor',
            'insurance_minor',
            'shipping_discount_minor',
            'discount_minor',
        ];
        foreach ($keys as $key) {
            $breakdown[$key] = (int) round(max(0, (int) ($breakdown[$key] ?? 0)) * $ratio);
        }
        $breakdown['value_minor'] = $targetValueMinor;
        $breakdown['currency_code'] = $targetCurrency !== '' ? $targetCurrency : (string) ($breakdown['currency_code'] ?? 'USD');

        $computed = (int) $breakdown['item_total_minor']
            + (int) $breakdown['tax_total_minor']
            + (int) $breakdown['shipping_minor']
            + (int) $breakdown['handling_minor']
            + (int) $breakdown['insurance_minor']
            - (int) $breakdown['shipping_discount_minor']
            - (int) $breakdown['discount_minor'];
        if ($computed !== $targetValueMinor) {
            $breakdown['discount_minor'] = max(0, (int) $breakdown['discount_minor'] + ($computed - $targetValueMinor));
            $computed = (int) $breakdown['item_total_minor']
                + (int) $breakdown['tax_total_minor']
                + (int) $breakdown['shipping_minor']
                + (int) $breakdown['handling_minor']
                + (int) $breakdown['insurance_minor']
                - (int) $breakdown['shipping_discount_minor']
                - (int) $breakdown['discount_minor'];
            if ($computed !== $targetValueMinor) {
                $breakdown['item_total_minor'] = max(
                    0,
                    $targetValueMinor
                    - (int) $breakdown['tax_total_minor']
                    - (int) $breakdown['shipping_minor']
                    - (int) $breakdown['handling_minor']
                    - (int) $breakdown['insurance_minor']
                    + (int) $breakdown['shipping_discount_minor']
                    + (int) $breakdown['discount_minor'],
                );
            }
        }

        return $breakdown;
    }

    /**
     * @param list<array<string, mixed>> $discountLines
     */
    public function hasIncentiveLine(array $discountLines): bool
    {
        foreach ($discountLines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            if (strtolower(trim((string) ($line['source_type'] ?? ''))) === PaymentMethodIncentiveQuoteInterface::SOURCE_TYPE) {
                return true;
            }
        }

        return false;
    }
}
