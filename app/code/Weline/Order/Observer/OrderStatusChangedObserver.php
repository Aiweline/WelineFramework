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
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\OrderHistory;

/**
 * 订单状态变更观察者
 * 
 * 处理订单状态变更后的逻辑：
 * - 记录订单历史
 * - 发送客户通知（如果需要）
 */
class OrderStatusChangedObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $oldStatus = $data['old_status'] ?? null;
        $newStatus = $data['new_status'] ?? null;
        $comment = $data['comment'] ?? null;
        $notifyCustomer = $data['notify_customer'] ?? false;
        
        if (!$orderId || !$newStatus) {
            return;
        }
        
        // 1. 记录订单历史
        $this->addHistory($orderId, $newStatus, $comment, $notifyCustomer);
        
        // 2. 发送客户通知（如果需要）
        if ($notifyCustomer && $order) {
            $this->notifyCustomer($order, $oldStatus, $newStatus, $comment);
        }
    }
    
    /**
     * 添加订单历史记录
     * 
     * @param int $orderId 订单ID
     * @param string $status 状态
     * @param string|null $comment 备注
     * @param bool $notifyCustomer 是否通知客户
     * @return void
     */
    private function addHistory(int $orderId, string $status, ?string $comment = null, bool $notifyCustomer = false): void
    {
        /** @var OrderHistory $history */
        $history = ObjectManager::getInstance(OrderHistory::class);
        $history->setData(OrderHistory::schema_fields_ORDER_ID, $orderId)
                ->setData(OrderHistory::schema_fields_STATUS, $status)
                ->setData(OrderHistory::schema_fields_COMMENT, $comment)
                ->setData(OrderHistory::schema_fields_IS_CUSTOMER_NOTIFIED, $notifyCustomer ? 1 : 0)
                ->save();
    }
    
    /**
     * 通知客户订单状态变更
     * 
     * @param mixed $order 订单对象
     * @param string|null $oldStatus 旧状态
     * @param string $newStatus 新状态
     * @param string|null $comment 备注
     * @return void
     */
    private function notifyCustomer($order, ?string $oldStatus, string $newStatus, ?string $comment = null): void
    {
        // 这里可以触发客户通知事件，让其他模块（如邮件、短信模块）处理通知
        // 例如：触发 'Weline_Order::order_status_notify_customer' 事件
        
        // 暂时留空，等待其他模块实现通知逻辑
        // 可以通过事件系统让其他模块监听并处理通知
    }
}

