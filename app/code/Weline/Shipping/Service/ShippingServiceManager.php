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
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
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
    public function getAvailableServices(
        string $countryCode,
        ?string $province = null,
        ?string $city = null,
        ?string $district = null
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
            $services = $this->coverageMatch()->getAvailableServices(
                $countryCode,
                $province,
                $city,
                $district,
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
     * @return array<string,array{amount_minor:int,label:string,currencies:list<string>,free_reason?:string}>
     */
    public function quoteRates(
        array $address,
        array $lines,
        string $currency,
        int $currencyPrecision = 2,
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
     * Hash all active service/template/rule facts that can affect a quote.
     */
    public function activeQuoteConfigVersion(): string
    {
        $services = $this->getModel()->reset()
            ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingService::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        $facts = [];
        foreach ($services->getItems() as $service) {
            if (!$service instanceof ShippingService) {
                continue;
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
                'service' => $this->canonical((array)$service->getData()),
                'template' => $template instanceof RateTemplate && $template->getId()
                    ? $this->canonical((array)$template->getData())
                    : null,
                'free_rule' => $rule instanceof FreeShippingRule && $rule->getId()
                    ? $this->canonical((array)$rule->getData())
                    : null,
            ];
        }

        return hash(
            'sha256',
            json_encode([
                'services' => $facts,
                'reachability' => $this->reachabilityConfigFacts(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reachabilityConfigFacts(): array
    {
        $facts = [
            'embargo' => [],
            'destination' => [],
            'carrier_coverage' => [],
            'service_lanes' => [],
        ];
        try {
            /** @var \Weline\Shipping\Model\EmbargoRegion $embargo */
            $embargo = $this->objectManager->getInstance(\Weline\Shipping\Model\EmbargoRegion::class);
            $items = $embargo->reset()
                ->where(\Weline\Shipping\Model\EmbargoRegion::schema_fields_IS_ACTIVE, 1)
                ->order(\Weline\Shipping\Model\EmbargoRegion::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if (!$item instanceof \Weline\Shipping\Model\EmbargoRegion) {
                    continue;
                }
                $facts['embargo'][] = $this->canonical((array)$item->getData());
            }
        } catch (\Throwable) {
        }
        try {
            /** @var \Weline\Shipping\Model\DestinationRegion $dest */
            $dest = $this->objectManager->getInstance(\Weline\Shipping\Model\DestinationRegion::class);
            $items = $dest->reset()
                ->order(\Weline\Shipping\Model\DestinationRegion::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if (is_object($item) && method_exists($item, 'getData')) {
                    $facts['destination'][] = $this->canonical((array)$item->getData());
                }
            }
        } catch (\Throwable) {
        }
        try {
            /** @var \Weline\Shipping\Model\CarrierRegion $cover */
            $cover = $this->objectManager->getInstance(\Weline\Shipping\Model\CarrierRegion::class);
            $items = $cover->reset()
                ->order(\Weline\Shipping\Model\CarrierRegion::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if (is_object($item) && method_exists($item, 'getData')) {
                    $facts['carrier_coverage'][] = $this->canonical((array)$item->getData());
                }
            }
        } catch (\Throwable) {
        }
        try {
            /** @var \Weline\Shipping\Model\ServiceRegion $lane */
            $lane = $this->objectManager->getInstance(\Weline\Shipping\Model\ServiceRegion::class);
            $items = $lane->reset()
                ->order(\Weline\Shipping\Model\ServiceRegion::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if (is_object($item) && method_exists($item, 'getData')) {
                    $facts['service_lanes'][] = $this->canonical((array)$item->getData());
                }
            }
        } catch (\Throwable) {
        }

        return $facts;
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
