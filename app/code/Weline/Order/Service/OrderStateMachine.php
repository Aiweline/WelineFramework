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
use Weline\Order\Model\OrderHistory;

/**
 * 订单状态机服务
 * 
 * @package Weline_Order
 */
class OrderStateMachine
{
    private ObjectManager $objectManager;
    private EventsManager $eventsManager;
    
    /**
     * 状态转换规则
     */
    private array $transitions = [
        Order::STATUS_PENDING => [
            Order::STATUS_PROCESSING,
            Order::STATUS_CANCELLED,
        ],
        Order::STATUS_PROCESSING => [
            Order::STATUS_PAID,
            Order::STATUS_CANCELLED,
        ],
        Order::STATUS_PAID => [
            Order::STATUS_FULFILLED,
            Order::STATUS_REFUNDED,
        ],
        Order::STATUS_FULFILLED => [
            Order::STATUS_COMPLETED,
        ],
    ];
    
    public function __construct(ObjectManager $objectManager, EventsManager $eventsManager)
    {
        $this->objectManager = $objectManager;
        $this->eventsManager = $eventsManager;
    }
    
    /**
     * 检查状态转换是否允许
     * 
     * @param string $from 当前状态
     * @param string $to 目标状态
     * @return bool
     */
    public function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        
        // 基本规则检查
        $canTransition = false;
        if (isset($this->transitions[$from])) {
            $canTransition = in_array($to, $this->transitions[$from], true);
        }
        
        // 触发事件，允许观察者扩展规则
        $eventData = [
            'from_status' => $from,
            'to_status' => $to,
            'can_transition' => $canTransition,
            'transitions' => $this->transitions,
        ];
        $this->eventsManager->dispatch('Weline_Order::order_status_can_transition', $eventData);
        
        return $eventData['can_transition'] ?? $canTransition;
    }
    
    /**
     * 执行状态转换
     * 
     * @param int $orderId 订单ID
     * @param string $newStatus 新状态
     * @param string|null $comment 备注
     * @param bool $notifyCustomer 是否通知客户
     * @return Order
     * @throws \Exception
     */
    public function transition(int $orderId, string $newStatus, ?string $comment = null, bool $notifyCustomer = false): Order
    {
        /** @var Order $order */
        $order = $this->objectManager->getInstance(Order::class);
        $order = $order->reset()->load($orderId);
        
        if (!$order->getId()) {
            throw new \Exception(__('订单不存在'));
        }
        
        $currentStatus = $order->getData(Order::fields_STATUS);
        
        // 1. 检查基本转换规则
        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new \Exception(__('订单状态不能从 %{1} 转换到 %{2}', [$currentStatus, $newStatus]));
        }
        
        // 2. 触发变更前事件（允许观察者阻止转换）
        $eventData = [
            'order' => $order,
            'order_id' => $orderId,
            'old_status' => $currentStatus,
            'new_status' => $newStatus,
            'comment' => $comment,
            'notify_customer' => $notifyCustomer,
            'can_change' => true,
        ];
        $this->eventsManager->dispatch('Weline_Order::order_status_change_before', $eventData);
        
        // 3. 检查观察者是否阻止转换
        if (isset($eventData['can_change']) && !$eventData['can_change']) {
            throw new \Exception(__('状态转换被阻止'));
        }
        
        // 4. 执行状态更新
        $order->setData(Order::fields_STATUS, $newStatus);
        $order->setData(Order::fields_STATE, $newStatus);
        $order->save();
        
        // 5. 触发变更后事件（订单历史记录等逻辑由观察者处理）
        $this->eventsManager->dispatch('Weline_Order::order_status_changed', $eventData);
        
        return $order;
    }
    
    /**
     * 获取可用状态转换
     * 
     * @param string $currentStatus 当前状态
     * @return array
     */
    public function getAvailableTransitions(string $currentStatus): array
    {
        return $this->transitions[$currentStatus] ?? [];
    }
    
}

