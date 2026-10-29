<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Merchant\MerchantInfoConfigResolver;
use Weline\Shipping\Model\ShippingCommercePolicy;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\ShippingCommercePolicyService;
use Weline\Shipping\Service\ShippingProfileResolver;
use Weline\Shipping\Service\ShippingServiceBatchLoader;

final class ProductCommerceFactsResolver
{
    private ?array $merchantInfo = null;

    public function resolve(array $product, array $context = []): array
    {
        return [
            'merchant_return_policy' => $this->returnPolicy($product, $context),
            'shipping_details' => $this->shippingDetails($product, $context),
        ];
    }

    /**
     * 商家信息（seo_merchant）为权威来源；退货运费未配置时回落到 Shipping 退货策略事实。
     */
    private function returnPolicy(array $product, array $context): ?array
    {
        try {
            $info = $this->merchantInfo();
            $country = $info['country'] !== '' ? (string)$info['country'] : 'WW';
            $returnDays = (int)$info['return_days'];

            $policy = [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => $country,
                'returnPolicyCategory' => $returnDays > 0
                    ? 'https://schema.org/MerchantReturnFiniteReturnWindow'
                    : 'https://schema.org/MerchantReturnNotPermitted',
                'returnMethod' => $info['return_method'] === MerchantInfoConfigResolver::RETURN_METHOD_IN_STORE
                    ? 'https://schema.org/ReturnInStore'
                    : 'https://schema.org/ReturnByMail',
            ];
            if ($returnDays > 0) {
                $policy['merchantReturnDays'] = $returnDays;
            }
            $policy['returnFees'] = $this->returnFeesUrl($info, $product, $context);
            // Google requires the amount whenever returnFees is ReturnShippingFees.
            // Publish it only when the merchant saved an explicit amount.
            if ($policy['returnFees'] === 'https://schema.org/ReturnShippingFees') {
                $amount = $this->returnShippingFeesAmount($info, $product, $context);
                if ($amount !== null) {
                    $policy['returnShippingFeesAmount'] = $amount;
                }
            }

            $link = $this->absoluteUrl((string)$info['return_policy_url'], $context);
            if ($link !== '') {
                $policy['merchantReturnLink'] = $link;
            }

            return $policy;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $product
     * @param array<string, mixed> $context
     */
    private function returnFeesUrl(array $info, array $product, array $context): string
    {
        if (!empty($info['configured'][MerchantInfoConfigResolver::KEY_RETURN_FEES])) {
            return ($info['return_fees'] ?? '') === MerchantInfoConfigResolver::RETURN_FEES_FREE
                ? 'https://schema.org/FreeReturn'
                : 'https://schema.org/ReturnShippingFees';
        }

        if (!class_exists(ShippingCommercePolicyService::class)) {
            return 'https://schema.org/ReturnShippingFees';
        }

        try {
            $scopeId = max(0, (int)($context['website_id'] ?? $product['website_id'] ?? 0));
            /** @var ShippingCommercePolicyService $service */
            $service = ObjectManager::getInstance(ShippingCommercePolicyService::class);
            $policy = $service->resolve([
                'scope_type' => ShippingCommercePolicy::SCOPE_WEBSITE,
                'scope_id' => $scopeId,
                'website_id' => $scopeId,
            ]);
        } catch (\Throwable) {
            return 'https://schema.org/ReturnShippingFees';
        }

        return (string)($policy['return_policy'] ?? '') === ShippingCommercePolicy::RETURN_SELLER
            ? 'https://schema.org/FreeReturn'
            : 'https://schema.org/ReturnShippingFees';
    }

    /**
     * 退回运费金额：仅在商家信息显式保存 seo/merchant/return_shipping_fee 时输出
     * （0.00 表示商家不收取固定退货运费、买家自行支付承运商）；未配置则不编造。
     *
     * @param array<string, mixed> $info
     * @param array<string, mixed> $product
     * @param array<string, mixed> $context
     * @return array{@type:string,value:string,currency:string}|null
     */
    private function returnShippingFeesAmount(array $info, array $product, array $context): ?array
    {
        if (empty($info['configured'][MerchantInfoConfigResolver::KEY_RETURN_SHIPPING_FEE])) {
            return null;
        }

        $currency = strtoupper(trim((string)($context['currency'] ?? $product['currency'] ?? $product['price_currency'] ?? '')));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $amount = max(0.0, (float)($info['return_shipping_fee'] ?? 0.0));
        $sourceCurrency = $this->baseCurrency();
        if ($amount > 0.0 && $sourceCurrency !== '' && $sourceCurrency !== $currency) {
            $converted = $this->convertCurrency($amount, $sourceCurrency, $currency);
            if ($converted !== null) {
                $amount = $converted;
            } else {
                $currency = $sourceCurrency;
            }
        }

        return [
            '@type' => 'MonetaryAmount',
            'value' => number_format(max(0.0, $amount), 2, '.', ''),
            'currency' => $currency,
        ];
    }

    private function baseCurrency(): string
    {
        try {
            if (!class_exists(\Weline\Currency\Service\CurrencyRateService::class)) {
                return 'CNY';
            }
            /** @var \Weline\Currency\Service\CurrencyRateService $rates */
            $rates = ObjectManager::getInstance(\Weline\Currency\Service\CurrencyRateService::class);

            return strtoupper(trim((string)$rates->getBaseCurrency())) ?: 'CNY';
        } catch (\Throwable) {
            return 'CNY';
        }
    }

    private function convertCurrency(float $amount, string $source, string $target): ?float
    {
        try {
            if (!class_exists(\Weline\Currency\Service\CurrencyRateService::class)) {
                return null;
            }
            /** @var \Weline\Currency\Service\CurrencyRateService $rates */
            $rates = ObjectManager::getInstance(\Weline\Currency\Service\CurrencyRateService::class);
            $converted = $rates->tryConvert($amount, $source, $target);

            return is_float($converted) || is_int($converted) ? (float)$converted : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function absoluteUrl(string $url, array $context): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = trim((string)($context['site_url'] ?? $context['canonical_url'] ?? $context['url'] ?? ''));
        if ($base === '') {
            return '';
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return rtrim($origin, '/') . '/' . ltrim($url, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function merchantInfo(): array
    {
        if ($this->merchantInfo !== null) {
            return $this->merchantInfo;
        }
        /** @var MerchantInfoConfigResolver $resolver */
        $resolver = ObjectManager::getInstance(MerchantInfoConfigResolver::class);

        return $this->merchantInfo = $resolver->resolve();
    }

    private function shippingDetails(array $product, array $context): array|null
    {
        try {
            if (!class_exists(ShippingProfileResolver::class)) {
                return null;
            }
            $scopeType = ShippingCommercePolicy::SCOPE_WEBSITE;
            $scopeId = max(0, (int)($context['website_id'] ?? $product['website_id'] ?? 0));
            /** @var ShippingProfileResolver $resolver */
            $resolver = ObjectManager::getInstance(ShippingProfileResolver::class);
            $profileCode = trim((string)($product['shipping_profile_code'] ?? $context['shipping_profile_code'] ?? ''));
            $resolved = $profileCode !== ''
                ? $resolver->resolveByCode($profileCode, [
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'website_id' => $scopeId,
                ])
                : $resolver->resolveGeneral($scopeType, $scopeId);
            if ($resolved === null) {
                return null;
            }
            $serviceIds = $resolver->serviceIdsForProfile((int)$resolved['profile']->getId());
            if ($serviceIds === []) {
                return null;
            }

            $currency = strtoupper(trim((string)($context['currency'] ?? $product['currency'] ?? 'CNY')));
            if ($currency === '') {
                $currency = 'CNY';
            }

            /** @var ShippingServiceBatchLoader $batch */
            $batch = ObjectManager::getInstance(ShippingServiceBatchLoader::class);
            $details = $this->buildShippingDetails(
                $serviceIds,
                $batch->loadActiveServices($serviceIds),
                $batch->loadActiveRegionCountryCodes($serviceIds),
                $currency,
            );

            if ($details === []) {
                return null;
            }
            // One node per PDP: keep the widest destination lane and borrow the
            // fastest lane's delivery window, so the JSON-LD stays a few hundred
            // bytes instead of one entry per shipping lane.
            usort(
                $details,
                static fn(array $left, array $right): int => (self::destinationCount($right) <=> self::destinationCount($left))
                    ?: (strlen((string)json_encode($right['shippingDestination'])) <=> strlen((string)json_encode($left['shippingDestination'])))
            );
            $primary = $details[0];
            if (!isset($primary['deliveryTime'])) {
                foreach ($details as $detail) {
                    if (isset($detail['deliveryTime'])) {
                        $primary['deliveryTime'] = $detail['deliveryTime'];
                        break;
                    }
                }
            }

            return $primary;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pure OfferShippingDetails assembly from already resolved rows.
     *
     * Kept free of I/O so the batched path can be compared byte for byte with
     * the per-service queries it replaced (see
     * ProductCommerceFactsResolverShippingDetailsTest).
     *
     * @param list<int> $serviceIds requested order, preserved in the output
     * @param array<int, ShippingService> $services active services keyed by service_id
     * @param array<int, list<string>> $regionCountryCodes upper-cased country codes keyed by service_id
     * @return list<array<string, mixed>>
     */
    private function buildShippingDetails(
        array $serviceIds,
        array $services,
        array $regionCountryCodes,
        string $currency,
    ): array {
        $details = [];
        foreach ($serviceIds as $serviceId) {
            $service = $services[(int)$serviceId] ?? null;
            if (!$service instanceof ShippingService) {
                continue;
            }
            $codes = $regionCountryCodes[(int)$serviceId] ?? [];
            if ($codes === []) {
                continue;
            }
            // $codes is a list of country codes (ShippingServiceBatchLoader returns
            // array_keys()), so the single-lane scalar is the first *value*, not the
            // first key: array_key_first() would yield the int 0 here.
            $addressCountry = count($codes) === 1 ? reset($codes) : array_values($codes);

            $detail = [
                '@type' => 'OfferShippingDetails',
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => $addressCountry,
                ],
            ];

            $min = max(0, (int)$service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MIN));
            $max = max(0, (int)$service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MAX));
            if ($max > 0) {
                if ($min > $max) {
                    [$min, $max] = [$max, $min];
                }
                $detail['deliveryTime'] = [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 0,
                        'maxValue' => 1,
                        'unitCode' => 'DAY',
                    ],
                    'transitTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => $min,
                        'maxValue' => $max,
                        'unitCode' => 'DAY',
                    ],
                ];
            }
            $detail['shippingRate'] = [
                '@type' => 'MonetaryAmount',
                'value' => 0,
                'currency' => $currency,
            ];
            $details[] = $detail;
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function destinationCount(array $detail): int
    {
        $countries = $detail['shippingDestination']['addressCountry'] ?? null;

        return is_array($countries) ? count($countries) : 1;
    }
}
