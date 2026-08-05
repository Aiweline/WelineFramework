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
use Weline\Shipping\Model\Zone;

/**
 * 配送服务管理服务
 * 
 * @package Weline_Shipping
 */
class ShippingServiceManager
{
    private ObjectManager $objectManager;
    private ZoneService $zoneService;
    private RateCalculationService $rateCalculationService;
    private FreeShippingService $freeShippingService;

    public function __construct(
        ObjectManager $objectManager,
        ZoneService $zoneService,
        RateCalculationService $rateCalculationService,
        FreeShippingService $freeShippingService
    ) {
        $this->objectManager = $objectManager;
        $this->zoneService = $zoneService;
        $this->rateCalculationService = $rateCalculationService;
        $this->freeShippingService = $freeShippingService;
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
        // 匹配配送区域
        $zone = $this->zoneService->matchZoneByAddress($countryCode, $province, $city, $district);
        
        if (!$zone) {
            return [];
        }
        
        // 获取该区域的所有配送服务
        $services = $this->getModel()->reset()
            ->where(ShippingService::schema_fields_ZONE_ID, $zone->getId())
            ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingService::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();
        
        $result = [];
        foreach ($services->getItems() as $service) {
            $result[] = [
                'service_id' => $service->getId(),
                'service_name' => $service->getData(ShippingService::schema_fields_SERVICE_NAME),
                'service_code' => $service->getData(ShippingService::schema_fields_SERVICE_CODE),
                'carrier_id' => $service->getData(ShippingService::schema_fields_CARRIER_ID),
                'estimated_days_min' => $service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MIN),
                'estimated_days_max' => $service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MAX),
                'is_free_shipping' => $service->getData(ShippingService::schema_fields_IS_FREE_SHIPPING),
            ];
        }
        
        return $result;
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
        $services = $this->getAvailableServices(
            strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? 'CN'))) ?: 'CN',
            trim((string)($address['province'] ?? $address['region'] ?? '')),
            trim((string)($address['city'] ?? '')),
            trim((string)($address['district'] ?? '')),
        );
        $subtotalMinor = $this->subtotalMinor($lines);
        $rates = [];
        foreach ($services as $summary) {
            $serviceId = (int)($summary['service_id'] ?? 0);
            $serviceCode = trim((string)($summary['service_code'] ?? ''));
            if ($serviceId <= 0 || $serviceCode === '') {
                continue;
            }
            $service = $this->getModel()->load($serviceId);
            if (!$service->getId()
                || !(bool)$service->getData(ShippingService::schema_fields_IS_ACTIVE)
            ) {
                continue;
            }
            $freeReason = $this->freeReason($service, $subtotalMinor, $currencyPrecision);
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
            json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
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

    private function freeReason(
        ShippingService $service,
        int $subtotalMinor,
        int $currencyPrecision,
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
        if ((string)$rule->getData(FreeShippingRule::schema_fields_CONDITION_TYPE)
            !== FreeShippingRule::CONDITION_ORDER_AMOUNT
        ) {
            return null;
        }
        $minimumMinor = $this->decimalToMinor(
            (string)$rule->getData(FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT),
            $currencyPrecision,
        );

        return $subtotalMinor >= $minimumMinor ? 'free_shipping_rule' : null;
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
