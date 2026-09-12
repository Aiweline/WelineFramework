<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Order\Model\Order as OrderModel;

/**
 * Re-quote shipping (+ tax/discount best-effort) and write back unpaid express orders.
 * Core provider address streets stay authoritative; only gap phone/email may be applied.
 *
 * @return array{ok:bool,message?:string,address?:array<string,mixed>,totals?:array<string,mixed>,shipping_amount_minor?:int}
 */
final class ExpressUnpaidOrderAmend
{
    /**
     * @param array<string, mixed> $profile Express / gap address fields
     * @param array<string, mixed> $options service_code, currency, gap-only flags
     * @return array<string, mixed>
     */
    public function amend(OrderModel $order, array $profile, array $options = []): array
    {
        $status = strtolower(trim((string) $order->getData(OrderModel::schema_fields_STATUS)));
        if (in_array($status, ['paid', 'fulfilled', 'completed', 'refunded', 'cancelled', 'canceled'], true)) {
            return ['ok' => false, 'message' => (string) __('已支付订单不可修改')];
        }

        $existingRaw = $order->getData(OrderModel::schema_fields_SHIPPING_ADDRESS);
        $existing = [];
        if (is_string($existingRaw) && $existingRaw !== '') {
            $decoded = json_decode($existingRaw, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        } elseif (is_array($existingRaw)) {
            $existing = $existingRaw;
        }

        $address = $this->mergeAddressReadOnlyCore($existing, $profile);
        $serviceCode = trim((string) ($options['service_code'] ?? $order->getData(OrderModel::schema_fields_SHIPPING_METHOD) ?? ''));
        $currency = strtoupper(trim((string) ($options['currency'] ?? $order->getData(OrderModel::schema_fields_CURRENCY) ?? 'CNY'))) ?: 'CNY';

        $catalog = $order->getData(OrderModel::schema_fields_CATALOG_SNAPSHOT_JSON);
        if (is_string($catalog) && $catalog !== '') {
            $catalog = json_decode($catalog, true);
        }
        $items = [];
        if (is_array($catalog)) {
            $items = is_array($catalog['lines'] ?? null) ? $catalog['lines'] : (array_is_list($catalog) ? $catalog : []);
        }

        $requiresShipping = $this->orderRequiresShipping($items);
        $shippingAmountMinor = 0;
        if ($requiresShipping) {
            $shippingAmountMinor = $this->quoteShippingMinor($address, $items, $currency, $serviceCode);
        }

        $moneyRaw = $order->getData(OrderModel::schema_fields_MONEY_SNAPSHOT_JSON);
        $money = [];
        if (is_string($moneyRaw) && $moneyRaw !== '') {
            $decodedMoney = json_decode($moneyRaw, true);
            if (is_array($decodedMoney)) {
                $money = $decodedMoney;
            }
        }
        $subtotalMinor = (int) ($money['subtotal_minor'] ?? round(((float) $order->getData(OrderModel::schema_fields_SUBTOTAL)) * 100));
        $taxAmountMinor = (int) ($money['tax_amount_minor'] ?? round(((float) $order->getData(OrderModel::schema_fields_TAX_AMOUNT)) * 100));
        $discountAmountMinor = (int) ($money['discount_amount_minor'] ?? round(((float) $order->getData(OrderModel::schema_fields_DISCOUNT_AMOUNT)) * 100));

        $scope = [
            'website_id' => (int) $order->getData(OrderModel::schema_fields_WEBSITE_ID),
            'store_id' => (int) $order->getData(OrderModel::schema_fields_STORE_ID),
            'channel_id' => (int) RequestContext::getWelineChannelId(),
        ];
        $taxAmountMinor = $this->quoteTaxMinor($items, $scope, $address, $currency, $taxAmountMinor);
        $discountAmountMinor = $this->quoteDiscountMinor(
            $items,
            $scope,
            $address,
            $currency,
            $shippingAmountMinor,
            ($cid = (int) $order->getData(OrderModel::schema_fields_CUSTOMER_ID)) > 0 ? $cid : null,
            $discountAmountMinor,
        );

        $grandTotalMinor = max(0, $subtotalMinor + $shippingAmountMinor + $taxAmountMinor - $discountAmountMinor);
        $toMajor = static fn (int $minor): float => round($minor / 100, 2);

        $shippingSnapshot = [
            'method' => $serviceCode,
            'service_code' => $serviceCode,
            'address' => $address,
            'amount_minor' => $shippingAmountMinor,
        ];
        $moneyOut = array_replace($money, [
            'subtotal_minor' => $subtotalMinor,
            'shipping_amount_minor' => $shippingAmountMinor,
            'tax_amount_minor' => $taxAmountMinor,
            'discount_amount_minor' => $discountAmountMinor,
            'grand_total_minor' => $grandTotalMinor,
        ]);

        try {
            $order->setData(OrderModel::schema_fields_SHIPPING_AMOUNT, $toMajor($shippingAmountMinor));
            $order->setData(OrderModel::schema_fields_TAX_AMOUNT, $toMajor($taxAmountMinor));
            $order->setData(OrderModel::schema_fields_DISCOUNT_AMOUNT, $toMajor($discountAmountMinor));
            $order->setData(OrderModel::schema_fields_GRAND_TOTAL, $toMajor($grandTotalMinor));
            $order->setData(
                OrderModel::schema_fields_SHIPPING_ADDRESS,
                json_encode($address, JSON_UNESCAPED_UNICODE),
            );
            if ($serviceCode !== '') {
                $order->setData(OrderModel::schema_fields_SHIPPING_METHOD, $serviceCode);
            }
            $order->setData(
                OrderModel::schema_fields_MONEY_SNAPSHOT_JSON,
                json_encode($moneyOut, JSON_UNESCAPED_UNICODE),
            );
            $order->setData(
                OrderModel::schema_fields_SHIPPING_SNAPSHOT_JSON,
                json_encode($shippingSnapshot, JSON_UNESCAPED_UNICODE),
            );
            $order->save();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'address' => $address,
            'shipping_amount_minor' => $shippingAmountMinor,
            'totals' => [
                'currency' => $currency,
                'subtotal' => $toMajor($subtotalMinor),
                'shipping_amount' => $toMajor($shippingAmountMinor),
                'tax_amount' => $toMajor($taxAmountMinor),
                'discount_amount' => $toMajor($discountAmountMinor),
                'grand_total' => $toMajor($grandTotalMinor),
                'subtotal_minor' => $subtotalMinor,
                'shipping_amount_minor' => $shippingAmountMinor,
                'tax_amount_minor' => $taxAmountMinor,
                'discount_amount_minor' => $discountAmountMinor,
                'grand_total_minor' => $grandTotalMinor,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeAddressReadOnlyCore(array $base, array $incoming): array
    {
        $out = $base !== [] ? $base : $incoming;
        foreach (['contact_name', 'name', 'street', 'address1', 'address2', 'country_code', 'province', 'city', 'district', 'postal_code'] as $key) {
            $fromBase = trim((string) ($base[$key] ?? ''));
            if ($fromBase !== '') {
                $out[$key] = $fromBase;
            } elseif (trim((string) ($incoming[$key] ?? '')) !== '') {
                $out[$key] = trim((string) $incoming[$key]);
            }
        }
        // Gaps only: phone / email may be filled from confirm form.
        $phone = trim((string) ($incoming['contact_phone'] ?? $incoming['phone'] ?? ''));
        if ($phone !== '') {
            $out['contact_phone'] = $phone;
            $out['phone'] = $phone;
        } elseif (!isset($out['contact_phone']) && !isset($out['phone'])) {
            $out['contact_phone'] = '';
            $out['phone'] = '';
        }
        $email = trim((string) ($incoming['email'] ?? ''));
        if ($email !== '') {
            $out['email'] = $email;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function orderRequiresShipping(array $items): bool
    {
        if ($items === []) {
            return true;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((bool) ($item['requires_shipping'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function linesForQuote(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = [
                'requires_shipping' => (bool) ($item['requires_shipping'] ?? true),
                'qty_minor' => max(1, (int) ($item['qty_minor'] ?? $item['qty'] ?? 1)),
                'unit_price_minor' => (int) ($item['unit_price_minor'] ?? 0),
                'row_total_minor' => (int) ($item['row_total_minor'] ?? 0),
                'weight_minor' => (int) ($item['weight_minor'] ?? 0),
                'volume_minor' => (int) ($item['volume_minor'] ?? 0),
                'sku' => (string) ($item['sku'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'offer_id' => (int) ($item['offer_id'] ?? 0),
                'split_key' => (string) ($item['split_key'] ?? 'default'),
                'fulfillment_metadata' => is_array($item['fulfillment_metadata'] ?? null)
                    ? $item['fulfillment_metadata']
                    : [],
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     */
    private function quoteShippingMinor(array $address, array $items, string $currency, string $serviceCode): int
    {
        try {
            $result = w_query('shippingInfo', 'listQuoteOptions', [
                'address' => $address,
                'lines' => $this->linesForQuote($items),
                'currency' => $currency,
                'currency_precision' => 2,
                'scope' => [
                    'website_id' => (int) RequestContext::getWelineWebsiteId(),
                    'store_id' => (int) RequestContext::getWelineStoreId(),
                    'channel_id' => (int) RequestContext::getWelineChannelId(),
                ],
            ]);
        } catch (\Throwable) {
            return 0;
        }
        if (!is_array($result) || empty($result['success'])) {
            return 0;
        }
        $options = is_array($result['data']['options'] ?? null) ? $result['data']['options'] : [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $code = trim((string) ($option['service_code'] ?? $option['code'] ?? ''));
            if ($serviceCode !== '' && $code !== $serviceCode) {
                continue;
            }

            return (int) ($option['amount_minor'] ?? 0);
        }
        if ($options !== [] && is_array($options[0])) {
            return (int) ($options[0]['amount_minor'] ?? 0);
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $address
     */
    private function quoteTaxMinor(
        array $items,
        array $scope,
        array $address,
        string $currency,
        int $fallback,
    ): int {
        try {
            if (!interface_exists(\Weline\Tax\Api\CheckoutTaxAdvisorInterface::class)) {
                return $fallback;
            }
            $advisor = ObjectManager::getInstance(\Weline\Tax\Api\CheckoutTaxAdvisorInterface::class);
            if (!is_object($advisor) || !method_exists($advisor, 'quoteTax')) {
                return $fallback;
            }
            $tax = $advisor->quoteTax([['lines' => $this->linesForQuote($items)]], $scope, $address, $currency);
            if (is_array($tax) && array_key_exists('tax_amount_minor', $tax)) {
                return max(0, (int) $tax['tax_amount_minor']);
            }
        } catch (\Throwable) {
        }

        return $fallback;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $address
     */
    private function quoteDiscountMinor(
        array $items,
        array $scope,
        array $address,
        string $currency,
        int $shippingAmountMinor,
        ?int $customerId,
        int $fallback,
    ): int {
        try {
            if (!interface_exists(\Weline\Marketing\Api\DiscountQuoteServiceInterface::class)) {
                return $fallback;
            }
            $svc = ObjectManager::getInstance(\Weline\Marketing\Api\DiscountQuoteServiceInterface::class);
            if (!is_object($svc) || !method_exists($svc, 'quote')) {
                return $fallback;
            }
            if (!class_exists(\Weline\Marketing\Api\Quote\DiscountQuoteRequest::class)) {
                return $fallback;
            }
            $lines = $this->linesForQuote($items);
            $request = new \Weline\Marketing\Api\Quote\DiscountQuoteRequest(
                scope: $scope,
                address: $address,
                lines: $lines,
                orders: [['lines' => $lines]],
                currency: $currency,
                currencyPrecision: 2,
                customerId: $customerId,
                shippingAmountMinor: $shippingAmountMinor,
                couponCode: null,
            );
            $quote = $svc->quote($request);
            if (is_object($quote) && method_exists($quote, 'toArray')) {
                $arr = $quote->toArray();

                return max(0, (int) ($arr['amount_minor'] ?? $fallback));
            }
        } catch (\Throwable) {
        }

        return $fallback;
    }
}
