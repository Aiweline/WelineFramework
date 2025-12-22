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
use Weline\Shipping\Service\TrackingService;

/**
 * 前端物流跟踪查询API
 * 
 * @package Weline_Shipping
 */
class Tracking extends FrontendController
{
    private TrackingService $trackingService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->trackingService = $objectManager->getInstance(TrackingService::class);
    }

    /**
     * 查询物流跟踪
     * 
     * @return string JSON响应
     */
    public function query(): string
    {
        $trackingNumber = $this->request->getParam('tracking_number', '');
        $carrierId = (int)$this->request->getParam('carrier_id', 0);
        $forceRefresh = (bool)$this->request->getParam('force_refresh', false);
        
        if (empty($trackingNumber)) {
            return $this->json(['success' => false, 'message' => __('物流单号不能为空')]);
        }
        
        if ($carrierId <= 0) {
            return $this->json(['success' => false, 'message' => __('快递公司ID不能为空')]);
        }
        
        try {
            $result = $this->trackingService->query($trackingNumber, $carrierId, $forceRefresh);
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 根据订单获取物流单号
     * 
     * @return string JSON响应
     */
    public function getTrackingNumber(): string
    {
        $orderId = (int)$this->request->getParam('order_id', 0);
        
        if ($orderId <= 0) {
            return $this->json(['success' => false, 'message' => __('订单ID不能为空')]);
        }
        
        // TODO: 从订单表获取物流单号和快递公司ID
        // 这里需要根据实际的订单模块实现
        
        return $this->json(['success' => false, 'message' => __('功能待实现')]);
    }
}

