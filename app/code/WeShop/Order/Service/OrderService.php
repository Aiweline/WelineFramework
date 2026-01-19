<?php

declare(strict_types=1);

namespace WeShop\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;

/**
 * 订单服务
 */
class OrderService
{
    // 订单状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    
    // 支付状态常量
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_PARTIAL = 'partial';
    public const PAYMENT_STATUS_REFUNDED = 'refunded';
    
    /**
     * 创建订单
     * 
     * @param array $orderData 订单数据
     * @return Order
     */
    public function createOrder(array $orderData): Order
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        
        // 生成订单号
        $incrementId = $this->generateOrderNumber();
        
        $order->clearData()
            ->setData(Order::fields_increment_id, $incrementId)
            ->setData(Order::fields_customer_id, $orderData['customer_id'] ?? 0)
            ->setData(Order::fields_status, $orderData['status'] ?? self::STATUS_PENDING)
            ->setData(Order::fields_total, $orderData['total'] ?? 0)
            ->setData(Order::fields_created_at, date('Y-m-d H:i:s'))
            ->setData(Order::fields_updated_at, date('Y-m-d H:i:s'))
            ->save();
        
        return $order;
    }
    
    /**
     * 获取订单
     * 
     * @param int $orderId 订单ID
     * @return Order|null
     */
    public function getOrder(int $orderId): ?Order
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if ($order->getId()) {
            return $order;
        }
        
        return null;
    }
    
    /**
     * 根据订单号获取订单
     * 
     * @param string $incrementId 订单号
     * @return Order|null
     */
    public function getOrderByIncrementId(string $incrementId): ?Order
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($incrementId, Order::fields_increment_id);
        
        if ($order->getId()) {
            return $order;
        }
        
        return null;
    }
    
    /**
     * 获取客户订单列表
     * 
     * @param int $customerId 客户ID
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @param array $filters 筛选条件（可选：status, payment_status等）
     * @return array
     */
    public function getCustomerOrders(int $customerId, int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        
        $order->clear()
            ->where(Order::fields_customer_id, $customerId);
        
        // 应用筛选条件
        if (!empty($filters['status'])) {
            $order->where(Order::fields_status, $filters['status']);
        }
        
        if (!empty($filters['payment_status'])) {
            // 如果订单模型有payment_status字段
            if ($order->hasField('payment_status')) {
                $order->where('payment_status', $filters['payment_status']);
            }
        }
        
        $order->order(Order::fields_created_at, 'DESC')
            ->pagination($page, $pageSize);
        
        $items = $order->select()->fetchArray();
        
        return [
            'items' => $items,
            'total' => $order->getTotalCount(),
            'pagination' => $order->getPagination(),
        ];
    }
    
    /**
     * 获取客户未支付订单数量
     * 
     * @param int $customerId 客户ID
     * @return int
     */
    public function getUnpaidOrderCount(int $customerId): int
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        
        $order->clear()
            ->where(Order::fields_customer_id, $customerId)
            ->where(Order::fields_status, [self::STATUS_PENDING, self::STATUS_PROCESSING], 'IN');
        
        // 如果订单模型有payment_status字段，也筛选支付状态
        if ($order->hasField('payment_status')) {
            $order->where('payment_status', [self::PAYMENT_STATUS_PENDING, self::PAYMENT_STATUS_FAILED], 'IN');
        }
        
        return $order->select()->getTotalCount();
    }
    
    /**
     * 获取客户未支付订单列表
     * 
     * @param int $customerId 客户ID
     * @return array
     */
    public function getUnpaidOrders(int $customerId): array
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        
        $order->clear()
            ->where(Order::fields_customer_id, $customerId)
            ->where(Order::fields_status, [self::STATUS_PENDING, self::STATUS_PROCESSING], 'IN')
            ->order(Order::fields_created_at, 'DESC');
        
        // 如果订单模型有payment_status字段，也筛选支付状态
        if ($order->hasField('payment_status')) {
            $order->where('payment_status', [self::PAYMENT_STATUS_PENDING, self::PAYMENT_STATUS_FAILED], 'IN');
        }
        
        return $order->select()->fetchArray();
    }
    
    /**
     * 更新订单状态
     * 
     * @param int $orderId 订单ID
     * @param string $status 新状态
     * @return Order
     */
    public function updateOrderStatus(int $orderId, string $status): Order
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            throw new \Exception(__('订单不存在'));
        }
        
        $order->setData(Order::fields_status, $status)
            ->setData(Order::fields_updated_at, date('Y-m-d H:i:s'))
            ->save();
        
        return $order;
    }
    
    /**
     * 更新订单支付状态
     * 
     * @param int $orderId 订单ID
     * @param string $paymentStatus 支付状态
     * @return Order
     */
    public function updatePaymentStatus(int $orderId, string $paymentStatus): Order
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            throw new \Exception(__('订单不存在'));
        }
        
        // 如果订单模型有payment_status字段
        if ($order->hasField('payment_status')) {
            $order->setData('payment_status', $paymentStatus)
                ->setData(Order::fields_updated_at, date('Y-m-d H:i:s'))
                ->save();
        }
        
        return $order;
    }
    
    /**
     * 检查订单是否可以取消
     * 
     * @param int $orderId 订单ID
     * @param int $customerId 客户ID（用于验证）
     * @return array ['can_cancel' => bool, 'reason' => string|null, 'require_return' => bool]
     */
    public function canCancelOrder(int $orderId, int $customerId): array
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            return [
                'can_cancel' => false,
                'reason' => __('订单不存在'),
                'require_return' => false
            ];
        }
        
        // 验证客户ID
        if ((int)$order->getData(Order::fields_customer_id) !== $customerId) {
            return [
                'can_cancel' => false,
                'reason' => __('无权取消此订单'),
                'require_return' => false
            ];
        }
        
        $status = $order->getData(Order::fields_status);
        $paymentStatus = $order->hasField('payment_status') ? $order->getData('payment_status') : null;
        $fulfillmentStatus = $order->hasField('fulfillment_status') ? $order->getData('fulfillment_status') : null;
        
        // 已取消的订单
        if ($status === self::STATUS_CANCELLED) {
            return [
                'can_cancel' => false,
                'reason' => __('订单已取消'),
                'require_return' => false
            ];
        }
        
        // 已完成的订单不能取消
        if ($status === self::STATUS_COMPLETED) {
            return [
                'can_cancel' => false,
                'reason' => __('已完成的订单不能取消，如需退货请申请退货'),
                'require_return' => false
            ];
        }
        
        // 已退款的订单不能取消
        if ($status === self::STATUS_REFUNDED) {
            return [
                'can_cancel' => false,
                'reason' => __('订单已退款'),
                'require_return' => false
            ];
        }
        
        // 已发货的订单需要先退货
        if ($status === self::STATUS_FULFILLED || $fulfillmentStatus === 'shipped' || $fulfillmentStatus === 'delivered') {
            // 检查是否有退货记录且已确认收到退货
            $hasReturn = $this->checkOrderReturn($orderId);
            
            if (!$hasReturn) {
                return [
                    'can_cancel' => false,
                    'reason' => __('订单已发货，请先申请退货，确认收到退货后才能取消订单'),
                    'require_return' => true
                ];
            }
            
            // 如果有退货记录，检查是否已确认收到退货
            $returnConfirmed = $this->checkReturnConfirmed($orderId);
            if (!$returnConfirmed) {
                return [
                    'can_cancel' => false,
                    'reason' => __('退货申请已提交，请等待确认收到退货后才能取消订单'),
                    'require_return' => true
                ];
            }
        }
        
        // 已支付的订单需要退款流程
        if ($status === self::STATUS_PAID || $paymentStatus === self::PAYMENT_STATUS_PAID) {
            // 如果已发货，需要先退货（上面已处理）
            // 如果未发货，可以取消但需要退款
            if ($status !== self::STATUS_FULFILLED && $fulfillmentStatus !== 'shipped' && $fulfillmentStatus !== 'delivered') {
                return [
                    'can_cancel' => true,
                    'reason' => null,
                    'require_return' => false,
                    'require_refund' => true
                ];
            }
        }
        
        // 待处理或处理中的订单可以取消
        if (in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING])) {
            // 如果已支付，需要退款
            if ($paymentStatus === self::PAYMENT_STATUS_PAID) {
                return [
                    'can_cancel' => true,
                    'reason' => null,
                    'require_return' => false,
                    'require_refund' => true
                ];
            }
            
            // 未支付的订单可以直接取消
            return [
                'can_cancel' => true,
                'reason' => null,
                'require_return' => false,
                'require_refund' => false
            ];
        }
        
        // 其他状态不允许取消
        return [
            'can_cancel' => false,
            'reason' => __('当前订单状态不允许取消'),
            'require_return' => false
        ];
    }
    
    /**
     * 取消订单
     * 
     * @param int $orderId 订单ID
     * @param int $customerId 客户ID（用于验证）
     * @return bool
     * @throws \Exception
     */
    public function cancelOrder(int $orderId, int $customerId): bool
    {
        // 检查是否可以取消
        $checkResult = $this->canCancelOrder($orderId, $customerId);
        
        if (!$checkResult['can_cancel']) {
            throw new \Exception($checkResult['reason'] ?? __('订单不能取消'));
        }
        
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        // 如果需要退款，触发退款流程
        if (!empty($checkResult['require_refund'])) {
            // TODO: 调用退款服务处理退款
            // $refundService->processRefund($orderId);
        }
        
        // 更新订单状态为已取消
        $order->setData(Order::fields_status, self::STATUS_CANCELLED)
            ->setData(Order::fields_updated_at, date('Y-m-d H:i:s'))
            ->save();
        
        // 如果订单模型有payment_status字段，更新支付状态
        if ($order->hasField('payment_status')) {
            $paymentStatus = $order->getData('payment_status');
            if ($paymentStatus === self::PAYMENT_STATUS_PAID) {
                // 已支付的订单取消后，支付状态改为已退款
                $order->setData('payment_status', self::PAYMENT_STATUS_REFUNDED)
                    ->save();
            }
        }
        
        return true;
    }
    
    /**
     * 检查订单是否有退货记录
     * 
     * @param int $orderId 订单ID
     * @return bool
     */
    protected function checkOrderReturn(int $orderId): bool
    {
        // TODO: 实现退货记录检查逻辑
        // 检查是否存在退货申请记录
        // 可以使用 RMA (Return Merchandise Authorization) 模块
        
        // 临时实现：如果订单模型有return_status字段，检查该字段
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if ($order->hasField('return_status')) {
            $returnStatus = $order->getData('return_status');
            return !empty($returnStatus) && $returnStatus !== 'none';
        }
        
        // 默认返回false，表示没有退货记录
        return false;
    }
    
    /**
     * 检查退货是否已确认收到
     * 
     * @param int $orderId 订单ID
     * @return bool
     */
    protected function checkReturnConfirmed(int $orderId): bool
    {
        // TODO: 实现退货确认检查逻辑
        // 检查退货申请是否已确认收到退货
        
        // 临时实现：如果订单模型有return_status字段，检查是否为confirmed
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if ($order->hasField('return_status')) {
            $returnStatus = $order->getData('return_status');
            return $returnStatus === 'confirmed' || $returnStatus === 'received';
        }
        
        // 默认返回false
        return false;
    }
    
    /**
     * 检查订单是否可以继续支付
     * 
     * @param int $orderId 订单ID
     * @param int $customerId 客户ID（用于验证）
     * @return bool
     */
    public function canRetryPayment(int $orderId, int $customerId): bool
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            return false;
        }
        
        // 验证客户ID
        if ((int)$order->getData(Order::fields_customer_id) !== $customerId) {
            return false;
        }
        
        // 只有待处理或处理中的订单可以继续支付
        $status = $order->getData(Order::fields_status);
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING])) {
            return false;
        }
        
        // 如果订单模型有payment_status字段，检查支付状态
        if ($order->hasField('payment_status')) {
            $paymentStatus = $order->getData('payment_status');
            if (!in_array($paymentStatus, [self::PAYMENT_STATUS_PENDING, self::PAYMENT_STATUS_FAILED])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 获取订单商品列表
     * 
     * @param int $orderId 订单ID
     * @return array
     */
    public function getOrderItems(int $orderId): array
    {
        /** @var \WeShop\Order\Model\OrderItem $orderItem */
        $orderItem = ObjectManager::getInstance(\WeShop\Order\Model\OrderItem::class);
        
        $orderItem->clear()
            ->where('order_id', $orderId)
            ->order('item_id', 'ASC');
        
        return $orderItem->select()->fetchArray();
    }
    
    /**
     * 生成订单号
     * 
     * @return string
     */
    protected function generateOrderNumber(): string
    {
        // 格式: YYYYMMDDHHMMSS + 4位随机数
        return date('YmdHis') . str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
