<?php

declare(strict_types=1);

namespace Weline\HelpPay\Service;

use Weline\Checkout\Service\CheckoutQuoteLineWeightResolver;
use Weline\Framework\Runtime\RequestContext;

/**
 * Quick-buy shipping quotes aligned with universal checkout weight gates.
 */
final class HelpPayQuickShippingQuoteService
{
    /** @var (callable(array):mixed)|null */
    private $listQuoteOptions;

    private readonly CheckoutQuoteLineWeightResolver $weights;

    /**
     * @param (callable(array):mixed)|null $listQuoteOptions
     */
    public function __construct(
        ?CheckoutQuoteLineWeightResolver $weights = null,
        ?callable $listQuoteOptions = null,
    ) {
        $this->weights = $weights ?? new CheckoutQuoteLineWeightResolver();
        $this->listQuoteOptions = $listQuoteOptions;
    }

    /**
     * @param list<array<string,mixed>> $options
     * @param array<string,mixed> $diagnostics
     */
    public static function forTesting(
        CheckoutQuoteLineWeightResolver $weights,
        array $options,
        array $diagnostics = [],
    ): self {
        return new self(
            $weights,
            static fn (array $_params): array => [
                'success' => true,
                'data' => [
                    'options' => $options,
                    'quote_diagnostics' => $diagnostics,
                ],
            ],
        );
    }

    /**
     * @param array{
     *   address?:array<string,mixed>,
     *   shipping_address?:array<string,mixed>,
     *   product_id?:int,
     *   qty?:int,
     *   goods_amount_minor?:int,
     *   currency_code?:string,
     *   currency?:string
     * } $input
     * @return array{
     *   options:list<array<string,mixed>>,
     *   quote_diagnostics:array<string,mixed>,
     *   missing_weight:bool,
     *   lines:list<array<string,mixed>>
     * }
     */
    public function listOptions(array $input): array
    {
        $address = is_array($input['address'] ?? null)
            ? $input['address']
            : (is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : []);
        $currency = trim((string) ($input['currency_code'] ?? $input['currency'] ?? 'CNY'));
        if ($currency === '') {
            $currency = 'CNY';
        }
        $lines = $this->buildQuoteLines($input);
        $result = $this->invokeListQuoteOptions([
            'address' => $this->normalizeAddress($address),
            'lines' => $lines,
            'currency' => $currency,
            'currency_precision' => 2,
            'scope' => [
                'website_id' => (int) RequestContext::getWelineWebsiteId(),
                'store_id' => (int) RequestContext::getWelineStoreId(),
                'channel_id' => (int) RequestContext::getWelineChannelId(),
            ],
        ]);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $rawOptions = is_array($data['options'] ?? null) ? $data['options'] : [];
        $diagnostics = is_array($data['quote_diagnostics'] ?? null) ? $data['quote_diagnostics'] : [];
        $missingWeight = !empty($diagnostics['missing_weight']);
        if (!$missingWeight) {
            foreach ($lines as $line) {
                if ((bool) ($line['requires_shipping'] ?? true) && (int) ($line['weight_minor'] ?? 0) <= 0) {
                    $missingWeight = true;
                    break;
                }
            }
        }
        $options = [];
        if (!$missingWeight) {
            foreach ($rawOptions as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $code = trim((string) ($option['service_code'] ?? $option['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $label = trim((string) ($option['label'] ?? $option['service_name'] ?? $option['title'] ?? $option['name'] ?? ''));
                if ($label === '') {
                    $label = $code;
                }
                $amountMinor = (int) ($option['amount_minor'] ?? 0);
                $options[] = [
                    'service_code' => $code,
                    'code' => $code,
                    'label' => $label,
                    'service_name' => $label,
                    'title' => $label,
                    'amount_minor' => $amountMinor,
                    'amount' => $amountMinor / 100,
                    'currency' => $currency,
                ];
            }
        }
        if ($missingWeight) {
            $diagnostics['missing_weight'] = true;
        }

        return [
            'options' => $options,
            'quote_diagnostics' => $diagnostics,
            'missing_weight' => $missingWeight,
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string,mixed> $input Same shape as listOptions + product/qty.
     */
    public function assertSelectedShipping(array $input, string $serviceCode, int $shippingAmountMinor): void
    {
        $serviceCode = trim($serviceCode);
        if ($serviceCode === '') {
            throw new \InvalidArgumentException('helppay_shipping_required');
        }
        $quoted = $this->listOptions($input);
        if (!empty($quoted['missing_weight'])) {
            throw new \InvalidArgumentException('helppay_missing_weight');
        }
        $matched = null;
        foreach ($quoted['options'] as $option) {
            if ((string) ($option['service_code'] ?? '') === $serviceCode) {
                $matched = $option;
                break;
            }
        }
        if ($matched === null) {
            throw new \InvalidArgumentException('helppay_shipping_unavailable');
        }
        if ((int) ($matched['amount_minor'] ?? 0) !== max(0, $shippingAmountMinor)) {
            throw new \InvalidArgumentException('helppay_shipping_mismatch');
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function buildQuoteLines(array $input): array
    {
        $productId = max(0, (int) ($input['product_id'] ?? 0));
        if ($productId <= 0 && is_array($input['line_summary'] ?? null)) {
            foreach ($input['line_summary'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $productId = max(0, (int) ($row['product_id'] ?? $row['id'] ?? 0));
                if ($productId > 0) {
                    break;
                }
            }
        }
        $qty = max(1, (int) ($input['qty'] ?? $input['qty_minor'] ?? 1));
        $goodsMinor = max(0, (int) ($input['goods_amount_minor'] ?? 0));
        $unit = $qty > 0 ? (int) max(0, (int) round($goodsMinor / $qty)) : $goodsMinor;
        $line = [
            'requires_shipping' => true,
            'qty' => $qty,
            'qty_minor' => $qty,
            'unit_price_minor' => $unit,
            'row_total_minor' => $goodsMinor,
            'weight_minor' => 0,
            'volume_minor' => 0,
            'product_id' => $productId,
        ];
        $enriched = $this->weights->enrichLines([$line]);

        return $enriched;
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private function normalizeAddress(array $address): array
    {
        $line1 = trim((string) ($address['line1'] ?? $address['street'] ?? $address['address1'] ?? ''));
        $country = strtoupper(trim((string) ($address['country_code'] ?? $address['country'] ?? 'CN'))) ?: 'CN';

        return [
            'name' => trim((string) ($address['name'] ?? $address['contact_name'] ?? '')),
            'contact_name' => trim((string) ($address['name'] ?? $address['contact_name'] ?? '')),
            'phone' => trim((string) ($address['phone'] ?? $address['contact_phone'] ?? '')),
            'contact_phone' => trim((string) ($address['phone'] ?? $address['contact_phone'] ?? '')),
            'street' => $line1,
            'address1' => $line1,
            'country' => $country,
            'country_code' => $country,
            'province' => trim((string) ($address['province'] ?? '')),
            'city' => trim((string) ($address['city'] ?? '')),
            'district' => trim((string) ($address['district'] ?? '')),
            'postal_code' => trim((string) ($address['postal_code'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function invokeListQuoteOptions(array $params): array
    {
        if ($this->listQuoteOptions !== null) {
            $result = ($this->listQuoteOptions)($params);

            return is_array($result) ? $result : [];
        }
        if (!function_exists('w_query')) {
            return ['success' => false, 'data' => []];
        }
        $result = w_query('shippingInfo', 'listQuoteOptions', $params);

        return is_array($result) ? $result : [];
    }
}
