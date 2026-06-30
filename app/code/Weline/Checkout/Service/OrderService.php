<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Service;

use Weline\Checkout\Model\Order;
use Weline\Checkout\Model\OrderItem;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;

/**
 * 订单服务
 */
class OrderService
{
    private EventsManager $eventsManager;

    public function __construct(EventsManager $eventsManager)
    {
        $this->eventsManager = $eventsManager;
    }

    /**
     * 获取订单详情
     * 
     * @param int $orderId
     * @return Order|null
     */
    public function getOrder(int $orderId): ?Order
    {
        // 派遣订单加载前事件
        $this->eventsManager->dispatch('Weline_Checkout::order::load::before', [
            'order_id' => $orderId,
        ]);
        
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            // 派遣订单加载后事件（即使订单不存在也派遣）
            $this->eventsManager->dispatch('Weline_Checkout::order::load::after', [
                'order_id' => $orderId,
                'order' => null,
            ]);
            return null;
        }
        
        // 派遣订单加载后事件
        $this->eventsManager->dispatch('Weline_Checkout::order::load::after', [
            'order_id' => $orderId,
            'order' => &$order,
        ]);
        
        return $order;
    }

    /**
     * 根据订单号获取订单
     * 
     * @param string $orderNumber
     * @return Order|null
     */
    public function getOrderByNumber(string $orderNumber): ?Order
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load(Order::schema_fields_ORDER_NUMBER, $orderNumber);
        
        if (!$order->getId()) {
            return null;
        }
        
        return $order;
    }

    /**
     * 更新订单状态
     * 
     * @param int $orderId
     * @param string $status
     * @param string|null $oldStatus
     * @return bool
     * @throws \Exception
     */
    public function updateOrderStatus(int $orderId, string $status, ?string $oldStatus = null): bool
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            throw new \Exception(__('订单不存在'));
        }
        
        $oldStatus = $oldStatus ?? $order->getStatus();
        
        // 派遣订单状态变更前事件
        $this->eventsManager->dispatch('Weline_Checkout::order::status::change::before', [
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'order' => $order,
        ]);
        
        // 更新状态
        $order->setStatus($status);
        $order->setUpdatedTime(date('Y-m-d H:i:s'));
        $order->save();
        
        // 派遣订单状态变更后事件
        $this->eventsManager->dispatch('Weline_Checkout::order::status::change::after', [
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'order' => $order,
        ]);
        
        // 派遣特定状态事件
        if ($status === Order::STATUS_COMPLETED) {
            $this->eventsManager->dispatch('Weline_Checkout::order::completed', [
                'order_id' => $orderId,
                'order' => $order,
            ]);
        } elseif ($status === Order::STATUS_CANCELLED) {
            $this->eventsManager->dispatch('Weline_Checkout::order::cancelled', [
                'order_id' => $orderId,
                'order' => $order,
            ]);
        } elseif ($status === Order::STATUS_REFUNDED) {
            $this->eventsManager->dispatch('Weline_Checkout::order::refunded', [
                'order_id' => $orderId,
                'order' => $order,
            ]);
        }
        
        return true;
    }

    /**
     * 取消订单
     * 
     * @param int $orderId
     * @return bool
     * @throws \Exception
     */
    public function cancelOrder(int $orderId): bool
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            throw new \Exception(__('订单不存在'));
        }
        
        if (!$order->canCancel()) {
            throw new \Exception(__('订单无法取消'));
        }
        
        // 派遣订单取消前事件
        $beforeEventData = [
            'order_id' => $orderId,
            'order' => $order,
        ];
        $this->eventsManager->dispatch('Weline_Checkout::order::cancel::before', $beforeEventData);
        
        $result = $this->updateOrderStatus($orderId, Order::STATUS_CANCELLED);
        
        // 派遣订单取消后事件
        $afterEventData = [
            'order_id' => $orderId,
            'order' => $order,
        ];
        $this->eventsManager->dispatch('Weline_Checkout::order::cancel::after', $afterEventData);
        
        return $result;
    }

    /**
     * 获取客户的订单列表
     * 
     * @param int $customerId
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getCustomerOrders(int $customerId, int $page = 1, int $pageSize = 20): array
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        
        $offset = ($page - 1) * $pageSize;
        
        return $order->where(Order::schema_fields_CUSTOMER_ID, $customerId)
            ->order(Order::schema_fields_CREATED_TIME, 'DESC')
            ->limit($pageSize, $offset)
            ->select()
            ->fetchArray();
    }

}

