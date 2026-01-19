<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingServiceManager;
use Weline\Shipping\Service\RegionService;
use Weline\Shipping\Model\ShippingService as ShippingServiceModel;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;

/**
 * 配送信息前端API控制器
 * 
 * 根据配送区域返回配送信息和价格规则
 */
class ShippingInfo extends FrontendRestController
{
    private ShippingServiceManager $serviceManager;
    private RegionService $regionService;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->serviceManager = $objectManager->getInstance(ShippingServiceManager::class);
        $this->regionService = $objectManager->getInstance(RegionService::class);
    }

    /**
     * 根据配送区域获取配送信息和价格规则
     * 
     * POST /shipping/rest/v1/frontend/shipping-info/get-by-location
     * Body: {
     *   "country_code": "CN",
     *   "province": "北京市",
     *   "city": "北京市",
     *   "district": "朝阳区"
     * }
     * 
     * @return array
     */
    public function getByLocation(): array
    {
        try {
            $body = $this->request->getBodyParams();
            $countryCode = $body['country_code'] ?? 'CN';
            $province = $body['province'] ?? '';
            $city = $body['city'] ?? '';
            $district = $body['district'] ?? '';
            
            if (empty($countryCode)) {
                return $this->error(__('国家代码不能为空'), 400);
            }
            
            // 获取可用配送服务
            $services = $this->serviceManager->getAvailableServices($countryCode, $province, $city, $district);
            
            // 获取配送区域信息
            $region = $this->regionService->findByLocation($countryCode, $province, $city, $district);
            
            // 构建配送信息数据
            $shippingInfo = [
                'location' => [
                    'country_code' => $countryCode,
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                ],
                'region' => $region ? [
                    'region_id' => $region->getId(),
                    'region_name' => $region->getData(Region::fields_REGION_NAME),
                    'region_code' => $region->getData(Region::fields_REGION_CODE),
                    'country_code' => $region->getData(Region::fields_COUNTRY_CODE),
                ] : null,
                'services' => [],
                'price_rules' => [],
            ];
            
            // 处理每个配送服务的价格规则
            foreach ($services as $service) {
                $serviceId = $service['service_id'] ?? $service['shipping_service_id'] ?? null;
                if (!$serviceId) {
                    continue;
                }
                
                    // 获取该服务的价格规则
                $priceRules = $this->getPriceRulesForService($serviceId);
                
                $shippingInfo['services'][] = [
                    'service_id' => $serviceId,
                    'service_name' => $service['service_name'] ?? '',
                    'service_code' => $service['service_code'] ?? '',
                    'carrier_id' => $service['carrier_id'] ?? null,
                    'estimated_days_min' => $service['estimated_days_min'] ?? null,
                    'estimated_days_max' => $service['estimated_days_max'] ?? null,
                    'is_free_shipping' => $service['is_free_shipping'] ?? false,
                    'price_rules' => $priceRules,
                ];
            }
            
            // 将价格规则也放到顶层，方便前端使用
            if (!empty($shippingInfo['services'])) {
                $shippingInfo['price_rules'] = [];
                foreach ($shippingInfo['services'] as $service) {
                    if (!empty($service['price_rules'])) {
                        $shippingInfo['price_rules'][$service['service_id']] = $service['price_rules'];
                    }
                }
            }
            
            return $this->success(__('获取配送信息成功'), $shippingInfo);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 获取服务的价格规则
     * 
     * @param int $serviceId
     * @return array
     */
    private function getPriceRulesForService(int $serviceId): array
    {
        try {
            /** @var ShippingServiceModel $serviceModel */
            $serviceModel = ObjectManager::getInstance(ShippingServiceModel::class);
            $service = $serviceModel->load($serviceId);
            
            if (!$service->getId()) {
                return [];
            }
            
            // 获取价格模板ID
            $templateId = $service->getData(ShippingServiceModel::fields_RATE_TEMPLATE_ID);
            if (!$templateId) {
                return [];
            }
            
            /** @var RateTemplate $templateModel */
            $templateModel = ObjectManager::getInstance(RateTemplate::class);
            $template = $templateModel->load($templateId);
            
            if (!$template->getId()) {
                return [];
            }
            
            // 构建价格规则
            return [
                'template_id' => $template->getId(),
                'template_name' => $template->getData(RateTemplate::fields_TEMPLATE_NAME),
                'calculation_type' => $template->getData(RateTemplate::fields_CALCULATION_TYPE),
                'base_fee' => (float)$template->getData(RateTemplate::fields_BASE_FEE),
                'weight_unit' => $template->getData(RateTemplate::fields_WEIGHT_UNIT),
                'weight_rate' => (float)$template->getData(RateTemplate::fields_WEIGHT_RATE),
                'volume_unit' => $template->getData(RateTemplate::fields_VOLUME_UNIT),
                'volume_rate' => (float)$template->getData(RateTemplate::fields_VOLUME_RATE),
                'quantity_rate' => (float)$template->getData(RateTemplate::fields_QUANTITY_RATE),
                'mixed_config' => $template->getData(RateTemplate::fields_MIXED_CONFIG),
                'currency_code' => $template->getData(RateTemplate::fields_CURRENCY_CODE) ?: 'CNY',
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
