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
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\OrderHistory;

/**
 * 订单核心服务
 * 
 * @package Weline_Order
 */
class OrderService
{
    private ObjectManager $objectManager;
    private OrderStateMachine $stateMachine;
    private EventsManager $eventsManager;
    private ?DiscountValidationService $discountValidationService = null;
    
    public function __construct(
        ObjectManager $objectManager,
        OrderStateMachine $stateMachine,
        EventsManager $eventsManager
    ) {
        $this->objectManager = $objectManager;
        $this->stateMachine = $stateMachine;
        $this->eventsManager = $eventsManager;
    }
    
    /**
     * 获取优惠方式验证服务
     * 
     * @return DiscountValidationService
     */
    private function getDiscountValidationService(): DiscountValidationService
    {
        if ($this->discountValidationService === null) {
            $this->discountValidationService = $this->objectManager->getInstance(DiscountValidationService::class);
        }
        return $this->discountValidationService;
    }
    
    /**
     * 获取订单模型实例
     * 
     * @return Order
     */
    private function getOrderModel(): Order
    {
        return $this->objectManager->getInstance(Order::class);
    }
    
    /**
     * 获取订单项模型实例
     * 
     * @return OrderItem
     */
    private function getOrderItemModel(): OrderItem
    {
        return $this->objectManager->getInstance(OrderItem::class);
    }
    
    /**
     * 创建订单
     * 
     * @param array $data 订单数据
     * @return Order
     * @throws \Exception
     */
    public function createOrder(array $data): Order
    {
        // 验证必需字段
        $this->validateOrderData($data);
        
        // 开始事务
        $connection = $this->getOrderModel()->getConnection();
        $connection->beginTransaction();
        
        try {
            // 创建订单主记录
            $order = $this->getOrderModel()->reset();
            
            // 生成订单号
            if (empty($data[Order::fields_ORDER_NUMBER])) {
                $data[Order::fields_ORDER_NUMBER] = $order->generateOrderNumber();
            }
            
            // 设置默认值
            $data[Order::fields_STATUS] = $data[Order::fields_STATUS] ?? Order::STATUS_PENDING;
            $data[Order::fields_STATE] = $data[Order::fields_STATE] ?? Order::STATUS_PENDING;
            $data[Order::fields_PAYMENT_STATUS] = $data[Order::fields_PAYMENT_STATUS] ?? Order::PAYMENT_STATUS_PENDING;
            $data[Order::fields_FULFILLMENT_STATUS] = $data[Order::fields_FULFILLMENT_STATUS] ?? Order::FULFILLMENT_STATUS_PENDING;
            $data[Order::fields_CURRENCY] = $data[Order::fields_CURRENCY] ?? 'CNY';
            
            $order->setData($data);
            
            // 计算订单总额
            if (isset($data['items']) && is_array($data['items'])) {
                $this->calculateTotals($order, $data['items']);
            }
            
            // 验证优惠规则（如果提供了支付方式和优惠规则）
            if (!empty($data[Order::fields_PAYMENT_METHOD]) && !empty($data['discount_rules'])) {
                $this->validateDiscountRules($order, $data[Order::fields_PAYMENT_METHOD], $data['discount_rules']);
            }
            
            $order->save();
            
            // 创建订单项
            if (isset($data['items']) && is_array($data['items'])) {
                $this->createOrderItems($order->getId(), $data['items']);
            }
            
            // 记录订单历史
            $this->addHistory($order->getId(), Order::STATUS_PENDING, __('订单已创建'));
            
            // 提交事务
            $connection->commit();
            
            // 触发订单创建事件
            $this->eventsManager->dispatch('Weline_Order::order_created', [
                'order' => $order,
                'order_id' => $order->getId(),
            ]);
            
            return $order;
            
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }
    
    /**
     * 更新订单
     * 
     * @param int $orderId 订单ID
     * @param array $data 更新数据
     * @return Order
     * @throws \Exception
     */
    public function updateOrder(int $orderId, array $data): Order
    {
        $order = $this->getOrder($orderId);
        
        // 开始事务
        $connection = $this->getOrderModel()->getConnection();
        $connection->beginTransaction();
        
        try {
            // 更新订单数据
            foreach ($data as $key => $value) {
                if ($key !== 'items' && $key !== Order::fields_ORDER_ID) {
                    $order->setData($key, $value);
                }
            }
            
            // 如果更新了订单项，重新计算总额
            if (isset($data['items']) && is_array($data['items'])) {
                // 删除旧订单项
                $this->getOrderItemModel()->reset()
                    ->where(OrderItem::fields_ORDER_ID, $orderId)
                    ->delete();
                
                // 创建新订单项
                $this->createOrderItems($orderId, $data['items']);
                
                // 重新计算总额
                $items = $this->getOrderItems($orderId);
                $this->calculateTotals($order, $items);
            }
            
            $order->save();
            
            // 记录订单历史
            $this->addHistory($orderId, $order->getData(Order::fields_STATUS), __('订单已更新'));
            
            // 提交事务
            $connection->commit();
            
            // 触发订单更新事件
            $this->eventsManager->dispatch('Weline_Order::order_updated', [
                'order' => $order,
                'order_id' => $orderId,
            ]);
            
            return $order;
            
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }
    
    /**
     * 取消订单
     * 
     * @param int $orderId 订单ID
     * @param string $reason 取消原因
     * @return Order
     * @throws \Exception
     */
    public function cancelOrder(int $orderId, string $reason = ''): Order
    {
        $order = $this->getOrder($orderId);
        
        if (!$order->canCancel()) {
            throw new \Exception(__('订单当前状态不允许取消'));
        }
        
        // 使用状态机转换状态
        $order = $this->stateMachine->transition($orderId, Order::STATUS_CANCELLED, $reason);
        
        // 触发订单取消事件
        $this->eventsManager->dispatch('Weline_Order::order_cancelled', [
            'order' => $order,
            'order_id' => $orderId,
            'reason' => $reason,
        ]);
        
        return $order;
    }
    
    /**
     * 获取订单详情
     * 
     * @param int $orderId 订单ID
     * @return Order
     * @throws \Exception
     */
    public function getOrder(int $orderId): Order
    {
        $order = $this->getOrderModel()->reset()->load($orderId);
        
        if (!$order->getId()) {
            throw new \Exception(__('订单不存在'));
        }
        
        return $order;
    }
    
    /**
     * 获取订单列表
     * 
     * @param array $filters 过滤条件
     * @return array
     */
    public function getOrderList(array $filters = []): array
    {
        $model = $this->getOrderModel()->reset();
        
        // 应用过滤条件
        if (isset($filters['status']) && $filters['status']) {
            $model->where(Order::fields_STATUS, $filters['status']);
        }
        
        if (isset($filters['customer_id']) && $filters['customer_id']) {
            $model->where(Order::fields_CUSTOMER_ID, $filters['customer_id']);
        }
        
        if (isset($filters['order_number']) && $filters['order_number']) {
            $model->where(Order::fields_ORDER_NUMBER, $filters['order_number']);
        }
        
        if (isset($filters['payment_status']) && $filters['payment_status']) {
            $model->where(Order::fields_PAYMENT_STATUS, $filters['payment_status']);
        }
        
        if (isset($filters['fulfillment_status']) && $filters['fulfillment_status']) {
            $model->where(Order::fields_FULFILLMENT_STATUS, $filters['fulfillment_status']);
        }
        
        if (isset($filters['keyword']) && $filters['keyword']) {
            $keyword = "%{$filters['keyword']}%";
            $model->where(Order::fields_ORDER_NUMBER, $keyword, 'LIKE', 'OR')
                  ->where(Order::fields_CUSTOMER_NAME, $keyword, 'LIKE', 'OR')
                  ->where(Order::fields_CUSTOMER_EMAIL, $keyword, 'LIKE');
        }
        
        // 排序
        $model->order(Order::fields_CREATED_AT, 'DESC');
        
        // 分页
        if (isset($filters['page']) && isset($filters['page_size'])) {
            $model->page($filters['page'], $filters['page_size']);
        }
        
        $collection = $model->select()->fetch();
        return $collection->getItems();
    }
    
    /**
     * 计算订单总额
     * 
     * @param Order $order 订单对象
     * @param array $items 订单项数据
     * @return void
     */
    public function calculateTotals(Order $order, array $items): void
    {
        $subtotal = 0;
        $taxAmount = 0;
        $discountAmount = 0;
        
        foreach ($items as $item) {
            if (is_array($item)) {
                $qty = (float)($item['qty_ordered'] ?? $item['quantity'] ?? 0);
                $price = (float)($item['price'] ?? 0);
                $itemTax = (float)($item['tax_amount'] ?? 0);
                $itemDiscount = (float)($item['discount_amount'] ?? 0);
                
                $subtotal += $qty * $price;
                $taxAmount += $itemTax;
                $discountAmount += $itemDiscount;
            } elseif ($item instanceof OrderItem) {
                $subtotal += (float)$item->getData(OrderItem::fields_ROW_TOTAL);
                $taxAmount += (float)$item->getData(OrderItem::fields_TAX_AMOUNT);
                $discountAmount += (float)$item->getData(OrderItem::fields_DISCOUNT_AMOUNT);
            }
        }
        
        $shippingAmount = (float)$order->getData(Order::fields_SHIPPING_AMOUNT) ?? 0;
        $grandTotal = $subtotal + $shippingAmount + $taxAmount - $discountAmount;
        
        $order->setData(Order::fields_SUBTOTAL, $subtotal);
        $order->setData(Order::fields_TAX_AMOUNT, $taxAmount);
        $order->setData(Order::fields_DISCOUNT_AMOUNT, $discountAmount);
        $order->setData(Order::fields_GRAND_TOTAL, $grandTotal);
    }
    
    /**
     * 创建订单项
     * 
     * @param int $orderId 订单ID
     * @param array $items 订单项数据
     * @return void
     */
    private function createOrderItems(int $orderId, array $items): void
    {
        foreach ($items as $itemData) {
            $item = $this->getOrderItemModel()->reset();
            $item->setData(OrderItem::fields_ORDER_ID, $orderId);
            
            // 映射字段
            $fieldMap = [
                'product_id' => OrderItem::fields_PRODUCT_ID,
                'product_sku' => OrderItem::fields_PRODUCT_SKU,
                'product_name' => OrderItem::fields_PRODUCT_NAME,
                'product_type' => OrderItem::fields_PRODUCT_TYPE,
                'qty_ordered' => OrderItem::fields_QTY_ORDERED,
                'quantity' => OrderItem::fields_QTY_ORDERED,
                'price' => OrderItem::fields_PRICE,
                'discount_amount' => OrderItem::fields_DISCOUNT_AMOUNT,
                'tax_amount' => OrderItem::fields_TAX_AMOUNT,
            ];
            
            foreach ($fieldMap as $source => $target) {
                if (isset($itemData[$source])) {
                    $item->setData($target, $itemData[$source]);
                }
            }
            
            // 计算行总计
            $rowTotal = $item->calculateRowTotal();
            $item->setData(OrderItem::fields_ROW_TOTAL, $rowTotal);
            
            // 设置默认值
            $item->setData(OrderItem::fields_QTY_SHIPPED, 0);
            $item->setData(OrderItem::fields_QTY_REFUNDED, 0);
            $item->setData(OrderItem::fields_QTY_CANCELLED, 0);
            
            $item->save();
        }
    }
    
    /**
     * 获取订单项列表
     * 
     * @param int $orderId 订单ID
     * @return array
     */
    public function getOrderItems(int $orderId): array
    {
        $collection = $this->getOrderItemModel()->reset()
            ->where(OrderItem::fields_ORDER_ID, $orderId)
            ->select()
            ->fetch();
        
        return $collection->getItems();
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
        $history = $this->objectManager->getInstance(OrderHistory::class);
        $history->setData(OrderHistory::fields_ORDER_ID, $orderId)
                ->setData(OrderHistory::fields_STATUS, $status)
                ->setData(OrderHistory::fields_COMMENT, $comment)
                ->setData(OrderHistory::fields_IS_CUSTOMER_NOTIFIED, $notifyCustomer ? 1 : 0)
                ->save();
    }
    
    /**
     * 验证订单数据
     * 
     * @param array $data 订单数据
     * @return void
     * @throws \Exception
     */
    private function validateOrderData(array $data): void
    {
        $required = [
            'items' => __('订单项'),
        ];
        
        foreach ($required as $field => $label) {
            if (empty($data[$field])) {
                throw new \Exception(__('%{1}不能为空', [$label]));
            }
        }
        
        if (!is_array($data['items']) || empty($data['items'])) {
            throw new \Exception(__('订单项不能为空'));
        }
        
        // 验证订单项
        foreach ($data['items'] as $item) {
            if (empty($item['product_name'])) {
                throw new \Exception(__('商品名称不能为空'));
            }
            if (empty($item['qty_ordered']) && empty($item['quantity'])) {
                throw new \Exception(__('商品数量不能为空'));
            }
            if (!isset($item['price'])) {
                throw new \Exception(__('商品价格不能为空'));
            }
        }
    }
    
    /**
     * 验证优惠规则是否可以应用于订单
     * 
     * @param Order $order 订单对象
     * @param string $paymentMethodCode 支付方式代码
     * @param array $discountRules 优惠规则数组，每个规则包含actions配置
     * @return void
     * @throws \Exception 如果支付方式不支持优惠方式，抛出异常
     */
    private function validateDiscountRules(Order $order, string $paymentMethodCode, array $discountRules): void
    {
        $validationService = $this->getDiscountValidationService();
        
        foreach ($discountRules as $rule) {
            if (empty($rule['actions'])) {
                continue;
            }
            
            $validationResult = $validationService->validateRuleActions($paymentMethodCode, $rule['actions']);
            
            if (!$validationResult['valid']) {
                $messages = implode('; ', $validationResult['messages']);
                throw new \Exception($messages);
            }
        }
    }
    
    /**
     * 验证单个优惠规则是否可以应用于订单
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @param array $ruleActions 规则动作配置
     * @return array 返回验证结果 ['valid' => bool, 'unsupported' => array, 'messages' => array]
     */
    public function validateDiscountRule(string $paymentMethodCode, array $ruleActions): array
    {
        $validationService = $this->getDiscountValidationService();
        return $validationService->validateRuleActions($paymentMethodCode, $ruleActions);
    }
}

