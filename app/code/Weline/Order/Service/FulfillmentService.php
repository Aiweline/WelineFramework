<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\OrderShipment;

/**
 * 发货服务
 * 
 * @package Weline_Order
 */
class FulfillmentService
{
    private ObjectManager $objectManager;
    private EventsManager $eventsManager;
    private OrderService $orderService;
    
    public function __construct(
        ObjectManager $objectManager,
        EventsManager $eventsManager,
        OrderService $orderService
    ) {
        $this->objectManager = $objectManager;
        $this->eventsManager = $eventsManager;
        $this->orderService = $orderService;
    }
    
    /**
     * 获取发货模型实例
     * 
     * @return OrderShipment
     */
    private function getShipmentModel(): OrderShipment
    {
        return $this->objectManager->getInstance(OrderShipment::class);
    }
    
    /**
     * 创建发货记录
     * 
     * @param int $orderId 订单ID
     * @param array $shipmentData 发货数据
     * @return OrderShipment
     * @throws \Exception
     */
    public function createShipment(int $orderId, array $shipmentData): OrderShipment
    {
        $order = $this->orderService->getOrder($orderId);
        
        // 验证订单是否可以发货
        if ($order->getData(Order::schema_fields_STATUS) !== Order::STATUS_PAID) {
            throw new \Exception(__('只有已支付的订单才能发货'));
        }
        
        // 创建发货记录
        $shipment = $this->getShipmentModel()->reset();
        $shipment->setData(OrderShipment::schema_fields_ORDER_ID, $orderId);
        $shipment->setData(OrderShipment::schema_fields_TRACKING_NUMBER, $shipmentData['tracking_number'] ?? '');
        $shipment->setData(OrderShipment::schema_fields_CARRIER, $shipmentData['carrier'] ?? '');
        $shipment->setData(OrderShipment::schema_fields_STATUS, OrderShipment::STATUS_SHIPPED);
        $shipment->setData(OrderShipment::schema_fields_SHIPPED_AT, date('Y-m-d H:i:s'));
        $shipment->save();
        
        // 更新订单发货状态
        $order->setData(Order::schema_fields_FULFILLMENT_STATUS, Order::FULFILLMENT_STATUS_SHIPPED);
        $order->save();
        
        // 使用状态机转换订单状态
        $stateMachine = $this->objectManager->getInstance(OrderStateMachine::class);
        try {
            $stateMachine->transition($orderId, Order::STATUS_FULFILLED, __('订单已发货'));
        } catch (\Exception $e) {
            // 如果状态转换失败，不影响发货记录
        }
        
        // 触发订单发货事件
        $this->eventsManager->dispatch('Weline_Order::order_shipped', [
            'order' => $order,
            'order_id' => $orderId,
            'shipment' => $shipment,
        ]);
        
        return $shipment;
    }
    
    /**
     * 更新物流单号
     * 
     * @param int $shipmentId 发货ID
     * @param string $trackingNumber 物流单号
     * @return OrderShipment
     * @throws \Exception
     */
    public function updateTracking(int $shipmentId, string $trackingNumber): OrderShipment
    {
        $shipment = $this->getShipmentModel()->reset()->load($shipmentId);
        
        if (!$shipment->getId()) {
            throw new \Exception(__('发货记录不存在'));
        }
        
        $shipment->setData(OrderShipment::schema_fields_TRACKING_NUMBER, $trackingNumber);
        $shipment->save();
        
        return $shipment;
    }
    
    /**
     * 标记为已送达
     * 
     * @param int $shipmentId 发货ID
     * @return OrderShipment
     * @throws \Exception
     */
    public function markAsDelivered(int $shipmentId): OrderShipment
    {
        $shipment = $this->getShipmentModel()->reset()->load($shipmentId);
        
        if (!$shipment->getId()) {
            throw new \Exception(__('发货记录不存在'));
        }
        
        $shipment->setData(OrderShipment::schema_fields_STATUS, OrderShipment::STATUS_DELIVERED);
        $shipment->setData(OrderShipment::schema_fields_DELIVERED_AT, date('Y-m-d H:i:s'));
        $shipment->save();
        
        // 更新订单状态为已完成
        $orderId = (int)$shipment->getData(OrderShipment::schema_fields_ORDER_ID);
        $order = $this->orderService->getOrder($orderId);
        
        $order->setData(Order::schema_fields_FULFILLMENT_STATUS, Order::FULFILLMENT_STATUS_DELIVERED);
        $order->save();
        
        // 使用状态机转换订单状态
        $stateMachine = $this->objectManager->getInstance(OrderStateMachine::class);
        try {
            $stateMachine->transition($orderId, Order::STATUS_COMPLETED, __('订单已完成'));
            
            // 触发订单完成事件
            $this->eventsManager->dispatch('Weline_Order::order_completed', [
                'order' => $order,
                'order_id' => $orderId,
                'shipment' => $shipment,
            ]);
        } catch (\Exception $e) {
            // 如果状态转换失败，不影响发货记录
        }
        
        return $shipment;
    }
    
    /**
     * 获取发货记录列表
     * 
     * @param int $orderId 订单ID
     * @return array
     */
    public function getShipments(int $orderId): array
    {
        $collection = $this->getShipmentModel()->reset()
            ->where(OrderShipment::schema_fields_ORDER_ID, $orderId)
            ->order(OrderShipment::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();
        
        return $collection->getItems();
    }
}

