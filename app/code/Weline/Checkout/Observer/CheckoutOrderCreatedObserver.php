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
 * 订单创建后观察者示例
 * 
 * 此观察者展示了如何监听 Weline_Checkout::checkout::create_order::after 事件
 * 并在订单创建后执行相应的业务逻辑
 */
class CheckoutOrderCreatedObserver implements ObserverInterface
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
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        
        if (!$order || !$orderId) {
            return;
        }
        
        // 示例：记录订单创建日志
        w_log_info("订单创建成功 - 订单ID: {$orderId}, 订单号: {$order->getOrderNumber()}");
        
        // 示例：扣减库存（需要库存模块实现）
        // $items = $order->getItems();
        // foreach ($items as $item) {
        //     $this->deductInventory($item['product_id'], $item['quantity']);
        // }
        
        // 示例：发送订单创建通知（需要通知模块实现）
        // $this->sendOrderCreatedNotification($order);
        
        // 示例：如果订单需要立即支付，自动发起支付（需要支付模块实现）
        // if ($order->getPaymentMethod() === 'alipay') {
        //     $this->initiatePayment($order);
        // }
    }
}

