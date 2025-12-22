<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\FulfillmentService;

/**
 * 发货管理控制器
 */
#[Acl('Weline_Order::shipment_manage', '发货管理', 'mdi-truck', '发货管理', 'Weline_Order::order_manage')]
class Shipment extends BackendController
{
    private FulfillmentService $fulfillmentService;
    
    public function __construct(ObjectManager $objectManager)
    {
        $this->fulfillmentService = $objectManager->getInstance(FulfillmentService::class);
    }
    
    /**
     * 创建发货记录
     */
    #[Acl('Weline_Order::shipment_create', '创建发货', 'mdi-package-variant', '创建发货记录')]
    public function create()
    {
        $orderId = (int)$this->request->getPost('order_id');
        $shipmentData = [
            'tracking_number' => trim((string)$this->request->getPost('tracking_number', '')),
            'carrier' => trim((string)$this->request->getPost('carrier', '')),
        ];
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            // 跳转到订单列表（显式后台路由）
            $this->redirect('order/backend/order/index');
            return;
        }
        
        try {
            $this->fulfillmentService->createShipment($orderId, $shipmentData);
            $this->getMessageManager()->addSuccess(__('发货记录创建成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        // 发货创建后返回订单详情
        $this->redirect('order/backend/order/view?id=' . $orderId);
    }
    
    /**
     * 更新物流单号
     */
    #[Acl('Weline_Order::shipment_update_tracking', '更新物流单号', 'mdi-update', '更新物流单号')]
    public function updateTracking()
    {
        $shipmentId = (int)$this->request->getPost('shipment_id');
        $trackingNumber = trim((string)$this->request->getPost('tracking_number'));
        
        if (!$shipmentId || !$trackingNumber) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('order/backend/order/index');
            return;
        }
        
        try {
            $shipment = $this->fulfillmentService->updateTracking($shipmentId, $trackingNumber);
            $orderId = (int)$shipment->getData(\Weline\Order\Model\OrderShipment::fields_ORDER_ID);
            $this->getMessageManager()->addSuccess(__('物流单号更新成功'));
            $this->redirect('order/backend/order/view?id=' . $orderId);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('order/backend/order/index');
        }
    }
    
    /**
     * 标记为已送达
     */
    #[Acl('Weline_Order::shipment_mark_delivered', '标记已送达', 'mdi-check-circle', '标记为已送达')]
    public function markDelivered()
    {
        $shipmentId = (int)$this->request->getParam('id');
        
        if (!$shipmentId) {
            $this->getMessageManager()->addError(__('发货ID不能为空'));
            $this->redirect('order/backend/order/index');
            return;
        }
        
        try {
            $shipment = $this->fulfillmentService->markAsDelivered($shipmentId);
            $orderId = (int)$shipment->getData(\Weline\Order\Model\OrderShipment::fields_ORDER_ID);
            $this->getMessageManager()->addSuccess(__('标记成功'));
            $this->redirect('order/backend/order/view?id=' . $orderId);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('order/backend/order/index');
        }
    }
}

