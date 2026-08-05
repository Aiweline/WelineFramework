<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Service;

use Weline\Framework\Database\TransactionContext;
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
    public const ERROR_NOT_FOUND = 'order_state_order_not_found';
    public const ERROR_ILLEGAL_TRANSITION = 'order_state_transition_invalid';
    public const ERROR_BLOCKED = 'order_state_transition_blocked';
    public const ERROR_STALE = 'order_state_transition_conflict';

    private ObjectManager $objectManager;
    private EventsManager $eventsManager;

    /** @var (\Closure(string,array<string,mixed>):array<string,mixed>|null)|null */
    private ?\Closure $eventDispatcher;

    /** @var (\Closure(int,string,int,string):bool)|null */
    private ?\Closure $stateCompareAndSet;

    private ?Order $orderModel;
    
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
        // P2D-002：保持既有 Order 转换；CheckoutGroup 态见 CheckoutGroupInvariant
    ];
    
    public function __construct(
        ObjectManager $objectManager,
        EventsManager $eventsManager,
        ?Order $orderModel = null,
        ?\Closure $eventDispatcher = null,
        ?\Closure $stateCompareAndSet = null,
    ) {
        $this->objectManager = $objectManager;
        $this->eventsManager = $eventsManager;
        $this->orderModel = $orderModel;
        $this->eventDispatcher = $eventDispatcher;
        $this->stateCompareAndSet = $stateCompareAndSet;
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
        $this->dispatch('Weline_Order::order_status_can_transition', $eventData);
        
        return ($eventData['can_transition'] ?? $canTransition) === true;
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
        $transaction = $this->newOrder();
        $ownsTransaction = TransactionContext::transactionState(
            $transaction->getConnection()->getConnector(),
        ) === null;
        if ($ownsTransaction) {
            $transaction->beginTransaction();
        }

        try {
            $query = $this->newOrder()->where(Order::schema_fields_ID, $orderId);
            if (!$this->isSqlite($query)) {
                $query->additional('FOR UPDATE');
            }
            $order = $query->find()->fetch();
            if (!$order instanceof Order || !$order->getId()) {
                throw new OrderStateTransitionException(
                    self::ERROR_NOT_FOUND,
                    \__('订单不存在'),
                    ['order_id' => $orderId],
                );
            }

            $currentStatus = (string)$order->getData(Order::schema_fields_STATUS);
            if ($currentStatus === $newStatus) {
                if ($ownsTransaction) {
                    $transaction->commit();
                }
                return $order;
            }
            if (!$this->canTransition($currentStatus, $newStatus)) {
                throw new OrderStateTransitionException(
                    self::ERROR_ILLEGAL_TRANSITION,
                    \__('订单状态不能从 %{1} 转换到 %{2}', [$currentStatus, $newStatus]),
                    ['order_id' => $orderId, 'from' => $currentStatus, 'to' => $newStatus],
                );
            }

            $eventData = [
                'order' => $order,
                'order_id' => $orderId,
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
                'comment' => $comment,
                'notify_customer' => $notifyCustomer,
                'can_change' => true,
            ];
            $this->dispatch('Weline_Order::order_status_change_before', $eventData);
            if (($eventData['can_change'] ?? true) !== true) {
                throw new OrderStateTransitionException(
                    self::ERROR_BLOCKED,
                    \__('状态转换被阻止'),
                    ['order_id' => $orderId, 'from' => $currentStatus, 'to' => $newStatus],
                );
            }

            $expectedVersion = (int)$order->getData(Order::schema_fields_STATE_VERSION);
            if (!$this->compareAndSetState($orderId, $currentStatus, $expectedVersion, $newStatus)) {
                throw new OrderStateTransitionException(
                    self::ERROR_STALE,
                    \__('订单状态已被并发修改'),
                    [
                        'order_id' => $orderId,
                        'from' => $currentStatus,
                        'to' => $newStatus,
                        'expected_version' => $expectedVersion,
                    ],
                );
            }

            $order = $this->newOrder()->load($orderId);
            $eventData['order'] = $order;
            $this->dispatch('Weline_Order::order_status_changed', $eventData);
            if ($ownsTransaction) {
                $transaction->commit();
            }

            return $order;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $transaction->rollBack();
            }
            throw $exception;
        }
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

    private function newOrder(): Order
    {
        $this->orderModel ??= $this->objectManager->getInstance(Order::class);
        $order = clone $this->orderModel;

        return $order->clear();
    }

    /** @param array<string, mixed> $eventData */
    private function dispatch(string $eventName, array &$eventData): void
    {
        if ($this->eventDispatcher !== null) {
            $result = ($this->eventDispatcher)($eventName, $eventData);
            if (\is_array($result)) {
                $eventData = $result;
            }
            return;
        }
        $this->eventsManager->dispatch($eventName, $eventData);
    }

    private function isSqlite(Order $order): bool
    {
        return \strtolower((string)$order->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }

    private function compareAndSetState(
        int $orderId,
        string $currentStatus,
        int $expectedVersion,
        string $newStatus,
    ): bool {
        if ($this->stateCompareAndSet !== null) {
            return ($this->stateCompareAndSet)($orderId, $currentStatus, $expectedVersion, $newStatus);
        }

        $writer = $this->newOrder()
            ->where(Order::schema_fields_ID, $orderId)
            ->where(Order::schema_fields_STATUS, $currentStatus)
            ->where(Order::schema_fields_STATE_VERSION, $expectedVersion);
        $writer->update([
            Order::schema_fields_STATUS => $newStatus,
            Order::schema_fields_STATE => $newStatus,
            Order::schema_fields_STATE_VERSION => $expectedVersion + 1,
        ])->fetch();
        $updated = $writer->getQueryData();
        if ($updated === false || $updated === null || $updated === 0) {
            return false;
        }
        $reloaded = $this->newOrder()->load($orderId);

        return (string)$reloaded->getData(Order::schema_fields_STATUS) === $newStatus
            && (int)$reloaded->getData(Order::schema_fields_STATE_VERSION) === $expectedVersion + 1;
    }
    
}
