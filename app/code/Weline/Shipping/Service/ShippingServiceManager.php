<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Service;

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
    ): array {
        // 唯一路径：承运商覆盖 ∩ 可售白名单（可空）∩ 非禁运，按承运商 sort_order。
        // 再按默认发货仓 + 航线目的地覆盖过滤（空覆盖=通配兼容旧配置）。
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

            return $this->laneMatch()->filterByOriginAndDest($services, $address);
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
    ): array {
        $currency = strtoupper(trim($currency));
        $destAddress = [
            'country_code' => strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? 'CN'))) ?: 'CN',
            'province' => trim((string)($address['province'] ?? $address['region'] ?? '')),
            'city' => trim((string)($address['city'] ?? '')),
            'district' => trim((string)($address['district'] ?? '')),
            'province_region_id' => (int)($address['province_region_id'] ?? $address['province_id'] ?? 0),
            'city_region_id' => (int)($address['city_region_id'] ?? $address['city_id'] ?? 0),
            'district_region_id' => (int)($address['district_region_id'] ?? $address['district_id'] ?? 0),
            'province_code' => trim((string)($address['province_code'] ?? '')),
            'city_code' => trim((string)($address['city_code'] ?? '')),
            'district_code' => trim((string)($address['district_code'] ?? '')),
        ];
        $services = $this->getAvailableServices(
            $destAddress['country_code'],
            $destAddress['province'],
            $destAddress['city'],
            $destAddress['district'],
            $context,
            $destAddress,
        );
        $subtotalMinor = $this->subtotalMinor($lines);
        $preferred = $this->preferredShippingProfileCodes($lines);
        $rates = [];
        foreach ($services as $summary) {
            $serviceId = (int)($summary['service_id'] ?? 0);
            $serviceCode = trim((string)($summary['service_code'] ?? ''));
            if ($serviceId <= 0 || $serviceCode === '') {
                continue;
            }
            if ($preferred !== [] && !isset($preferred[$serviceCode])) {
                continue;
            }
            $service = $this->getModel()->load($serviceId);
            if (!$service->getId()
                || !(bool)$service->getData(ShippingService::schema_fields_IS_ACTIVE)
            ) {
                continue;
            }
            $freeReason = $this->freeReason($service, $subtotalMinor, $currencyPrecision, $destAddress);
            if ($freeReason !== null) {
                $rates[$serviceCode] = [
                    'amount_minor' => 0,
                    'label' => (string)$service->getData(ShippingService::schema_fields_SERVICE_NAME),
                    'currencies' => [$currency],
                    'free_reason' => $freeReason,
                ];
                continue;
            }
            $templateId = (int)$service->getData(ShippingService::schema_fields_RATE_TEMPLATE_ID);
            if ($templateId <= 0) {
                continue;
            }
            /** @var RateTemplate $template */
            $template = $this->objectManager->getInstance(RateTemplate::class, [], false)->load($templateId);
            if (!$template->getId()
                || !(bool)$template->getData(RateTemplate::schema_fields_IS_ACTIVE)
            ) {
                continue;
            }
            $templateCurrency = strtoupper(trim((string)$template->getData(
                RateTemplate::schema_fields_CURRENCY_CODE,
            )));
            if ($templateCurrency === '' || $templateCurrency !== $currency) {
                continue;
            }
            $rates[$serviceCode] = [
                'amount_minor' => $this->rateCalculationService->calculateTemplateMinor(
                    $template,
                    $lines,
                    $currencyPrecision,
                ),
                'label' => (string)$service->getData(ShippingService::schema_fields_SERVICE_NAME),
                'currencies' => [$templateCurrency],
            ];
        }
        ksort($rates);

        return $rates;
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
                'reachability' => $this->reachabilityConfigFacts($context, array_keys($serviceIds)),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );

        return $this->quoteConfigVersionMemo[$memoKey] = $version;
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
