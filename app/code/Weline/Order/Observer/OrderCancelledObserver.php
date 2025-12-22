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
 * 订单取消观察者
 */
class OrderCancelledObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $reason = $data['reason'] ?? '';
        
        // 可以在这里添加订单取消后的处理逻辑
        // 例如：释放库存、退款处理、发送取消通知等
    }
}

