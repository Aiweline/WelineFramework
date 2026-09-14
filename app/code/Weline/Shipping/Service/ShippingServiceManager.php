<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Service;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\CarrierRegion;
use Weline\Shipping\Model\DestinationRegion;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingService;

/**
 * 配送服务管理服务
 * 
 * @package Weline_Shipping
 */
class ShippingServiceManager
{
    private ObjectManager $objectManager;
    private RateCalculationService $rateCalculationService;
    private FreeShippingService $freeShippingService;

    private ?CarrierCoverageMatchService $coverageMatch = null;
    private ?ServiceLaneMatchService $laneMatch = null;

    /** @var array<string, string> request-local memo keyed by scope_type:scope_id */
    private array $quoteConfigVersionMemo = [];

    /** @var array{country_code?:string,currency?:string,lane_count?:int,fx_skipped?:list<string>} */
    private array $lastQuoteDiagnostics = [];

    public function __construct(
        ObjectManager $objectManager,
        RateCalculationService $rateCalculationService,
        FreeShippingService $freeShippingService,
        ?CarrierCoverageMatchService $coverageMatch = null,
        ?ServiceLaneMatchService $laneMatch = null,
    ) {
        $this->objectManager = $objectManager;
        $this->rateCalculationService = $rateCalculationService;
        $this->freeShippingService = $freeShippingService;
        $this->coverageMatch = $coverageMatch;
        $this->laneMatch = $laneMatch;
    }

    /**
     * @return array{country_code?:string,currency?:string,lane_count?:int,fx_skipped?:list<string>}
     */
    public function getLastQuoteDiagnostics(): array
    {
        return $this->lastQuoteDiagnostics;
    }

    /**
     * 获取配送服务模型实例
     * 
     * @return ShippingService
     */
    private function getModel(): ShippingService
    {
        return $this->objectManager->getInstance(ShippingService::class, [], false);
    }

    /**
     * 根据收货地址获取可用配送服务
     * 
     * @param string $countryCode 国家代码
     * @param string|null $province 省/州
     * @param string|null $city 市
     * @param string|null $district 区县
     * @return array 配送服务列表
     */
    /**
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return list<array<string, mixed>>
     */
    public function getAvailableServices(
        string $countryCode,
        ?string $province = null,
        ?string $city = null,
        ?string $district = null,
        ?array $context = null,
        ?array $addressMeta = null,
        ?int $originShippingAddressId = null,
    ): array {
        // 唯一路径：承运商覆盖 ∩ 可售白名单（可空）∩ 非禁运，按承运商 sort_order。
        // 再按发货仓（默认或指定）+ 航线目的地覆盖过滤（空覆盖=通配兼容旧配置）。
        try {
            $address = [
                'country_code' => strtoupper(trim($countryCode)) ?: 'CN',
                'province' => trim((string)$province),
                'city' => trim((string)$city),
                'district' => trim((string)$district),
            ];
            if (\is_array($addressMeta)) {
                $address = array_merge($address, $addressMeta);
            }
            $services = $this->coverageMatch()->getAvailableServices(
                $countryCode,
                $province,
                $city,
                $district,
                $context,
                $address,
            );

            return $this->laneMatch()->filterByOriginAndDest($services, $address, $originShippingAddressId);
        } catch (\Throwable) {
            return [];
        }
    }

    private function coverageMatch(): CarrierCoverageMatchService
    {
        if ($this->coverageMatch instanceof CarrierCoverageMatchService) {
            return $this->coverageMatch;
        }

        return $this->coverageMatch = $this->objectManager->getInstance(CarrierCoverageMatchService::class);
    }

    private function laneMatch(): ServiceLaneMatchService
    {
        if ($this->laneMatch instanceof ServiceLaneMatchService) {
            return $this->laneMatch;
        }

        return $this->laneMatch = $this->objectManager->getInstance(ServiceLaneMatchService::class);
    }

    /**
     * 计算配送费用
     * 
     * @param int $serviceId 配送服务ID
     * @param float $orderAmount 订单金额
     * @param float $weight 重量（kg）
     * @param float $volume 体积（m³）
     * @param int $quantity 件数
     * @param int|null $memberLevelId 会员等级ID
     * @param int|null $regionId 地区ID
     * @param string|null $couponCode 优惠券代码
     * @return array 包含费用和是否免邮的信息
     */
    public function calculateShippingFee(
        int $serviceId,
        float $orderAmount = 0,
        float $weight = 0,
        float $volume = 0,
        int $quantity = 1,
        ?int $memberLevelId = null,
        ?int $regionId = null,
        ?string $couponCode = null
    ): array {
        $service = $this->getModel()->load($serviceId);
        if (!$service->getId()) {
            throw new \RuntimeException(__('配送服务不存在'));
        }
        
        // 检查是否配置为免邮
        if ($service->getData(ShippingService::schema_fields_IS_FREE_SHIPPING)) {
            return [
                'fee' => 0,
                'is_free' => true,
                'reason' => 'service_free_shipping',
            ];
        }
        
        // 检查免邮规则
        $freeShippingRuleId = $service->getData(ShippingService::schema_fields_FREE_SHIPPING_RULE_ID);
        if ($freeShippingRuleId) {
            $freeRule = $this->freeShippingService->checkFreeShipping(
                $orderAmount,
                $memberLevelId,
                $regionId,
                $couponCode
            );
            
            if ($freeRule && $freeRule->getId() == $freeShippingRuleId) {
                return [
                    'fee' => 0,
                    'is_free' => true,
                    'reason' => 'free_shipping_rule',
                    'rule_name' => $freeRule->getData('rule_name'),
                ];
            }
        }
        
        // 计算配送费用
        $rateTemplateId = $service->getData(ShippingService::schema_fields_RATE_TEMPLATE_ID);
        if (!$rateTemplateId) {
            return [
                'fee' => 0,
                'is_free' => false,
                'reason' => 'no_template',
            ];
        }
        
        $fee = $this->rateCalculationService->calculate($rateTemplateId, $weight, $volume, $quantity);
        
        return [
            'fee' => $fee,
            'is_free' => false,
            'reason' => 'calculated',
        ];
    }

    /**
     * Production Checkout Quote rates from active database configuration.
     *
     * @param array<string,mixed> $address
     * @param list<array<string,mixed>> $lines
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return array<string,array{amount_minor:int,label:string,currencies:list<string>,free_reason?:string}>
     */
    public function quoteRates(
        array $address,
        array $lines,
        string $currency,
        int $currencyPrecision = 2,
        ?array $context = null,
        ?int $originShippingAddressId = null,
        ?int $freeShippingSubtotalMinor = null,
    ): array {
        $currency = strtoupper(trim($currency));
        $rawCountry = strtoupper(trim((string)($address['country_code'] ?? '')));
        if ($rawCountry === '') {
            $rawCountry = strtoupper(trim((string)($address['country'] ?? '')));
        }
        // Only accept ISO-3166 alpha-2; never treat empty editor defaults as a reason to
        // invent CN when a non-ISO label slipped in (currency-reload cascade bug).
        if (!preg_match('/^[A-Z]{2}$/', $rawCountry)) {
            $rawCountry = $rawCountry === '' ? 'CN' : '';
        }
        $pointType = strtolower(trim((string)($address['delivery_point_type']
            ?? $address['point_type']
            ?? '')));
        if ($pointType === '') {
            $pointType = ShippingCapabilityGate::POINT_RESIDENTIAL;
        }
        $destAddress = [
            'country_code' => $rawCountry !== '' ? $rawCountry : 'CN',
            'province' => trim((string)($address['province'] ?? $address['region'] ?? '')),
            'city' => trim((string)($address['city'] ?? '')),
            'district' => trim((string)($address['district'] ?? '')),
            'province_region_id' => (int)($address['province_region_id'] ?? $address['province_id'] ?? 0),
            'city_region_id' => (int)($address['city_region_id'] ?? $address['city_id'] ?? 0),
            'district_region_id' => (int)($address['district_region_id'] ?? $address['district_id'] ?? 0),
            'province_code' => trim((string)($address['province_code'] ?? '')),
            'city_code' => trim((string)($address['city_code'] ?? '')),
            'district_code' => trim((string)($address['district_code'] ?? '')),
            'postcode' => trim((string)($address['postcode'] ?? $address['postal_code'] ?? '')),
            'postal_code' => trim((string)($address['postal_code'] ?? $address['postcode'] ?? '')),
            'delivery_point_type' => $pointType,
        ];
        if ($rawCountry === '') {
            $this->lastQuoteDiagnostics = [
                'country_code' => '',
                'currency' => $currency,
                'lane_count' => 0,
                'fx_skipped' => [],
            ];

            return [];
        }
        $services = $this->getAvailableServices(
            $destAddress['country_code'],
            $destAddress['province'],
            $destAddress['city'],
            $destAddress['district'],
            $context,
            $destAddress,
            $originShippingAddressId,
        );
        /** @var ShippingProfileResolver $profileResolver */
        $profileResolver = $this->objectManager->getInstance(ShippingProfileResolver::class);
        $profileGroups = $profileResolver->groupLinesByProfile($lines, $context);
        if ($profileGroups === []) {
            $this->lastQuoteDiagnostics = [
                'country_code' => $destAddress['country_code'],
                'currency' => $currency,
                'lane_count' => 0,
                'fx_skipped' => [],
            ];

            return [];
        }

        /** @var ShippingProviderManager $providerManager */
        $providerManager = $this->objectManager->getInstance(ShippingProviderManager::class);
        /** @var ShippingCapabilityGate $capabilityGate */
        $capabilityGate = $this->objectManager->getInstance(ShippingCapabilityGate::class);
        $fxSkipped = [];
        /** @var list<array<string, array<string, mixed>>> $perProfileRates */
        $perProfileRates = [];
        foreach ($profileGroups as $group) {
            $allowedIds = [];
            foreach ($group['service_ids'] as $sid) {
                $allowedIds[(int)$sid] = true;
            }
            $groupHazards = $capabilityGate->collectLineHazards($group['lines']);
            $matchedSummaries = [];
            foreach ($services as $summary) {
                $serviceId = (int)($summary['service_id'] ?? 0);
                if ($allowedIds !== [] && !isset($allowedIds[$serviceId])) {
                    continue;
                }
                $serviceCode = trim((string)($summary['service_code'] ?? ''));
                if ($serviceCode === '') {
                    continue;
                }
                $allowedPoints = $summary['allowed_point_types']
                    ?? ShippingCapabilityGate::DEFAULT_ALLOWED_POINTS;
                if (!$capabilityGate->allowsPointType($allowedPoints, $destAddress['delivery_point_type'])) {
                    continue;
                }
                $acceptedHazards = $summary['accepted_hazard_classes'] ?? '';
                if (!$capabilityGate->allowsHazards($acceptedHazards, $groupHazards)) {
                    continue;
                }
                $matchedSummaries[] = $summary;
            }
            if ($matchedSummaries === []) {
                throw new \RuntimeException('shipping_profile_conflict');
            }
            $byProvider = [];
            foreach ($matchedSummaries as $summary) {
                $carrierId = (int)($summary['carrier_id'] ?? 0);
                $providerCode = ShippingProviderManager::DEFAULT_PROVIDER_CODE;
                if ($carrierId > 0) {
                    /** @var \Weline\Shipping\Model\Carrier $carrier */
                    $carrier = $this->objectManager->getInstance(
                        \Weline\Shipping\Model\Carrier::class,
                        [],
                        false,
                    )->load($carrierId);
                    if ($carrier->getId()) {
                        $raw = (string)$carrier->getData(
                            \Weline\Shipping\Model\Carrier::schema_fields_PROVIDER_CODE,
                        );
                        $providerCode = strtolower(trim($raw)) !== ''
                            ? strtolower(trim($raw))
                            : ShippingProviderManager::DEFAULT_PROVIDER_CODE;
                    }
                }
                $summary['provider_code'] = $providerCode;
                $byProvider[$providerCode][] = $summary;
            }
            $groupRates = [];
            foreach ($byProvider as $providerCode => $matched) {
                $provider = $providerManager->getProvider((string)$providerCode);
                if ($provider === null) {
                    continue;
                }
                $config = $providerManager->getProviderConfig((string)$providerCode);
                $availability = $provider->checkAvailability(new \Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityRequest(
                    $destAddress,
                    $context ?? [],
                    $config,
                    $currency,
                ));
                if (!$availability->available) {
                    continue;
                }
                $addons = [];
                if (isset($address['addons']) && is_array($address['addons'])) {
                    $addons = $address['addons'];
                } elseif (isset($context['addons']) && is_array($context['addons'])) {
                    $addons = $context['addons'];
                }
                $quoteResult = $provider->quote(new \Weline\Shipping\Api\Data\Shipping\ShippingQuoteRequest(
                    $destAddress,
                    $group['lines'],
                    $currency,
                    $currencyPrecision,
                    $matched,
                    $context,
                    $originShippingAddressId,
                    $freeShippingSubtotalMinor,
                    $config,
                    $addons,
                ));
                if ($quoteResult->status !== \Weline\Shipping\Api\Data\Shipping\ShippingQuoteResult::STATUS_OK) {
                    continue;
                }
                foreach ($quoteResult->rates as $code => $rate) {
                    $rate['provider_code'] = (string)$providerCode;
                    $rate['service_code'] = (string)$code;
                    $groupRates[(string)$code] = $rate;
                }
                foreach ($quoteResult->fxSkipped as $skipped) {
                    $fxSkipped[] = $skipped;
                }
            }
            if ($groupRates === []) {
                throw new \RuntimeException('shipping_profile_conflict');
            }
            $perProfileRates[] = $groupRates;
        }

        $rates = $this->mergeProfileGroupRates($perProfileRates);
        ksort($rates);
        $this->lastQuoteDiagnostics = [
            'country_code' => $destAddress['country_code'],
            'currency' => $currency,
            'lane_count' => \count($rates),
            'fx_skipped' => array_values(array_unique($fxSkipped)),
            'profile_groups' => count($perProfileRates),
        ];

        return $rates;
    }

    /**
     * @param list<array<string, array<string, mixed>>> $perProfileRates
     * @return array<string, array<string, mixed>>
     */
    private function mergeProfileGroupRates(array $perProfileRates): array
    {
        if ($perProfileRates === []) {
            return [];
        }
        if (count($perProfileRates) === 1) {
            return $perProfileRates[0];
        }

        /** @var array<string, list<array{code:string,rate:array<string,mixed>}>> $byKey */
        $byKey = [];
        foreach ($perProfileRates as $groupRates) {
            $seenKeys = [];
            foreach ($groupRates as $code => $rate) {
                $provider = strtolower(trim((string)($rate['provider_code'] ?? 'local')));
                $label = mb_strtolower(trim((string)($rate['label'] ?? $code)));
                $key = $provider . "\0" . $label;
                $byKey[$key][] = ['code' => (string)$code, 'rate' => $rate];
                $seenKeys[$key] = true;
            }
            // Mark keys missing in this group by not adding — intersection later
            foreach (array_keys($byKey) as $key) {
                if (!isset($seenKeys[$key])) {
                    // leave gap; intersection check uses count === group count
                }
            }
        }

        $merged = [];
        $groupCount = count($perProfileRates);
        foreach ($byKey as $key => $entries) {
            if (count($entries) !== $groupCount) {
                continue;
            }
            $amount = 0;
            $label = (string)($entries[0]['rate']['label'] ?? $entries[0]['code']);
            $provider = (string)($entries[0]['rate']['provider_code'] ?? 'local');
            $parts = [];
            foreach ($entries as $entry) {
                $amount += max(0, (int)($entry['rate']['amount_minor'] ?? 0));
                $parts[] = [
                    'service_code' => $entry['code'],
                    'amount_minor' => (int)($entry['rate']['amount_minor'] ?? 0),
                ];
            }
            $mergeCode = 'merged:' . sha1($key);
            $merged[$mergeCode] = [
                'amount_minor' => $amount,
                'label' => $label,
                'currencies' => $entries[0]['rate']['currencies'] ?? [],
                'provider_code' => $provider,
                'merge' => 'named',
                'parts' => $parts,
            ];
        }

        if ($merged !== []) {
            return $merged;
        }

        // Synthetic: sum of each group's cheapest rate
        $amount = 0;
        $parts = [];
        $label = (string)__('合并运费');
        foreach ($perProfileRates as $groupRates) {
            $bestCode = null;
            $bestAmount = null;
            $bestLabel = '';
            foreach ($groupRates as $code => $rate) {
                $a = max(0, (int)($rate['amount_minor'] ?? 0));
                if ($bestAmount === null || $a < $bestAmount) {
                    $bestAmount = $a;
                    $bestCode = (string)$code;
                    $bestLabel = (string)($rate['label'] ?? $code);
                }
            }
            if ($bestAmount === null || $bestCode === null) {
                throw new \RuntimeException('shipping_profile_conflict');
            }
            $amount += $bestAmount;
            $parts[] = [
                'service_code' => $bestCode,
                'label' => $bestLabel,
                'amount_minor' => $bestAmount,
            ];
        }

        return [
            'merged:synthetic_min_sum' => [
                'amount_minor' => $amount,
                'label' => $label,
                'currencies' => [],
                'merge' => 'synthetic_min_sum',
                'parts' => $parts,
            ],
        ];
    }

    /**
     * Convert shipping amount minors between currencies; null when FX unavailable.
     */
    private function convertShippingMinor(
        int $minor,
        string $fromCurrency,
        string $toCurrency,
        int $fromPrecision,
        int $toPrecision,
    ): ?int {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        if ($minor === 0 || $from === '' || $to === '' || $from === $to) {
            return max(0, $minor);
        }
        $fromPrecision = max(0, min(6, $fromPrecision));
        $toPrecision = max(0, min(6, $toPrecision));
        try {
            /** @var CurrencyRateService $rates */
            $rates = $this->objectManager->getInstance(CurrencyRateService::class);
            $scaleFrom = 10 ** $fromPrecision;
            $major = $minor / $scaleFrom;
            $convertedMajor = $rates->tryConvert($major, $from, $to);
            if ($convertedMajor === null) {
                return null;
            }

            return max(0, (int)round($convertedMajor * (10 ** $toPrecision)));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Collect preferred ShippingService codes from cart lines.
     * Empty set = no restriction (all address-reachable services).
     *
     * @param list<array<string,mixed>> $lines
     * @return array<string, true>
     */
    public function preferredShippingProfileCodes(array $lines): array
    {
        $preferred = [];
        foreach ($lines as $line) {
            if (!(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            $code = trim((string)($line['shipping_profile_code']
                ?? ($line['fulfillment_metadata']['shipping_profile_code'] ?? '')));
            if ($code !== '') {
                $preferred[$code] = true;
            }
        }

        return $preferred;
    }

    /**
     * Hash active service/template/rule + reachability facts for the nearest quote layer.
     *
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     */
    public function activeQuoteConfigVersion(?array $context = null): string
    {
        /** @var ShippingConfigScopeService $scopeSvc */
        $scopeSvc = $this->objectManager->getInstance(ShippingConfigScopeService::class);
        $layer = $scopeSvc->resolveNearestServiceLayer($context);
        $memoKey = (string)$layer['scope_type'] . ':' . (int)$layer['scope_id'];
        if (isset($this->quoteConfigVersionMemo[$memoKey])) {
            return $this->quoteConfigVersionMemo[$memoKey];
        }

        /** @var ShippingService $serviceModel */
        $serviceModel = $this->objectManager->getInstance(ShippingService::class, [], false);
        $query = $serviceModel->reset()
            ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingService::schema_fields_ID, 'ASC');
        $scopeSvc->applyScopeWhere(
            $query,
            $layer,
            ShippingService::schema_fields_SCOPE_TYPE,
            ShippingService::schema_fields_SCOPE_ID,
        );
        $services = $query->select()->fetch()->getItems();
        $facts = [];
        $serviceIds = [];
        foreach ($services as $service) {
            if (!$service instanceof ShippingService) {
                continue;
            }
            $serviceId = (int)$service->getId();
            if ($serviceId > 0) {
                $serviceIds[$serviceId] = true;
            }
            $templateId = (int)$service->getData(ShippingService::schema_fields_RATE_TEMPLATE_ID);
            $ruleId = (int)$service->getData(ShippingService::schema_fields_FREE_SHIPPING_RULE_ID);
            $template = $templateId > 0
                ? $this->objectManager->getInstance(RateTemplate::class, [], false)->load($templateId)
                : null;
            $rule = $ruleId > 0
                ? $this->objectManager->getInstance(FreeShippingRule::class, [], false)->load($ruleId)
                : null;
            $facts[] = [
                'service' => $this->pickFactFields((array)$service->getData(), [
                    ShippingService::schema_fields_ID,
                    ShippingService::schema_fields_SCOPE_TYPE,
                    ShippingService::schema_fields_SCOPE_ID,
                    ShippingService::schema_fields_SERVICE_CODE,
                    ShippingService::schema_fields_SERVICE_NAME,
                    ShippingService::schema_fields_CARRIER_ID,
                    ShippingService::schema_fields_RATE_TEMPLATE_ID,
                    ShippingService::schema_fields_FREE_SHIPPING_RULE_ID,
                    ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID,
                    ShippingService::schema_fields_ESTIMATED_DAYS_MIN,
                    ShippingService::schema_fields_ESTIMATED_DAYS_MAX,
                    ShippingService::schema_fields_IS_FREE_SHIPPING,
                    ShippingService::schema_fields_IS_ACTIVE,
                    ShippingService::schema_fields_SORT_ORDER,
                    ShippingService::schema_fields_ALLOWED_POINT_TYPES,
                    ShippingService::schema_fields_ACCEPTED_HAZARD_CLASSES,
                ]),
                'template' => $template instanceof RateTemplate && $template->getId()
                    ? $this->pickFactFields((array)$template->getData(), [
                        RateTemplate::schema_fields_ID,
                        RateTemplate::schema_fields_SCOPE_TYPE,
                        RateTemplate::schema_fields_SCOPE_ID,
                        RateTemplate::schema_fields_TEMPLATE_CODE,
                        RateTemplate::schema_fields_CALCULATION_TYPE,
                        RateTemplate::schema_fields_BASE_FEE,
                        RateTemplate::schema_fields_WEIGHT_UNIT,
                        RateTemplate::schema_fields_WEIGHT_RATE,
                        RateTemplate::schema_fields_VOLUME_UNIT,
                        RateTemplate::schema_fields_VOLUME_RATE,
                        RateTemplate::schema_fields_QUANTITY_RATE,
                        RateTemplate::schema_fields_MIXED_CONFIG,
                        RateTemplate::schema_fields_RATE_BRACKETS,
                        RateTemplate::schema_fields_MAX_WEIGHT_KG,
                        RateTemplate::schema_fields_CURRENCY_CODE,
                        RateTemplate::schema_fields_IS_ACTIVE,
                    ])
                    : null,
                'free_rule' => $rule instanceof FreeShippingRule && $rule->getId()
                    ? $this->pickFactFields((array)$rule->getData(), [
                        FreeShippingRule::schema_fields_ID,
                        FreeShippingRule::schema_fields_SCOPE_TYPE,
                        FreeShippingRule::schema_fields_SCOPE_ID,
                        FreeShippingRule::schema_fields_RULE_CODE,
                        FreeShippingRule::schema_fields_CONDITION_TYPE,
                        FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT,
                        FreeShippingRule::schema_fields_MEMBER_LEVEL_IDS,
                        FreeShippingRule::schema_fields_REGION_IDS,
                        FreeShippingRule::schema_fields_COUPON_CODES,
                        FreeShippingRule::schema_fields_MIXED_CONFIG,
                        FreeShippingRule::schema_fields_IS_ACTIVE,
                        FreeShippingRule::schema_fields_PRIORITY,
                    ])
                    : null,
            ];
        }

        $version = hash(
            'sha256',
            json_encode([
                'layer' => [
                    'scope_type' => (string)$layer['scope_type'],
                    'scope_id' => (int)$layer['scope_id'],
                ],
                'services' => $facts,
                'surcharges' => $this->surchargeConfigFacts($layer),
                'packing' => $this->packingConfigFacts($layer),
                'seasonal' => $this->seasonalConfigFacts($layer),
                'checkout_addons' => $this->checkoutAddonConfigFacts($layer),
                'commerce' => $this->commerceConfigFacts($layer),
                'reachability' => $this->reachabilityConfigFacts($context, array_keys($serviceIds)),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );

        return $this->quoteConfigVersionMemo[$memoKey] = $version;
    }

    /**
     * @param array{scope_type:string,scope_id:int} $layer
     * @return list<array<string, mixed>>
     */
    private function surchargeConfigFacts(array $layer): array
    {
        $facts = [];
        try {
            /** @var \Weline\Shipping\Model\ShippingSurchargeRule $model */
            $model = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingSurchargeRule::class,
                [],
                false,
            );
            $items = $model->reset()
                ->where(
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_TYPE,
                    (string)$layer['scope_type'],
                )
                ->where(
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_ID,
                    (int)$layer['scope_id'],
                )
                ->where(\Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_IS_ACTIVE, 1)
                ->order(\Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach (is_array($items) ? $items : [] as $item) {
                if (!$item instanceof \Weline\Shipping\Model\ShippingSurchargeRule) {
                    continue;
                }
                $facts[] = $this->pickFactFields((array)$item->getData(), [
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_ID,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_TYPE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_ID,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_RULE_CODE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_MATCH_TYPE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_COUNTRY_CODE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_MATCH_VALUE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_AMOUNT_TYPE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_AMOUNT_VALUE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_IS_ACTIVE,
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_PRIORITY,
                ]);
            }
        } catch (\Throwable) {
        }

        return $facts;
    }

    /**
     * @param array{scope_type:string,scope_id:int} $layer
     * @return list<array<string, mixed>>
     */
    private function packingConfigFacts(array $layer): array
    {
        $facts = [];
        try {
            /** @var \Weline\Shipping\Model\ShippingPackingPolicy $model */
            $model = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingPackingPolicy::class,
                [],
                false,
            );
            $items = $model->reset()
                ->where(
                    \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_SCOPE_TYPE,
                    (string)$layer['scope_type'],
                )
                ->where(
                    \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_SCOPE_ID,
                    (int)$layer['scope_id'],
                )
                ->where(\Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_IS_ACTIVE, 1)
                ->order(\Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach (is_array($items) ? $items : [] as $item) {
                if (!$item instanceof \Weline\Shipping\Model\ShippingPackingPolicy) {
                    continue;
                }
                $facts[] = $this->pickFactFields((array)$item->getData(), [
                    \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_ID,
                    \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_POLICY_CODE,
                    \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_MAX_WEIGHT_KG,
                    \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_MAX_VOLUME_CM3,
                    \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_IS_ACTIVE,
                ]);
            }
        } catch (\Throwable) {
        }

        return $facts;
    }

    /**
     * @param array{scope_type:string,scope_id:int} $layer
     * @return list<array<string, mixed>>
     */
    private function seasonalConfigFacts(array $layer): array
    {
        $facts = [];
        try {
            /** @var \Weline\Shipping\Model\ShippingSeasonalRule $model */
            $model = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingSeasonalRule::class,
                [],
                false,
            );
            $items = $model->reset()
                ->where(
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_SCOPE_TYPE,
                    (string)$layer['scope_type'],
                )
                ->where(
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_SCOPE_ID,
                    (int)$layer['scope_id'],
                )
                ->order(\Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach (is_array($items) ? $items : [] as $item) {
                if (!$item instanceof \Weline\Shipping\Model\ShippingSeasonalRule) {
                    continue;
                }
                $facts[] = $this->pickFactFields((array)$item->getData(), [
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_ID,
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_RULE_CODE,
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_START_DATE,
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_END_DATE,
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_AMOUNT_TYPE,
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_AMOUNT_VALUE,
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_IS_ACTIVE,
                ]);
            }
        } catch (\Throwable) {
        }

        return $facts;
    }

    /**
     * @param array{scope_type:string,scope_id:int} $layer
     * @return list<array<string, mixed>>
     */
    private function checkoutAddonConfigFacts(array $layer): array
    {
        $facts = [];
        try {
            /** @var \Weline\Shipping\Model\ShippingCheckoutAddon $model */
            $model = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingCheckoutAddon::class,
                [],
                false,
            );
            $items = $model->reset()
                ->where(
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_SCOPE_TYPE,
                    (string)$layer['scope_type'],
                )
                ->where(
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_SCOPE_ID,
                    (int)$layer['scope_id'],
                )
                ->order(\Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach (is_array($items) ? $items : [] as $item) {
                if (!$item instanceof \Weline\Shipping\Model\ShippingCheckoutAddon) {
                    continue;
                }
                $facts[] = $this->pickFactFields((array)$item->getData(), [
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ID,
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ADDON_CODE,
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ADDON_TYPE,
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_AMOUNT_TYPE,
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_AMOUNT_VALUE,
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_IS_ACTIVE,
                ]);
            }
        } catch (\Throwable) {
        }

        return $facts;
    }

    /**
     * @param array{scope_type:string,scope_id:int} $layer
     * @return array<string, mixed>
     */
    private function commerceConfigFacts(array $layer): array
    {
        try {
            /** @var ShippingCommercePolicyService $commerce */
            $commerce = $this->objectManager->getInstance(ShippingCommercePolicyService::class);
            $policy = $commerce->resolve([
                'scope_type' => (string)$layer['scope_type'],
                'scope_id' => (int)$layer['scope_id'],
                'website_id' => (int)$layer['scope_id'],
            ]);
            $incoterms = [];
            /** @var ShippingService $svcModel */
            $svcModel = $this->objectManager->getInstance(ShippingService::class, [], false);
            $items = $svcModel->reset()
                ->where(ShippingService::schema_fields_SCOPE_TYPE, (string)$layer['scope_type'])
                ->where(ShippingService::schema_fields_SCOPE_ID, (int)$layer['scope_id'])
                ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();
            foreach (is_array($items) ? $items : [] as $item) {
                if (!$item instanceof ShippingService) {
                    continue;
                }
                $code = trim((string)$item->getData(ShippingService::schema_fields_SERVICE_CODE));
                if ($code === '') {
                    continue;
                }
                $incoterms[$code] = (string)$item->getData(ShippingService::schema_fields_INCOTERM);
            }

            return [
                'return_policy' => $policy['return_policy'],
                'split_shipment_shipping' => $policy['split_shipment_shipping'],
                'incoterms' => $incoterms,
            ];
        } catch (\Throwable) {
            return [
                'return_policy' => \Weline\Shipping\Model\ShippingCommercePolicy::RETURN_BUYER,
                'split_shipment_shipping' => \Weline\Shipping\Model\ShippingCommercePolicy::SPLIT_FIRST_ONLY,
                'incoterms' => [],
            ];
        }
    }

    /**
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @param list<int> $serviceIds
     * @return array<string, mixed>
     */
    private function reachabilityConfigFacts(?array $context, array $serviceIds): array
    {
        $facts = [
            'embargo' => [],
            'destination' => [],
            'carrier_coverage' => [],
            'service_lanes' => [],
        ];
        /** @var ShippingConfigScopeService $scopeSvc */
        $scopeSvc = $this->objectManager->getInstance(ShippingConfigScopeService::class);
        $scopePairs = [
            [EmbargoRegion::SCOPE_SYSTEM, 0],
        ];
        foreach ($scopeSvc->quoteLayerChain($context) as $layer) {
            $scopePairs[] = [(string)$layer['scope_type'], (int)$layer['scope_id']];
        }

        try {
            /** @var EmbargoRegion $embargo */
            $embargo = $this->objectManager->getInstance(EmbargoRegion::class, [], false);
            foreach ($scopePairs as [$type, $id]) {
                $items = $embargo->reset()
                    ->where(EmbargoRegion::schema_fields_SCOPE_TYPE, $type)
                    ->where(EmbargoRegion::schema_fields_SCOPE_ID, $id)
                    ->where(EmbargoRegion::schema_fields_IS_ACTIVE, 1)
                    ->order(EmbargoRegion::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetch()
                    ->getItems();
                foreach ($items as $item) {
                    if (!$item instanceof EmbargoRegion) {
                        continue;
                    }
                    $facts['embargo'][] = $this->pickFactFields((array)$item->getData(), [
                        EmbargoRegion::schema_fields_ID,
                        EmbargoRegion::schema_fields_SCOPE_TYPE,
                        EmbargoRegion::schema_fields_SCOPE_ID,
                        EmbargoRegion::schema_fields_REGION_TYPE,
                        EmbargoRegion::schema_fields_COUNTRY_CODE,
                        EmbargoRegion::schema_fields_REGION_ID,
                        EmbargoRegion::schema_fields_REGION_CODE,
                        EmbargoRegion::schema_fields_STREET_ID,
                        EmbargoRegion::schema_fields_REASON_CODE,
                        EmbargoRegion::schema_fields_ORIGIN,
                        EmbargoRegion::schema_fields_IS_ACTIVE,
                    ]);
                }
            }
        } catch (\Throwable) {
        }

        try {
            /** @var DestinationRegion $dest */
            $dest = $this->objectManager->getInstance(DestinationRegion::class, [], false);
            foreach ($scopeSvc->quoteLayerChain($context) as $layer) {
                $items = $dest->reset()
                    ->where(DestinationRegion::schema_fields_SCOPE_TYPE, (string)$layer['scope_type'])
                    ->where(DestinationRegion::schema_fields_SCOPE_ID, (int)$layer['scope_id'])
                    ->where(DestinationRegion::schema_fields_IS_ACTIVE, 1)
                    ->order(DestinationRegion::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetch()
                    ->getItems();
                foreach ($items as $item) {
                    if (!$item instanceof DestinationRegion) {
                        continue;
                    }
                    $facts['destination'][] = $this->pickFactFields((array)$item->getData(), [
                        DestinationRegion::schema_fields_ID,
                        DestinationRegion::schema_fields_SCOPE_TYPE,
                        DestinationRegion::schema_fields_SCOPE_ID,
                        DestinationRegion::schema_fields_REGION_TYPE,
                        DestinationRegion::schema_fields_COUNTRY_CODE,
                        DestinationRegion::schema_fields_REGION_ID,
                        DestinationRegion::schema_fields_REGION_CODE,
                        DestinationRegion::schema_fields_STREET_ID,
                        DestinationRegion::schema_fields_IS_ACTIVE,
                    ]);
                }
            }
        } catch (\Throwable) {
        }

        try {
            /** @var CarrierRegion $cover */
            $cover = $this->objectManager->getInstance(CarrierRegion::class, [], false);
            $items = $cover->reset()
                ->where(CarrierRegion::schema_fields_IS_ACTIVE, 1)
                ->order(CarrierRegion::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if (!$item instanceof CarrierRegion) {
                    continue;
                }
                $facts['carrier_coverage'][] = $this->pickFactFields((array)$item->getData(), [
                    CarrierRegion::schema_fields_ID,
                    CarrierRegion::schema_fields_CARRIER_ID,
                    CarrierRegion::schema_fields_REGION_TYPE,
                    CarrierRegion::schema_fields_COUNTRY_CODE,
                    CarrierRegion::schema_fields_REGION_ID,
                    CarrierRegion::schema_fields_REGION_CODE,
                    CarrierRegion::schema_fields_STREET_ID,
                    CarrierRegion::schema_fields_IS_ACTIVE,
                ]);
            }
        } catch (\Throwable) {
        }

        if ($serviceIds !== []) {
            try {
                /** @var ServiceRegion $lane */
                $lane = $this->objectManager->getInstance(ServiceRegion::class, [], false);
                $wanted = [];
                foreach ($serviceIds as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) {
                        $wanted[$sid] = true;
                    }
                }
                $items = $lane->reset()
                    ->where(ServiceRegion::schema_fields_IS_ACTIVE, 1)
                    ->order(ServiceRegion::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetch()
                    ->getItems();
                foreach ($items as $item) {
                    if (!$item instanceof ServiceRegion) {
                        continue;
                    }
                    $sid = (int)$item->getData(ServiceRegion::schema_fields_SERVICE_ID);
                    if (!isset($wanted[$sid])) {
                        continue;
                    }
                    $facts['service_lanes'][] = $this->pickFactFields((array)$item->getData(), [
                        ServiceRegion::schema_fields_ID,
                        ServiceRegion::schema_fields_SERVICE_ID,
                        ServiceRegion::schema_fields_REGION_TYPE,
                        ServiceRegion::schema_fields_COUNTRY_CODE,
                        ServiceRegion::schema_fields_REGION_ID,
                        ServiceRegion::schema_fields_REGION_CODE,
                        ServiceRegion::schema_fields_IS_ACTIVE,
                    ]);
                }
            } catch (\Throwable) {
            }
        }

        return $facts;
    }

    /**
     * @param array<string|int, mixed> $data
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function pickFactFields(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $this->canonical($out);
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    private function subtotalMinor(array $lines): int
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            $row = array_key_exists('row_total_minor', $line)
                ? (int)$line['row_total_minor']
                : (int)($line['qty_minor'] ?? 0) * (int)($line['unit_price_minor'] ?? 0);
            if ($row < 0 || $subtotal > PHP_INT_MAX - $row) {
                throw new \OverflowException(__('配送报价小计溢出'));
            }
            $subtotal += $row;
        }

        return $subtotal;
    }

    /**
     * @param array<string, mixed> $destAddress
     */
    private function freeReason(
        ShippingService $service,
        int $subtotalMinor,
        int $currencyPrecision,
        array $destAddress = [],
    ): ?string {
        if ((bool)$service->getData(ShippingService::schema_fields_IS_FREE_SHIPPING)) {
            return 'service_free_shipping';
        }
        $ruleId = (int)$service->getData(ShippingService::schema_fields_FREE_SHIPPING_RULE_ID);
        if ($ruleId <= 0) {
            return null;
        }
        /** @var FreeShippingRule $rule */
        $rule = $this->objectManager->getInstance(FreeShippingRule::class, [], false)->load($ruleId);
        if (!$rule->getId() || !(bool)$rule->getData(FreeShippingRule::schema_fields_IS_ACTIVE)) {
            return null;
        }
        $type = (string)$rule->getData(FreeShippingRule::schema_fields_CONDITION_TYPE);
        $amountOk = true;
        if (
            $type === FreeShippingRule::CONDITION_ORDER_AMOUNT
            || $type === FreeShippingRule::CONDITION_MIXED
        ) {
            $minimumMinor = $this->decimalToMinor(
                (string)$rule->getData(FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT),
                $currencyPrecision,
            );
            $amountOk = $subtotalMinor >= $minimumMinor;
        }
        $regionOk = true;
        if (
            $type === FreeShippingRule::CONDITION_REGION
            || $type === FreeShippingRule::CONDITION_MIXED
        ) {
            $regionOk = $this->destMatchesFreeShippingRegions($rule, $destAddress);
        }
        if ($type === FreeShippingRule::CONDITION_ORDER_AMOUNT) {
            return $amountOk ? 'free_shipping_rule' : null;
        }
        if ($type === FreeShippingRule::CONDITION_REGION) {
            return $regionOk ? 'free_shipping_rule' : null;
        }
        if ($type === FreeShippingRule::CONDITION_MIXED) {
            return ($amountOk && $regionOk) ? 'free_shipping_rule' : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $destAddress
     */
    private function destMatchesFreeShippingRegions(FreeShippingRule $rule, array $destAddress): bool
    {
        $regionIds = $rule->getRegionIds();
        if ($regionIds === []) {
            return true;
        }
        $wanted = [];
        foreach ($regionIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $wanted[$id] = true;
            }
        }
        if ($wanted === []) {
            return true;
        }
        try {
            $resolved = $this->objectManager->getInstance(CoverageRuleMatcher::class)
                ->resolveAddressLevels($destAddress);
        } catch (\Throwable) {
            $resolved = ['chain' => []];
        }
        foreach ($resolved['chain'] ?? [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $rid = (int)($node['region_id'] ?? 0);
            if ($rid > 0 && isset($wanted[$rid])) {
                return true;
            }
        }
        foreach (['province_region_id', 'city_region_id', 'district_region_id'] as $key) {
            $rid = (int)($destAddress[$key] ?? 0);
            if ($rid > 0 && isset($wanted[$rid])) {
                return true;
            }
        }

        return false;
    }

    private function decimalToMinor(string $decimal, int $precision): int
    {
        $decimal = trim($decimal);
        if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $decimal, $match)) {
            throw new \InvalidArgumentException(__('免邮金额格式非法'));
        }
        $scale = 10 ** $precision;
        $whole = (int)$match[1];
        if ($whole !== 0 && $scale > intdiv(PHP_INT_MAX, $whole)) {
            throw new \OverflowException(__('免邮金额溢出'));
        }
        $fraction = str_pad((string)($match[2] ?? ''), $precision + 1, '0');
        $minor = $whole * $scale + ($precision > 0 ? (int)substr($fraction, 0, $precision) : 0);
        if ((int)$fraction[$precision] >= 5) {
            $minor++;
        }

        return $minor;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }

        return $data;
    }
}
