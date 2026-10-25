<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Merchant\MerchantInfoConfigResolver;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingCommercePolicy;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\ShippingCommercePolicyService;
use Weline\Shipping\Service\ShippingProfileResolver;

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

            $details = [];
            foreach ($serviceIds as $serviceId) {
                /** @var ShippingService $serviceModel */
                $serviceModel = ObjectManager::getInstance(ShippingService::class, [], false);
                $items = $serviceModel->reset()
                    ->where(ShippingService::schema_fields_ID, (int)$serviceId)
                    ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetch()
                    ->getItems();
                $service = is_array($items) ? ($items[0] ?? null) : null;
                if (!$service instanceof ShippingService) {
                    continue;
                }

                /** @var ServiceRegion $regionModel */
                $regionModel = ObjectManager::getInstance(ServiceRegion::class, [], false);
                $regions = $regionModel->reset()
                    ->where(ServiceRegion::schema_fields_SERVICE_ID, (int)$serviceId)
                    ->where(ServiceRegion::schema_fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetch()
                    ->getItems();
                $codes = [];
                foreach (is_array($regions) ? $regions : [] as $region) {
                    if (!$region instanceof ServiceRegion) {
                        continue;
                    }
                    $code = strtoupper(trim((string)$region->getData(ServiceRegion::schema_fields_COUNTRY_CODE)));
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
                if ($codes === []) {
                    continue;
                }
                $addressCountry = count($codes) === 1 ? array_key_first($codes) : array_values(array_keys($codes));

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
     * @param array<string, mixed> $detail
     */
    private static function destinationCount(array $detail): int
    {
        $countries = $detail['shippingDestination']['addressCountry'] ?? null;

        return is_array($countries) ? count($countries) : 1;
    }
}
