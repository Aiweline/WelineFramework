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
        return $this->objectManager->getInstance(ShippingService::class);
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
}

