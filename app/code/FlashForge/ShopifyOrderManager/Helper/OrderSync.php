<?php

namespace FlashForge\ShopifyOrderManager\Helper;

use Weline\Framework\App\Helper;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Shop;
use FlashForge\ShopifyOrderManager\Model\Order;
use FlashForge\ShopifyOrderManager\Model\OrderItem;
use FlashForge\ShopifyOrderManager\Helper\ShopifyApi;
use FlashForge\ShopifyOrderManager\Helper\FeishuNotify;

/**
 * 订单同步服务类
 */
class OrderSync extends Helper
{
    private ShopifyApi $shopifyApi;
    private FeishuNotify $feishuNotify;
    private Shop $shopModel;
    private Order $orderModel;
    private OrderItem $orderItemModel;

    public function __construct()
    {
        $this->shopifyApi = ObjectManager::getInstance(ShopifyApi::class);
        $this->feishuNotify = ObjectManager::getInstance(FeishuNotify::class);
        $this->shopModel = ObjectManager::getInstance(Shop::class);
        $this->orderModel = ObjectManager::getInstance(Order::class);
        $this->orderItemModel = ObjectManager::getInstance(OrderItem::class);
    }

    /**
     * 同步所有活跃店铺的订单
     */
    public function syncAllShops(): array
    {
        $shops = $this->shopModel->getActiveShops();
        $results = [];

        foreach ($shops as $shop) {
            try {
                $result = $this->syncShopOrders($shop);
                $results[] = $result;

                // 发送成功通知（可选）
                if ($result['success'] && $result['new_orders'] > 0) {
                    $this->feishuNotify->sendSyncSuccessNotify(
                        $shop['shop_name'],
                        $result['total_orders'],
                        $result['new_orders']
                    );
                }

            } catch (\Exception $e) {
                $errorResult = [
                    'shop_id' => $shop['shop_id'],
                    'shop_name' => $shop['shop_name'],
                    'success' => false,
                    'error' => $e->getMessage(),
                    'total_orders' => 0,
                    'new_orders' => 0,
                    'updated_orders' => 0
                ];

                $results[] = $errorResult;

                // 发送错误通知
                $this->feishuNotify->sendErrorNotify(
                    '店铺订单同步失败',
                    "店铺 {$shop['shop_name']} 订单同步时发生错误",
                    [
                        '店铺ID' => $shop['shop_id'],
                        '错误信息' => $e->getMessage()
                    ]
                );
            }
        }

        return $results;
    }

    /**
     * 同步单个店铺的订单
     */
    public function syncShopOrders(array $shop): array
    {
        // 初始化Shopify API
        $this->shopifyApi->init($shop['shop_url'], $shop['access_token']);

        // 测试API连接
        if (!$this->shopifyApi->testConnection()) {
            throw new \Exception('无法连接到Shopify API，请检查店铺配置');
        }

        // 获取最后同步时间
        $lastSyncTime = $shop['last_sync_time'] ?: date('Y-m-d H:i:s', strtotime('-1 day'));

        // 获取更新的订单
        $ordersData = $this->shopifyApi->getRecentlyUpdatedOrders($lastSyncTime);

        if (empty($ordersData['orders'])) {
            // 更新同步时间
            $this->shopModel->updateLastSyncTime($shop['shop_id']);
            
            return [
                'shop_id' => $shop['shop_id'],
                'shop_name' => $shop['shop_name'],
                'success' => true,
                'total_orders' => 0,
                'new_orders' => 0,
                'updated_orders' => 0
            ];
        }

        $totalOrders = count($ordersData['orders']);
        $newOrders = 0;
        $updatedOrders = 0;

        // 开始事务
        $this->orderModel->beginTransaction();

        try {
            foreach ($ordersData['orders'] as $orderData) {
                $isNewOrder = $this->processOrder($shop['shop_id'], $orderData);
                
                if ($isNewOrder) {
                    $newOrders++;
                } else {
                    $updatedOrders++;
                }
            }

            // 更新店铺最后同步时间
            $this->shopModel->updateLastSyncTime($shop['shop_id']);

            // 提交事务
            $this->orderModel->commit();

            return [
                'shop_id' => $shop['shop_id'],
                'shop_name' => $shop['shop_name'],
                'success' => true,
                'total_orders' => $totalOrders,
                'new_orders' => $newOrders,
                'updated_orders' => $updatedOrders
            ];

        } catch (\Exception $e) {
            // 回滚事务
            $this->orderModel->rollBack();
            throw $e;
        }
    }

    /**
     * 处理单个订单
     */
    private function processOrder(int $shopId, array $orderData): bool
    {
        $shopifyOrderId = $orderData['id'];
        
        // 检查订单是否已存在
        $existingOrder = $this->orderModel
            ->where(Order::fields_SHOP_ID, $shopId)
            ->where(Order::fields_SHOPIFY_ORDER_ID, $shopifyOrderId)
            ->find()
            ->fetch();

        $isNewOrder = !$existingOrder->getId();

        // 准备订单数据
        $orderInsertData = [
            Order::fields_SHOP_ID => $shopId,
            Order::fields_SHOPIFY_ORDER_ID => $shopifyOrderId,
            Order::fields_ORDER_NUMBER => $orderData['order_number'] ?? '',
            Order::fields_CUSTOMER_EMAIL => $orderData['email'] ?? '',
            Order::fields_CUSTOMER_NAME => $this->getCustomerName($orderData),
            Order::fields_TOTAL_PRICE => $orderData['total_price'] ?? 0,
            Order::fields_SUBTOTAL_PRICE => $orderData['subtotal_price'] ?? 0,
            Order::fields_TOTAL_TAX => $orderData['total_tax'] ?? 0,
            Order::fields_CURRENCY => $orderData['currency'] ?? 'USD',
            Order::fields_FINANCIAL_STATUS => $orderData['financial_status'] ?? '',
            Order::fields_FULFILLMENT_STATUS => $orderData['fulfillment_status'] ?? Order::FULFILLMENT_PENDING,
            Order::fields_ORDER_STATUS => $this->mapOrderStatus($orderData),
            Order::fields_TAGS => $orderData['tags'] ?? '',
            Order::fields_NOTE => $orderData['note'] ?? '',
            Order::fields_SHIPPING_ADDRESS => json_encode($orderData['shipping_address'] ?? []),
            Order::fields_BILLING_ADDRESS => json_encode($orderData['billing_address'] ?? []),
            Order::fields_SHOPIFY_CREATED_AT => $this->formatDateTime($orderData['created_at']),
            Order::fields_SHOPIFY_UPDATED_AT => $this->formatDateTime($orderData['updated_at'])
        ];

        if ($isNewOrder) {
            // 创建新订单
            $order = new Order();
            $order->setData($orderInsertData);
            $order->save();
            $orderId = $order->getId();
        } else {
            // 更新现有订单
            $orderId = $existingOrder->getId();
            $existingOrder->setData($orderInsertData);
            $existingOrder->save();

            // 删除旧的订单项目
            $this->orderItemModel->deleteByOrderId($orderId);
        }

        // 处理订单项目
        if (!empty($orderData['line_items'])) {
            $this->processOrderItems($orderId, $orderData['line_items']);
        }

        return $isNewOrder;
    }

    /**
     * 处理订单项目
     */
    private function processOrderItems(int $orderId, array $lineItems): void
    {
        $itemsData = [];

        foreach ($lineItems as $item) {
            $itemsData[] = [
                OrderItem::fields_ORDER_ID => $orderId,
                OrderItem::fields_SHOPIFY_PRODUCT_ID => $item['product_id'] ?? null,
                OrderItem::fields_SHOPIFY_VARIANT_ID => $item['variant_id'] ?? null,
                OrderItem::fields_PRODUCT_TITLE => $item['title'] ?? '',
                OrderItem::fields_VARIANT_TITLE => $item['variant_title'] ?? '',
                OrderItem::fields_SKU => $item['sku'] ?? '',
                OrderItem::fields_QUANTITY => $item['quantity'] ?? 1,
                OrderItem::fields_PRICE => $item['price'] ?? 0,
                OrderItem::fields_TOTAL_DISCOUNT => $item['total_discount'] ?? 0,
                OrderItem::fields_VENDOR => $item['vendor'] ?? '',
                OrderItem::fields_PRODUCT_TYPE => $item['product_type'] ?? '',
                OrderItem::fields_REQUIRES_SHIPPING => $item['requires_shipping'] ? 1 : 0,
                OrderItem::fields_TAXABLE => $item['taxable'] ? 1 : 0,
                OrderItem::fields_GIFT_CARD => $item['gift_card'] ? 1 : 0,
                OrderItem::fields_FULFILLMENT_SERVICE => $item['fulfillment_service'] ?? '',
                OrderItem::fields_PROPERTIES => json_encode($item['properties'] ?? [])
            ];
        }

        $this->orderItemModel->batchInsertItems($itemsData);
    }

    /**
     * 检查超时订单并发送通知
     */
    public function checkOverdueOrders(): bool
    {
        try {
            $overdueOrders = $this->orderModel->getOverdueOrders();

            if (!empty($overdueOrders)) {
                // 获取店铺信息
                $shopIds = array_unique(array_column($overdueOrders, 'shop_id'));
                $shops = [];
                
                foreach ($shopIds as $shopId) {
                    $shop = $this->shopModel->where(Shop::fields_ID, $shopId)->find()->fetch();
                    if ($shop->getId()) {
                        $shops[$shopId] = $shop->getData();
                    }
                }

                // 添加店铺名称到订单数据
                foreach ($overdueOrders as &$order) {
                    $order['shop_name'] = $shops[$order['shop_id']]['shop_name'] ?? '未知店铺';
                }

                // 发送飞书通知
                return $this->feishuNotify->sendOverdueOrderNotify($overdueOrders);
            }

            return true;

        } catch (\Exception $e) {
            $this->feishuNotify->sendErrorNotify(
                '超时订单检查失败',
                '检查超时订单时发生错误: ' . $e->getMessage()
            );
            
            return false;
        }
    }

    /**
     * 获取客户姓名
     */
    private function getCustomerName(array $orderData): string
    {
        if (!empty($orderData['customer'])) {
            $customer = $orderData['customer'];
            $firstName = $customer['first_name'] ?? '';
            $lastName = $customer['last_name'] ?? '';
            
            return trim($firstName . ' ' . $lastName);
        }

        return '';
    }

    /**
     * 映射订单状态
     */
    private function mapOrderStatus(array $orderData): string
    {
        $financialStatus = $orderData['financial_status'] ?? '';
        $fulfillmentStatus = $orderData['fulfillment_status'] ?? '';

        if ($financialStatus === 'paid' && $fulfillmentStatus === 'fulfilled') {
            return Order::STATUS_FULFILLED;
        } elseif ($financialStatus === 'paid') {
            return Order::STATUS_PAID;
        } elseif ($financialStatus === 'refunded') {
            return Order::STATUS_REFUNDED;
        } elseif (isset($orderData['cancelled_at']) && $orderData['cancelled_at']) {
            return Order::STATUS_CANCELLED;
        }

        return Order::STATUS_PENDING;
    }

    /**
     * 格式化日期时间
     */
    private function formatDateTime(?string $dateTime): ?string
    {
        if (empty($dateTime)) {
            return null;
        }

        try {
            return date('Y-m-d H:i:s', strtotime($dateTime));
        } catch (\Exception $e) {
            return null;
        }
    }
}
