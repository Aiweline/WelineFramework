<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingServiceManager;

/**
 * 前端配送服务查询API
 * 
 * @package Weline_Shipping
 */
class ShippingService extends FrontendController
{
    private ShippingServiceManager $serviceManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->serviceManager = $objectManager->getInstance(ShippingServiceManager::class);
    }

    /**
     * 获取可用配送服务
     * 
     * @return string JSON响应
     */
    public function getAvailableServices(): string
    {
        $countryCode = $this->request->getParam('country_code', '');
        $province = $this->request->getParam('province');
        $city = $this->request->getParam('city');
        $district = $this->request->getParam('district');
        
        if (empty($countryCode)) {
            return $this->json(['success' => false, 'message' => __('国家代码不能为空')]);
        }
        
        try {
            $services = $this->serviceManager->getAvailableServices($countryCode, $province, $city, $district);
            return $this->json(['success' => true, 'data' => $services]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 计算配送费用
     * 
     * @return string JSON响应
     */
    public function calculateFee(): string
    {
        $serviceId = (int)$this->request->getParam('service_id', 0);
        $orderAmount = (float)$this->request->getParam('order_amount', 0);
        $weight = (float)$this->request->getParam('weight', 0);
        $volume = (float)$this->request->getParam('volume', 0);
        $quantity = (int)$this->request->getParam('quantity', 1);
        $memberLevelId = $this->request->getParam('member_level_id') ? (int)$this->request->getParam('member_level_id') : null;
        $regionId = $this->request->getParam('region_id') ? (int)$this->request->getParam('region_id') : null;
        $couponCode = $this->request->getParam('coupon_code');
        
        if ($serviceId <= 0) {
            return $this->json(['success' => false, 'message' => __('配送服务ID不能为空')]);
        }
        
        try {
            $result = $this->serviceManager->calculateShippingFee(
                $serviceId,
                $orderAmount,
                $weight,
                $volume,
                $quantity,
                $memberLevelId,
                $regionId,
                $couponCode
            );
            return $this->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

