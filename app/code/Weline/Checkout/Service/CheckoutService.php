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
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\EventsManager;

/**
 * 结账服务
 */
class CheckoutService
{
    private ConnectionFactory $connectionFactory;
    private EventsManager $eventsManager;

    public function __construct(
        ConnectionFactory $connectionFactory,
        EventsManager $eventsManager
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 验证结账数据
     * 
     * @param array $data
     * @return array 返回验证结果 ['valid' => bool, 'errors' => []]
     */
    public function validateCheckout(array $data): array
    {
        // 派遣验证前事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::validate::before', [
            'data' => &$data,
        ]);
        
        $errors = [];
        
        // 验证客户ID
        if (empty($data['customer_id'])) {
            $errors[] = __('客户ID不能为空');
        }
        
        // 验证订单项
        if (empty($data['items']) || !is_array($data['items'])) {
            $errors[] = __('订单项不能为空');
        } else {
            foreach ($data['items'] as $index => $item) {
                if (empty($item['product_id'])) {
                    $errors[] = __('订单项 %{1} 的产品ID不能为空', [$index + 1]);
                }
                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    $errors[] = __('订单项 %{1} 的数量必须大于0', [$index + 1]);
                }
                if (empty($item['price']) || $item['price'] < 0) {
                    $errors[] = __('订单项 %{1} 的价格无效', [$index + 1]);
                }
            }
        }
        
        // 验证收货地址
        if (empty($data['shipping_address'])) {
            $errors[] = __('收货地址不能为空');
        }
        
        $result = [
            'valid' => empty($errors),
            'errors' => $errors
        ];
        
        // 派遣验证后事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::validate::after', [
            'data' => $data,
            'result' => &$result,
        ]);
        
        return $result;
    }

    /**
     * 计算订单总额
     * 
     * @param array $items 订单项
     * @param float $shippingAmount 运费
     * @param float $taxAmount 税费
     * @param float $discountAmount 折扣金额
     * @return array
     */
    public function calculateTotals(array $items, float $shippingAmount = 0.0, float $taxAmount = 0.0, float $discountAmount = 0.0): array
    {
        // 派遣计算前事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::calculate_totals::before', [
            'items' => &$items,
            'shipping_amount' => &$shippingAmount,
            'tax_amount' => &$taxAmount,
            'discount_amount' => &$discountAmount,
        ]);
        
        $subtotal = 0.0;
        
        foreach ($items as $item) {
            $quantity = (float)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $subtotal += $quantity * $price;
        }
        
        $total = $subtotal + $shippingAmount + $taxAmount - $discountAmount;
        
        $result = [
            'subtotal' => round($subtotal, 2),
            'shipping_amount' => round($shippingAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_amount' => round($total, 2)
        ];
        
        // 派遣计算后事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::calculate_totals::after', [
            'items' => $items,
            'result' => &$result,
        ]);
        
        return $result;
    }

    /**
     * 创建订单
     * 
     * @param array $data
     * @return Order
     * @throws \Exception
     */
    public function createOrder(array $data): Order
    {
        // 验证数据
        $validation = $this->validateCheckout($data);
        if (!$validation['valid']) {
            throw new \Exception(implode(', ', $validation['errors']));
        }
        
        $conn = $this->connectionFactory->getConnection();
        $conn->beginTransaction();
        
        try {
            // 派遣创建订单前事件
            $this->eventsManager->dispatch('Weline_Checkout::checkout::create_order::before', [
                'data' => &$data,
            ]);
            
            // 计算订单总额
            $totals = $this->calculateTotals(
                $data['items'],
                (float)($data['shipping_amount'] ?? 0.0),
                (float)($data['tax_amount'] ?? 0.0),
                (float)($data['discount_amount'] ?? 0.0)
            );
            
            // 创建订单
            /** @var Order $order */
            $order = ObjectManager::getInstance(Order::class);
            $order->setData([
                Order::fields_ORDER_NUMBER => $order->generateOrderNumber(),
                Order::fields_CUSTOMER_ID => $data['customer_id'],
                Order::fields_STATUS => Order::STATUS_PENDING,
                Order::fields_SUBTOTAL => $totals['subtotal'],
                Order::fields_SHIPPING_AMOUNT => $totals['shipping_amount'],
                Order::fields_TAX_AMOUNT => $totals['tax_amount'],
                Order::fields_DISCOUNT_AMOUNT => $totals['discount_amount'],
                Order::fields_TOTAL_AMOUNT => $totals['total_amount'],
                Order::fields_CURRENCY => $data['currency'] ?? 'CNY',
                Order::fields_SHIPPING_ADDRESS => is_array($data['shipping_address']) 
                    ? json_encode($data['shipping_address'], JSON_UNESCAPED_UNICODE) 
                    : $data['shipping_address'],
                Order::fields_BILLING_ADDRESS => !empty($data['billing_address'])
                    ? (is_array($data['billing_address']) 
                        ? json_encode($data['billing_address'], JSON_UNESCAPED_UNICODE) 
                        : $data['billing_address'])
                    : (is_array($data['shipping_address']) 
                        ? json_encode($data['shipping_address'], JSON_UNESCAPED_UNICODE) 
                        : $data['shipping_address']),
                Order::fields_PAYMENT_METHOD => $data['payment_method'] ?? '',
                Order::fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PENDING,
                Order::fields_SHIPPING_METHOD => $data['shipping_method'] ?? '',
                Order::fields_SHIPPING_STATUS => Order::SHIPPING_STATUS_PENDING,
                Order::fields_REMARK => $data['remark'] ?? '',
                Order::fields_CREATED_TIME => date('Y-m-d H:i:s'),
                Order::fields_UPDATED_TIME => date('Y-m-d H:i:s'),
            ]);
            $order->save();
            
            // 创建订单项
            /** @var OrderItem $orderItem */
            $orderItem = ObjectManager::getInstance(OrderItem::class);
            foreach ($data['items'] as $item) {
                $orderItem->clear()
                    ->setData([
                        OrderItem::fields_ORDER_ID => $order->getId(),
                        OrderItem::fields_PRODUCT_ID => $item['product_id'],
                        OrderItem::fields_PRODUCT_NAME => $item['product_name'] ?? '',
                        OrderItem::fields_PRODUCT_SKU => $item['product_sku'] ?? '',
                        OrderItem::fields_QUANTITY => $item['quantity'],
                        OrderItem::fields_PRICE => $item['price'],
                        OrderItem::fields_TOTAL_PRICE => (float)$item['quantity'] * (float)$item['price'],
                        OrderItem::fields_ATTRIBUTES => !empty($item['attributes'])
                            ? (is_array($item['attributes']) 
                                ? json_encode($item['attributes'], JSON_UNESCAPED_UNICODE) 
                                : $item['attributes'])
                            : '',
                        OrderItem::fields_CREATED_TIME => date('Y-m-d H:i:s'),
                    ])
                    ->save();
            }
            
            // 派遣创建订单后事件
            $this->eventsManager->dispatch('Weline_Checkout::checkout::create_order::after', [
                'order' => $order,
                'order_id' => $order->getId(),
            ]);
            
            $conn->commit();
            return $order;
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}

