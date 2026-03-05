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
use Weline\Order\Model\OrderPayment;

/**
 * 支付服务
 * 
 * @package Weline_Order
 */
class PaymentService
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
     * 获取支付模型实例
     * 
     * @return OrderPayment
     */
    private function getPaymentModel(): OrderPayment
    {
        return $this->objectManager->getInstance(OrderPayment::class);
    }
    
    /**
     * 处理支付
     * 
     * @param int $orderId 订单ID
     * @param array $paymentData 支付数据
     * @return OrderPayment
     * @throws \Exception
     */
    public function processPayment(int $orderId, array $paymentData): OrderPayment
    {
        $order = $this->orderService->getOrder($orderId);
        
        // 验证支付数据
        if (empty($paymentData['amount'])) {
            throw new \Exception(__('支付金额不能为空'));
        }
        
        if (empty($paymentData['payment_method'])) {
            throw new \Exception(__('支付方式不能为空'));
        }
        
        $amount = (float)$paymentData['amount'];
        $grandTotal = (float)$order->getData(Order::schema_fields_GRAND_TOTAL);
        
        if ($amount > $grandTotal) {
            throw new \Exception(__('支付金额不能超过订单总额'));
        }
        
        // 创建支付记录
        $payment = $this->getPaymentModel()->reset();
        $payment->setData(OrderPayment::schema_fields_ORDER_ID, $orderId);
        $payment->setData(OrderPayment::schema_fields_PAYMENT_METHOD, $paymentData['payment_method']);
        $payment->setData(OrderPayment::schema_fields_AMOUNT, $amount);
        $payment->setData(OrderPayment::schema_fields_CURRENCY, $paymentData['currency'] ?? $order->getData(Order::schema_fields_CURRENCY));
        $payment->setData(OrderPayment::schema_fields_TRANSACTION_ID, $paymentData['transaction_id'] ?? '');
        $payment->setData(OrderPayment::schema_fields_STATUS, OrderPayment::STATUS_PAID);
        $payment->setData(OrderPayment::schema_fields_PAID_AT, date('Y-m-d H:i:s'));
        $payment->save();
        
        // 更新订单支付状态
        $paidAmount = $this->getPaidAmount($orderId);
        if ($paidAmount >= $grandTotal) {
            $order->setData(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PAID);
            $order->save();
            
            // 使用状态机转换订单状态
            $stateMachine = $this->objectManager->getInstance(OrderStateMachine::class);
            try {
                $stateMachine->transition($orderId, Order::STATUS_PAID, __('订单已支付'));
            } catch (\Exception $e) {
                // 如果状态转换失败，不影响支付记录
            }
            
            // 触发订单支付事件
            $this->eventsManager->dispatch('Weline_Order::order_paid', [
                'order' => $order,
                'order_id' => $orderId,
                'payment' => $payment,
            ]);
        } elseif ($paidAmount > 0) {
            $order->setData(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PARTIAL);
            $order->save();
        }
        
        return $payment;
    }
    
    /**
     * 退款支付
     * 
     * @param int $paymentId 支付ID
     * @param float $amount 退款金额
     * @return OrderPayment
     * @throws \Exception
     */
    public function refundPayment(int $paymentId, float $amount): OrderPayment
    {
        $payment = $this->getPaymentModel()->reset()->load($paymentId);
        
        if (!$payment->getId()) {
            throw new \Exception(__('支付记录不存在'));
        }
        
        if ($payment->getData(OrderPayment::schema_fields_STATUS) !== OrderPayment::STATUS_PAID) {
            throw new \Exception(__('只有已支付的记录才能退款'));
        }
        
        $paidAmount = (float)$payment->getData(OrderPayment::schema_fields_AMOUNT);
        if ($amount > $paidAmount) {
            throw new \Exception(__('退款金额不能超过支付金额'));
        }
        
        // 更新支付状态
        $payment->setData(OrderPayment::schema_fields_STATUS, OrderPayment::STATUS_REFUNDED);
        $payment->save();
        
        // 更新订单支付状态
        $orderId = (int)$payment->getData(OrderPayment::schema_fields_ORDER_ID);
        $order = $this->orderService->getOrder($orderId);
        
        $paidAmount = $this->getPaidAmount($orderId);
        $grandTotal = (float)$order->getData(Order::schema_fields_GRAND_TOTAL);
        
        if ($paidAmount <= 0) {
            $order->setData(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PENDING);
        } elseif ($paidAmount < $grandTotal) {
            $order->setData(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PARTIAL);
        }
        
        $order->save();
        
        return $payment;
    }
    
    /**
     * 获取支付历史
     * 
     * @param int $orderId 订单ID
     * @return array
     */
    public function getPaymentHistory(int $orderId): array
    {
        $collection = $this->getPaymentModel()->reset()
            ->where(OrderPayment::schema_fields_ORDER_ID, $orderId)
            ->order(OrderPayment::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();
        
        return $collection->getItems();
    }
    
    /**
     * 获取已支付金额
     * 
     * @param int $orderId 订单ID
     * @return float
     */
    private function getPaidAmount(int $orderId): float
    {
        $payments = $this->getPaymentModel()->reset()
            ->where(OrderPayment::schema_fields_ORDER_ID, $orderId)
            ->where(OrderPayment::schema_fields_STATUS, OrderPayment::STATUS_PAID)
            ->select()
            ->fetch()
            ->getItems();
        
        $total = 0;
        foreach ($payments as $payment) {
            $total += (float)$payment->getData(OrderPayment::schema_fields_AMOUNT);
        }
        
        return $total;
    }
}

