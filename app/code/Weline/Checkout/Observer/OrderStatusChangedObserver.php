<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 订单状态变更观察者示例
 * 
 * 此观察者展示了如何监听 Weline_Checkout::order::status::change::after 事件
 * 并在订单状态变更后执行相应的业务逻辑
 */
class OrderStatusChangedObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $orderId = $data['order_id'] ?? null;
        $oldStatus = $data['old_status'] ?? null;
        $newStatus = $data['new_status'] ?? null;
        $order = $data['order'] ?? null;
        
        if (!$order || !$orderId || !$oldStatus || !$newStatus) {
            return;
        }
        
        // 示例：记录状态变更日志
        error_log("订单状态变更 - 订单ID: {$orderId}, {$oldStatus} -> {$newStatus}");
        
        // 示例：根据状态变化执行相应操作
        switch ($newStatus) {
            case 'processing':
                // 订单处理中时发送通知
                // $this->sendOrderProcessingNotification($order);
                break;
                
            case 'completed':
                // 订单完成时增加积分
                // $points = (int)($order->getTotalAmount() * 0.01);
                // $this->addUserPoints($order->getCustomerId(), $points);
                // $this->sendOrderCompleteNotification($order);
                break;
                
            case 'cancelled':
                // 订单取消时恢复库存
                // if ($oldStatus !== 'cancelled') {
                //     $items = $order->getItems();
                //     foreach ($items as $item) {
                //         $this->restoreInventory($item['product_id'], $item['quantity']);
                //     }
                //     if ($order->isPaid()) {
                //         $this->processRefund($order);
                //     }
                //     $this->sendOrderCancelledNotification($order);
                // }
                break;
        }
    }
}

