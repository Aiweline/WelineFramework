<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 订单退款观察者
 */
class OrderRefundedObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $refund = $data['refund'] ?? null;
        
        // 可以在这里添加订单退款后的处理逻辑
        // 例如：发送退款通知、更新财务记录等
    }
}

