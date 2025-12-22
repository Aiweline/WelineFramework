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
 * 订单状态变更前观察者
 * 
 * 用于验证状态转换是否允许
 * 可以扩展业务规则验证（如：已发货的订单不能取消）
 */
class OrderStatusChangeBeforeObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        $oldStatus = $data['old_status'] ?? null;
        $newStatus = $data['new_status'] ?? null;
        
        if (!$order || !$oldStatus || !$newStatus) {
            return;
        }
        
        // 示例：已发货的订单不能取消
        if ($oldStatus === 'fulfilled' && $newStatus === 'cancelled') {
            $data['can_change'] = false;
            $event->setData($data);
            return;
        }
        
        // 示例：已完成的订单不能再变更状态
        if ($oldStatus === 'completed') {
            $data['can_change'] = false;
            $event->setData($data);
            return;
        }
        
        // 示例：已取消的订单不能再变更状态
        if ($oldStatus === 'cancelled' && $newStatus !== 'cancelled') {
            $data['can_change'] = false;
            $event->setData($data);
            return;
        }
        
        // 可以在这里添加更多业务规则验证
        // 其他模块也可以通过监听此事件来添加自定义验证规则
    }
}

