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
use Weline\Order\Model\OrderRefund;

/**
 * 退款服务
 * 
 * @package Weline_Order
 */
class RefundService
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
     * 获取退款模型实例
     * 
     * @return OrderRefund
     */
    private function getRefundModel(): OrderRefund
    {
        return $this->objectManager->getInstance(OrderRefund::class);
    }
    
    /**
     * 创建退款
     * 
     * @param int $orderId 订单ID
     * @param array $refundData 退款数据
     * @return OrderRefund
     * @throws \Exception
     */
    public function createRefund(int $orderId, array $refundData): OrderRefund
    {
        $order = $this->orderService->getOrder($orderId);
        
        // 验证订单是否可以退款
        if (!$order->canRefund()) {
            throw new \Exception(__('订单当前状态不允许退款'));
        }
        
        // 验证退款金额
        if (empty($refundData['amount'])) {
            throw new \Exception(__('退款金额不能为空'));
        }
        
        $refundAmount = (float)$refundData['amount'];
        $grandTotal = (float)$order->getData(Order::schema_fields_GRAND_TOTAL);
        
        if ($refundAmount > $grandTotal) {
            throw new \Exception(__('退款金额不能超过订单总额'));
        }
        
        // 检查已退款金额
        $refundedAmount = $this->getRefundedAmount($orderId);
        if ($refundedAmount + $refundAmount > $grandTotal) {
            throw new \Exception(__('退款总额不能超过订单总额'));
        }
        
        // 创建退款记录
        $refund = $this->getRefundModel()->reset();
        $refund->setData(OrderRefund::schema_fields_ORDER_ID, $orderId);
        $refund->setData(OrderRefund::schema_fields_AMOUNT, $refundAmount);
        $refund->setData(OrderRefund::schema_fields_REASON, $refundData['reason'] ?? '');
        $refund->setData(OrderRefund::schema_fields_STATUS, OrderRefund::STATUS_PENDING);
        $refund->save();
        
        return $refund;
    }
    
    /**
     * 处理退款
     * 
     * @param int $refundId 退款ID
     * @return OrderRefund
     * @throws \Exception
     */
    public function processRefund(int $refundId): OrderRefund
    {
        $refund = $this->getRefundModel()->reset()->load($refundId);
        
        if (!$refund->getId()) {
            throw new \Exception(__('退款记录不存在'));
        }
        
        if ($refund->getData(OrderRefund::schema_fields_STATUS) !== OrderRefund::STATUS_PENDING) {
            throw new \Exception(__('只有待处理的退款才能处理'));
        }
        
        // 更新退款状态
        $refund->setData(OrderRefund::schema_fields_STATUS, OrderRefund::STATUS_REFUNDED);
        $refund->setData(OrderRefund::schema_fields_REFUNDED_AT, date('Y-m-d H:i:s'));
        $refund->save();
        
        // 更新订单状态
        $orderId = (int)$refund->getData(OrderRefund::schema_fields_ORDER_ID);
        $order = $this->orderService->getOrder($orderId);
        
        $refundedAmount = $this->getRefundedAmount($orderId);
        $grandTotal = (float)$order->getData(Order::schema_fields_GRAND_TOTAL);
        
        if ($refundedAmount >= $grandTotal) {
            // 使用状态机转换订单状态
            $stateMachine = $this->objectManager->getInstance(OrderStateMachine::class);
            try {
                $stateMachine->transition($orderId, Order::STATUS_REFUNDED, __('订单已退款'));
            } catch (\Exception $e) {
                // 如果状态转换失败，不影响退款记录
            }
            
            // 触发订单退款事件
            $this->eventsManager->dispatch('Weline_Order::order_refunded', [
                'order' => $order,
                'order_id' => $orderId,
                'refund' => $refund,
            ]);
        }
        
        return $refund;
    }
    
    /**
     * 获取退款历史
     * 
     * @param int $orderId 订单ID
     * @return array
     */
    public function getRefundHistory(int $orderId): array
    {
        $collection = $this->getRefundModel()->reset()
            ->where(OrderRefund::schema_fields_ORDER_ID, $orderId)
            ->order(OrderRefund::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();
        
        return $collection->getItems();
    }
    
    /**
     * 获取已退款金额
     * 
     * @param int $orderId 订单ID
     * @return float
     */
    private function getRefundedAmount(int $orderId): float
    {
        $refunds = $this->getRefundModel()->reset()
            ->where(OrderRefund::schema_fields_ORDER_ID, $orderId)
            ->where(OrderRefund::schema_fields_STATUS, OrderRefund::STATUS_REFUNDED)
            ->select()
            ->fetch()
            ->getItems();
        
        $total = 0;
        foreach ($refunds as $refund) {
            $total += (float)$refund->getData(OrderRefund::schema_fields_AMOUNT);
        }
        
        return $total;
    }
}

