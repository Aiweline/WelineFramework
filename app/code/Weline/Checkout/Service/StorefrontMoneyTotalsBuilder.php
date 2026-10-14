<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

/**
 * Single storefront money-totals channel for checkout SSR + getData + paint.
 *
 * Inputs: cart baseline (no payment incentive) + shipping/payment lists + selected codes
 * + tax_estimate. Output: money_summary DTO (minors + texts). Optional commerce Policy
 * adjusts once at the exit — never a second formula on the client.
 */
final class StorefrontMoneyTotalsBuilder
{
    /**
     * @param array{
     *   currency?:string,
     *   cart?:array<string,mixed>,
     *   shipping_methods?:list<array<string,mixed>>,
     *   payment_methods?:list<array<string,mixed>>,
     *   tax_estimate?:array<string,mixed>|null,
     *   selected_payment_method?:string,
     *   selected_shipping_method?:string,
     *   cart_type?:string,
     *   selling_mode?:string,
     *   policy_ctx?:array<string,mixed>
     * } $input
     * @return array<string, mixed>
     */
    public function build(array $input): array
    {
        $currency = strtoupper(trim((string)($input['currency'] ?? 'CNY')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'CNY';
        }
        $cart = \is_array($input['cart'] ?? null) ? $input['cart'] : [];
        $shippingMethods = \is_array($input['shipping_methods'] ?? null) ? $input['shipping_methods'] : [];
        $paymentMethods = \is_array($input['payment_methods'] ?? null) ? $input['payment_methods'] : [];
        $taxEstimate = \is_array($input['tax_estimate'] ?? null) ? $input['tax_estimate'] : [];
        $cartType = strtolower(trim((string)($input['cart_type'] ?? $cart['cart_type'] ?? 'toc'))) ?: 'toc';
        $sellingMode = strtolower(trim((string)($input['selling_mode'] ?? $cartType))) ?: $cartType;

        $selectedPayment = strtolower(trim((string)($input['selected_payment_method'] ?? '')));
        $selectedShipping = trim((string)($input['selected_shipping_method'] ?? ''));
        $payment = $this->resolveMethod($paymentMethods, $selectedPayment, 'code');
        $shipping = $this->resolveMethod($shippingMethods, $selectedShipping, 'code');
        if ($selectedPayment === '' && $payment !== []) {
            $selectedPayment = strtolower(trim((string)($payment['code'] ?? $payment['method_code'] ?? '')));
        }
        if ($selectedShipping === '' && $shipping !== []) {
            $selectedShipping = trim((string)($shipping['code'] ?? ''));
        }

        $goodsMinor = $this->toMinor($cart, 'subtotal', 'subtotal_minor');
        if ($goodsMinor <= 0) {
            $preview = \is_array($cart['discount_preview'] ?? null) ? $cart['discount_preview'] : [];
            $goodsMinor = $this->toMinor($preview, 'subtotal', 'subtotal_minor');
        }
        $discountPreview = \is_array($cart['discount_preview'] ?? null) ? $cart['discount_preview'] : [];
        $discountMinor = $this->toMinor($discountPreview, 'amount', 'amount_minor');
        $discountLabel = '';
        if ($discountMinor > 0) {
            $couponCode = strtoupper(trim((string)($discountPreview['coupon_code'] ?? '')));
            $discountLabel = $couponCode !== '' ? $couponCode : trim((string)($discountPreview['label'] ?? ''));
        }
        $shippingMinor = $this->shippingMinor($shipping);
        $salesTaxMinor = max(0, (int)($taxEstimate['sales_tax_amount_minor'] ?? $cart['sales_tax_amount_minor'] ?? 0));
        $dutyMinor = max(0, (int)($taxEstimate['duty_amount_minor'] ?? 0));
        $importTaxMinor = max(0, (int)($taxEstimate['import_tax_amount_minor'] ?? 0));
        // Prefer explicit split; fall back to charged duty lane only when split empty.
        if ($dutyMinor === 0 && $importTaxMinor === 0) {
            $charged = max(0, (int)($taxEstimate['tax_amount_minor'] ?? 0)) - $salesTaxMinor;
            if ($charged > 0) {
                $dutyMinor = $charged;
            }
        }
        $taxMinor = $salesTaxMinor + $dutyMinor + $importTaxMinor;

        $incentiveMinor = 0;
        if (!empty($payment['incentive_available'])) {
            $incentiveMinor = max(0, (int)($payment['incentive_savings_minor'] ?? 0));
        }
        $codFeeMinor = max(0, (int)($payment['cod_fee_amount_minor'] ?? 0));

        $payableMinor = max(
            0,
            $goodsMinor + $shippingMinor - $discountMinor + $taxMinor + $codFeeMinor - $incentiveMinor
        );

        $dto = [
            'currency' => $currency,
            'cart_type' => $cartType,
            'selling_mode' => $sellingMode,
            'selected_payment_method' => $selectedPayment,
            'selected_shipping_method' => $selectedShipping,
            'goods_subtotal_minor' => $goodsMinor,
            'shipping_minor' => $shippingMinor,
            'discount_minor' => $discountMinor,
            'discount_label' => $discountLabel,
            'sales_tax_minor' => $salesTaxMinor,
            'duty_minor' => $dutyMinor,
            'customs_duty_minor' => $dutyMinor,
            'import_tax_minor' => $importTaxMinor,
            'tax_minor' => $taxMinor,
            'cod_fee_minor' => $codFeeMinor,
            'payment_incentive_minor' => $incentiveMinor,
            'payable_minor' => $payableMinor,
            'deposit_minor' => 0,
            'credit_minor' => 0,
            'order_total_minor' => 0,
            'commerce_deposit_allowed' => false,
            'goods_text' => $this->money($currency, $goodsMinor / 100.0),
            'shipping_text' => $this->money($currency, $shippingMinor / 100.0),
            'payable_text' => $this->money($currency, $payableMinor / 100.0),
            'incentive_text' => $incentiveMinor > 0
                ? ('-' . $this->money($currency, $incentiveMinor / 100.0))
                : '',
            'incentive_minor' => $incentiveMinor,
            'cod_fee_text' => $codFeeMinor > 0
                ? $this->money($currency, $codFeeMinor / 100.0)
                : '',
            'note' => '',
        ];

        $policyCtx = \is_array($input['policy_ctx'] ?? null) ? $input['policy_ctx'] : [];
        $policyCtx += [
            'cart_type' => $cartType,
            'shell_cart_type' => $sellingMode,
        ];

        return $this->applyPolicy($dto, $policyCtx);
    }

    /**
     * Flatten money_summary onto SSR/getData payload money fields (same channel).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $moneySummary
     * @return array<string, mixed>
     */
    public function mergeIntoPayload(array $payload, array $moneySummary): array
    {
        $payload['money_summary'] = $moneySummary;
        $payload['goods_text'] = (string)($moneySummary['goods_text'] ?? $payload['goods_text'] ?? '');
        $payload['shipping_text'] = (string)($moneySummary['shipping_text'] ?? $payload['shipping_text'] ?? '');
        $payload['payable_text'] = (string)($moneySummary['payable_text'] ?? $payload['payable_text'] ?? '');
        $payload['incentive_text'] = (string)($moneySummary['incentive_text'] ?? '');
        $payload['incentive_minor'] = (int)($moneySummary['incentive_minor'] ?? $moneySummary['payment_incentive_minor'] ?? 0);
        $payload['cod_fee_text'] = (string)($moneySummary['cod_fee_text'] ?? '');
        $payload['cod_fee_minor'] = (int)($moneySummary['cod_fee_minor'] ?? 0);
        // Cart baseline stays without payment incentive; payable lives on money_summary only.
        $cart = \is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        if ($cart !== []) {
            // Do not overwrite cart.grand_total with payable — keep cart baseline semantics.
            $payload['cart'] = $cart;
        }

        return $payload;
    }

    /**
     * @param list<array<string,mixed>> $methods
     * @return array<string,mixed>
     */
    private function resolveMethod(array $methods, string $selected, string $codeKey): array
    {
        if ($methods === []) {
            return [];
        }
        if ($selected !== '') {
            foreach ($methods as $method) {
                if (!\is_array($method)) {
                    continue;
                }
                $code = strtolower(trim((string)($method[$codeKey] ?? $method['method_code'] ?? '')));
                if ($code !== '' && $code === strtolower($selected)) {
                    return $method;
                }
            }
        }
        $first = $methods[0] ?? null;

        return \is_array($first) ? $first : [];
    }

    /**
     * @param array<string,mixed> $shipping
     */
    private function shippingMinor(array $shipping): int
    {
        if ($shipping === []) {
            return 0;
        }
        if (isset($shipping['amount_minor']) && is_numeric($shipping['amount_minor'])) {
            return max(0, (int)$shipping['amount_minor']);
        }
        $major = 0.0;
        if (isset($shipping['amount']) && is_numeric($shipping['amount'])) {
            $major = (float)$shipping['amount'];
        } elseif (isset($shipping['fee']) && is_numeric($shipping['fee'])) {
            $major = (float)$shipping['fee'];
        }

        return (int)round(max(0.0, $major) * 100.0);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function toMinor(array $row, string $majorKey, string $minorKey): int
    {
        if (isset($row[$minorKey]) && is_numeric($row[$minorKey])) {
            $minor = (int)$row[$minorKey];
            if ($minor > 0) {
                return $minor;
            }
        }
        if (isset($row[$majorKey]) && is_numeric($row[$majorKey])) {
            return (int)round(max(0.0, (float)$row[$majorKey]) * 100.0);
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $dto
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function applyPolicy(array $dto, array $ctx): array
    {
        try {
            $policy = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Checkout\Api\StorefrontMoneySummaryPolicyInterface::class
            );
            if ($policy instanceof \Weline\Checkout\Api\StorefrontMoneySummaryPolicyInterface) {
                $adjusted = $policy->adjustDto($dto, $ctx);
                if (\is_array($adjusted)) {
                    // Keep display texts in sync after Policy mutates minors.
                    $currency = strtoupper(trim((string)($adjusted['currency'] ?? $dto['currency'] ?? 'CNY')));
                    $incentive = max(0, (int)($adjusted['payment_incentive_minor'] ?? 0));
                    $cod = max(0, (int)($adjusted['cod_fee_minor'] ?? 0));
                    $payable = max(0, (int)($adjusted['payable_minor'] ?? 0));
                    $goods = max(0, (int)($adjusted['goods_subtotal_minor'] ?? 0));
                    $shipping = max(0, (int)($adjusted['shipping_minor'] ?? 0));
                    $adjusted['incentive_minor'] = $incentive;
                    $adjusted['incentive_text'] = $incentive > 0
                        ? ('-' . $this->money($currency, $incentive / 100.0))
                        : '';
                    $adjusted['cod_fee_text'] = $cod > 0
                        ? $this->money($currency, $cod / 100.0)
                        : '';
                    $adjusted['goods_text'] = $this->money($currency, $goods / 100.0);
                    $adjusted['shipping_text'] = $this->money($currency, $shipping / 100.0);
                    $adjusted['payable_text'] = $this->money($currency, $payable / 100.0);

                    return $adjusted;
                }
            }
        } catch (\Throwable) {
            // Optional B2B — retail DTO unchanged.
        }

        return $dto;
    }

    private function money(string $currency, float $amount): string
    {
        if (\class_exists(\Weline\Currency\Helper\CurrencySymbol::class)) {
            return \Weline\Currency\Helper\CurrencySymbol::formatAmount($amount, $currency);
        }
        $code = \strtoupper(\trim($currency)) ?: 'CNY';
        $glyphs = ['CNY' => '¥', 'RMB' => '¥', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'];
        $symbol = $glyphs[$code] ?? $code;
        $formatted = \number_format($amount, 2, '.', ',');

        return $symbol === $code ? ($code . ' ' . $formatted) : ($symbol . $formatted);
    }
}
