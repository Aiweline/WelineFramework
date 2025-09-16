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
    
    // 当前处理的店铺和订单信息
    private int $currentShopId = 0;
    private string $currentOrderNumber = '';

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
        try {
            if (!$this->shopifyApi->testConnection()) {
                throw new \Exception('无法连接到Shopify API，请检查店铺配置');
            }
        } catch (\Exception $e) {
            // 提供更详细的错误信息
            $errorMsg = '无法连接到Shopify API，请检查店铺配置: ' . $e->getMessage();
            $errorMsg .= "\n店铺信息:";
            $errorMsg .= "\n- 店铺名称: " . $shop['shop_name'];
            $errorMsg .= "\n- 店铺URL: " . $shop['shop_url'];
            $errorMsg .= "\n- 访问令牌: " . (empty($shop['access_token']) ? '未设置' : '已设置');
            $errorMsg .= "\n- 状态: " . ($shop['status'] == 1 ? '启用' : '禁用');
            
            throw new \Exception($errorMsg);
        }

        // 只同步近一个月的订单
        $oneMonthAgo = date('Y-m-d H:i:s', strtotime('-1 month'));
        $lastSyncTime = $shop['last_sync_time'] ?: $oneMonthAgo;

        // 获取近一个月的所有订单
        $ordersData = $this->shopifyApi->getOrdersByDateRange($oneMonthAgo);

        // 记录同步信息
        $syncInfo = [
            'shop_id' => $shop['shop_id'],
            'shop_name' => $shop['shop_name'],
            'sync_from_date' => $oneMonthAgo,
            'total_fetched' => count($ordersData['orders'] ?? [])
        ];

        if (empty($ordersData['orders'])) {
            // 更新同步时间
            $this->shopModel->updateLastSyncTime($shop['shop_id']);
            
            return array_merge($syncInfo, [
                'success' => true,
                'total_orders' => 0,
                'new_orders' => 0,
                'updated_orders' => 0,
                'message' => '没有新订单需要同步'
            ]);
        }

        $totalOrders = count($ordersData['orders']);
        $newOrders = 0;
        $updatedOrders = 0;

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

            return array_merge($syncInfo, [
                'success' => true,
                'total_orders' => $totalOrders,
                'new_orders' => $newOrders,
                'updated_orders' => $updatedOrders,
                'message' => "成功同步 {$totalOrders} 个订单，新增 {$newOrders} 个，更新 {$updatedOrders} 个"
            ]);

        } catch (\Exception $e) {
            // 发送飞书错误通知
            $this->feishuNotify->sendErrorNotify(
                '订单处理异常',
                "处理店铺订单时发生异常",
                [
                    '店铺ID' => $shop['shop_id'],
                    '店铺名称' => $shop['shop_name'],
                    '错误信息' => $e->getMessage(),
                    '错误文件' => $e->getFile(),
                    '错误行号' => $e->getLine()
                ]
            );
            
            throw $e;
        }
    }

    /**
     * 处理单个订单
     */
    private function processOrder(int $shopId, array $orderData): bool
    {
        try {
            $shopifyOrderId = $orderData['id'];
            
            // 设置当前处理的店铺和订单信息
            $this->currentShopId = $shopId;
            $this->currentOrderNumber = $orderData['order_number'] ?? '';
            
            // 检查订单是否已存在
            $existingOrder = $this->orderModel
                ->where(Order::fields_SHOP_ID, $shopId)
                ->where(Order::fields_SHOPIFY_ORDER_ID, $shopifyOrderId)
                ->find()
                ->fetch();

            $isNewOrder = !$existingOrder->getId();

        // 准备订单数据
        $orderInsertData = [
            Order::fields_PLATFORM => 'shopify',
            Order::fields_SHOP_ID => $shopId,
            Order::fields_SHOPIFY_ORDER_ID => $shopifyOrderId,
            Order::fields_ORDER_NUMBER => $orderData['order_number'] ?? '',
            Order::fields_NAME => $orderData['name'] ?? '',
            Order::fields_CUSTOMER_EMAIL => $orderData['email'] ?? '',
            Order::fields_CUSTOMER_NAME => $this->getCustomerName($orderData),
            Order::fields_CUSTOMER_PHONE => $this->getCustomerPhone($orderData),
            Order::fields_TOTAL_PRICE => floatval($orderData['total_price'] ?? 0),
            Order::fields_SUBTOTAL_PRICE => floatval($orderData['subtotal_price'] ?? 0),
            Order::fields_TOTAL_TAX => floatval($orderData['total_tax'] ?? 0),
            Order::fields_TOTAL_DISCOUNTS => floatval($orderData['total_discounts'] ?? 0),
            Order::fields_TOTAL_SHIPPING_PRICE => $this->getShippingPrice($orderData),
            Order::fields_CURRENCY => $orderData['currency'] ?? 'USD',
            Order::fields_GATEWAY => $this->getPaymentGateway($orderData),
            Order::fields_PAYMENT_METHOD_NAME => $this->getPaymentMethodName($orderData),
            Order::fields_PAYMENT_METHOD_TYPE => $this->getPaymentMethodType($orderData),
            Order::fields_PAYMENT_GATEWAY_NAMES => $this->formatPaymentGatewayNames($orderData['payment_gateway_names'] ?? []),
            Order::fields_TRANSACTIONS => json_encode($orderData['transactions'] ?? []),
            Order::fields_TEST => isset($orderData['test']) && $orderData['test'] ? 1 : 0,
            Order::fields_FINANCIAL_STATUS => $orderData['financial_status'] ?? '',
            Order::fields_FULFILLMENT_STATUS => $orderData['fulfillment_status'] ?? Order::FULFILLMENT_PENDING,
            Order::fields_ORDER_STATUS => $this->mapOrderStatus($orderData),
            Order::fields_TAGS => $orderData['tags'] ?? '',
            Order::fields_NOTE => $orderData['note'] ?? '',
            Order::fields_SHIPPING_ADDRESS => json_encode($orderData['shipping_address'] ?? []),
            Order::fields_BILLING_ADDRESS => json_encode($orderData['billing_address'] ?? []),
            Order::fields_CUSTOMER => json_encode($orderData['customer'] ?? []),
            Order::fields_RAW_DATA => json_encode($orderData),
            // Order::fields_PROCESSED_AT => $this->formatDateTime($orderData['processed_at'] ?? null), // 暂时注释掉
            Order::fields_CANCELLED_AT => $this->formatDateTime($orderData['cancelled_at'] ?? null),
            Order::fields_CLOSED_AT => $this->formatDateTime($orderData['closed_at'] ?? null),
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
        }

        // 处理订单项目（先删除旧的，再插入新的，确保不重复）
        if (!empty($orderData['line_items'])) {
            try {
                $this->processOrderItems($orderId, $orderData['line_items']);
            } catch (\Exception $e) {
                // 记录商品同步错误，但不影响订单同步
                error_log("商品同步失败 - 订单ID: {$orderId}, 错误: " . $e->getMessage());
            }
        }

        return $isNewOrder;
            
        } catch (\Exception $e) {
            // 发送飞书错误通知
            $this->feishuNotify->sendErrorNotify(
                '单个订单处理异常',
                "处理订单时发生异常",
                [
                    '店铺ID' => $shopId,
                    '订单ID' => $orderData['id'] ?? '未知',
                    '订单号' => $orderData['order_number'] ?? '未知',
                    '错误信息' => $e->getMessage(),
                    '错误文件' => $e->getFile(),
                    '错误行号' => $e->getLine()
                ]
            );
            
            // 重新抛出异常，让上层处理
            throw $e;
        }
    }

    /**
     * 处理订单项目
     */
    private function processOrderItems(int $orderId, array $lineItems): void
    {
        $shopId = $this->getCurrentShopId();
        
        foreach ($lineItems as $item) {
            // 检查是否已存在相同的商品项目 - 使用shop_id和shopify_line_item_id组合确保唯一性
            $existingItem = $this->checkDuplicateOrderItem($shopId, $item['id'] ?? '');
            
            // 计算税费信息
            $taxInfo = $this->calculateTaxInfo($item);
            
            // 计算真实销售价格（基于您提供的逻辑）
            $priceInfo = $this->calculateRealPrice($item);
            
            $itemData = [
                OrderItem::fields_PLATFORM => 'shopify',
                OrderItem::fields_ORDER_ID => $orderId,
                OrderItem::fields_ORDER_REF_ID => $orderId, // 使用相同的订单ID
                OrderItem::fields_SHOP_ID => $shopId,
                OrderItem::fields_ORDER_NUMBER => $this->getCurrentOrderNumber(),
                OrderItem::fields_SHOPIFY_ITEM_ID => $item['id'] ?? null,
                OrderItem::fields_SHOPIFY_PRODUCT_ID => $item['product_id'] ?? null,
                OrderItem::fields_SHOPIFY_VARIANT_ID => $item['variant_id'] ?? null,
                OrderItem::fields_SKU => $item['sku'] ?? '',
                OrderItem::fields_PRODUCT_TITLE => $item['title'] ?? '',
                OrderItem::fields_VARIANT_TITLE => $item['variant_title'] ?? '',
                OrderItem::fields_VENDOR => $item['vendor'] ?? '',
                OrderItem::fields_PRODUCT_TYPE => $item['product_type'] ?? '',
                OrderItem::fields_QUANTITY => intval($item['quantity'] ?? 1),
                OrderItem::fields_PRICE => $priceInfo['real_unit_price'], // 真实销售单价
                OrderItem::fields_ORIGINAL_PRICE => $priceInfo['original_unit_price'], // 原始单价
                OrderItem::fields_TOTAL_DISCOUNT => $priceInfo['total_discount'],
                OrderItem::fields_TOTAL_TAX => $taxInfo['total_tax'],
                OrderItem::fields_UNIT_TAX => $taxInfo['unit_tax'],
                OrderItem::fields_FULFILLABLE_QUANTITY => intval($item['fulfillable_quantity'] ?? 0),
                OrderItem::fields_FULFILLMENT_STATUS => $item['fulfillment_status'] ?? '',
                OrderItem::fields_FULFILLMENT_SERVICE => $item['fulfillment_service'] ?? '',
                OrderItem::fields_REQUIRES_SHIPPING => $item['requires_shipping'] ? 1 : 0,
                OrderItem::fields_TAXABLE => $item['taxable'] ? 1 : 0,
                OrderItem::fields_GIFT_CARD => $item['gift_card'] ? 1 : 0,
                OrderItem::fields_PROPERTIES => json_encode($item['properties'] ?? []),
                OrderItem::fields_TAX_LINES => json_encode($item['tax_lines'] ?? []),
                OrderItem::fields_DISCOUNT_ALLOCATIONS => json_encode($item['discount_allocations'] ?? []),
                OrderItem::fields_RAW_DATA => json_encode($item)
            ];
            
            if ($existingItem->getId()) {
                // 更新现有商品项目
                $existingItem->setData($itemData);
                $existingItem->save();
                error_log("更新订单项目 - 店铺ID: {$shopId}, Shopify项目ID: {$item['id']}, 订单ID: {$orderId}");
            } else {
                // 插入新商品项目
                $newItem = new \FlashForge\ShopifyOrderManager\Model\OrderItem();
                $newItem->setData($itemData);
                $newItem->save();
                error_log("新增订单项目 - 店铺ID: {$shopId}, Shopify项目ID: {$item['id']}, 订单ID: {$orderId}");
            }
        }
    }

    /**
     * 检查订单项目是否重复
     * 使用shop_id和shopify_line_item_id组合确保唯一性
     */
    private function checkDuplicateOrderItem(int $shopId, string $shopifyLineItemId): ?\FlashForge\ShopifyOrderManager\Model\OrderItem
    {
        return $this->orderItemModel
            ->where('shop_id', $shopId)
            ->where('shopify_item_id', $shopifyLineItemId)
            ->find()
            ->fetch();
    }

    /**
     * 计算真实销售价格（基于Shopify API数据）
     * 参考您提供的导出脚本逻辑
     */
    private function calculateRealPrice(array $item): array
    {
        $qty = intval($item['quantity'] ?? 1);
        
        // 统计折扣分配金额（整行）
        $alloc = 0.0;
        if (!empty($item['discount_allocations']) && is_array($item['discount_allocations'])) {
            foreach ($item['discount_allocations'] as $da) {
                $alloc += floatval($da['amount'] ?? 0);
            }
        }
        
        // 如果discount_allocations为空，使用total_discount字段
        if ($alloc == 0) {
            $alloc = floatval($item['total_discount'] ?? 0);
        }
        
        // 获取Shopify API中的价格字段
        $shopifyPrice = floatval($item['price'] ?? 0);
        
        // 计算原始单价和真实销售单价
        $originalUnit = 0.0;
        $realUnitPrice = 0.0;
        
        if ($alloc > 0) {
            // 有折扣的情况：Shopify的price字段是原始价格，需要减去折扣
            $originalUnit = $shopifyPrice;
            $realUnitPrice = $shopifyPrice - ($alloc / $qty);
        } else {
            // 无折扣的情况：原始价格和销售价格相同
            $originalUnit = $shopifyPrice;
            $realUnitPrice = $shopifyPrice;
        }
        
        
        return [
            'original_unit_price' => $originalUnit,
            'real_unit_price' => $realUnitPrice,
            'total_discount' => $alloc,
            'unit_discount' => $qty > 0 ? $alloc / $qty : 0
        ];
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

    /**
     * 获取客户电话
     */
    private function getCustomerPhone(array $orderData): string
    {
        if (!empty($orderData['customer'])) {
            return $orderData['customer']['phone'] ?? '';
        }
        
        if (!empty($orderData['shipping_address'])) {
            return $orderData['shipping_address']['phone'] ?? '';
        }
        
        return '';
    }

    /**
     * 获取运费
     */
    private function getShippingPrice(array $orderData): float
    {
        if (!empty($orderData['shipping_lines'])) {
            $totalShipping = 0;
            foreach ($orderData['shipping_lines'] as $shipping) {
                $totalShipping += floatval($shipping['price'] ?? 0);
            }
            return $totalShipping;
        }
        
        return 0;
    }

    /**
     * 计算税费信息
     */
    private function calculateTaxInfo(array $item): array
    {
        $quantity = intval($item['quantity'] ?? 1);
        $totalTax = 0;
        
        // 从tax_lines中计算总税费
        if (!empty($item['tax_lines'])) {
            foreach ($item['tax_lines'] as $taxLine) {
                $totalTax += floatval($taxLine['price'] ?? 0);
            }
        }
        
        // 计算单件税费
        $unitTax = $quantity > 0 ? $totalTax / $quantity : 0;
        
        return [
            'total_tax' => $totalTax,
            'unit_tax' => $unitTax
        ];
    }

    /**
     * 获取当前店铺ID（用于订单项目）
     */
    private function getCurrentShopId(): int
    {
        // 这个方法需要在processOrder方法中设置当前店铺ID
        return $this->currentShopId ?? 0;
    }

    /**
     * 获取当前订单号（用于订单项目）
     */
    private function getCurrentOrderNumber(): string
    {
        // 这个方法需要在processOrder方法中设置当前订单号
        return $this->currentOrderNumber ?? '';
    }

    /**
     * 获取支付网关信息（支付方式）
     */
    private function getPaymentGateway(array $orderData): string
    {
        // 尝试从多个可能的字段获取支付方式信息
        $gateway = $orderData['gateway'] ?? '';
        
        // 如果没有gateway字段，尝试从payment_gateway_names获取
        if (empty($gateway) && !empty($orderData['payment_gateway_names'])) {
            $gateway = is_array($orderData['payment_gateway_names']) 
                ? implode(', ', $orderData['payment_gateway_names'])
                : $orderData['payment_gateway_names'];
        }
        
        // 如果还是没有，尝试从transactions中获取
        if (empty($gateway) && !empty($orderData['transactions'])) {
            $transactions = $orderData['transactions'];
            if (is_array($transactions) && !empty($transactions)) {
                $gateways = [];
                foreach ($transactions as $transaction) {
                    if (!empty($transaction['gateway'])) {
                        $gateways[] = $transaction['gateway'];
                    }
                }
                if (!empty($gateways)) {
                    $gateway = implode(', ', array_unique($gateways));
                }
            }
        }
        
        // 如果仍然没有找到具体的支付方式，返回空字符串
        // 不要用支付状态来推断支付方式，这是两个不同的概念
        if (empty($gateway)) {
            $gateway = ''; // 保持为空，表示支付方式未知
        }
        
        return $gateway;
    }

    /**
     * 获取支付方式名称
     */
    private function getPaymentMethodName(array $orderData): string
    {
        // 首先尝试从API单独获取交易信息
        $transactions = $this->getOrderTransactionsFromApi($orderData['id']);
        
        if (!empty($transactions)) {
            foreach ($transactions as $transaction) {
                // 查找成功的交易
                if (isset($transaction['status']) && $transaction['status'] === 'success') {
                    // 从payment_details中获取支付方式名称
                    if (!empty($transaction['payment_details'])) {
                        $paymentDetails = $transaction['payment_details'];
                        if (isset($paymentDetails['payment_method_name'])) {
                            return $paymentDetails['payment_method_name'];
                        }
                    }
                    
                    // 从gateway字段推断支付方式名称
                    if (!empty($transaction['gateway'])) {
                        return $this->mapGatewayToPaymentMethodName($transaction['gateway']);
                    }
                }
            }
        }
        
        // 如果API获取失败，尝试从订单数据中获取
        if (!empty($orderData['transactions'])) {
            $transactions = $orderData['transactions'];
            if (is_array($transactions) && !empty($transactions)) {
                foreach ($transactions as $transaction) {
                    // 查找成功的交易
                    if (isset($transaction['status']) && $transaction['status'] === 'success') {
                        // 从payment_details中获取支付方式名称
                        if (!empty($transaction['payment_details'])) {
                            $paymentDetails = $transaction['payment_details'];
                            if (isset($paymentDetails['payment_method_name'])) {
                                return $paymentDetails['payment_method_name'];
                            }
                        }
                        
                        // 从gateway字段推断支付方式名称
                        if (!empty($transaction['gateway'])) {
                            return $this->mapGatewayToPaymentMethodName($transaction['gateway']);
                        }
                    }
                }
            }
        }
        
        // 如果transactions中没有找到，尝试从gateway字段推断
        if (!empty($orderData['gateway'])) {
            return $this->mapGatewayToPaymentMethodName($orderData['gateway']);
        }
        
        // 如果还是没有，尝试从payment_gateway_names推断
        if (!empty($orderData['payment_gateway_names'])) {
            $gatewayNames = is_array($orderData['payment_gateway_names']) 
                ? $orderData['payment_gateway_names'] 
                : [$orderData['payment_gateway_names']];
            
            if (!empty($gatewayNames)) {
                return $this->mapGatewayToPaymentMethodName($gatewayNames[0]);
            }
        }
        
        return '';
    }

    /**
     * 从API单独获取订单交易信息
     */
    private function getOrderTransactionsFromApi(string $orderId): array
    {
        try {
            $transactionsData = $this->shopifyApi->getOrderTransactions($orderId);
            return $transactionsData['transactions'] ?? [];
        } catch (\Exception $e) {
            // 如果获取失败，返回空数组
            return [];
        }
    }

    /**
     * 获取支付方式类型
     */
    private function getPaymentMethodType(array $orderData): string
    {
        // 优先从transactions中获取支付方式类型
        if (!empty($orderData['transactions'])) {
            $transactions = $orderData['transactions'];
            if (is_array($transactions) && !empty($transactions)) {
                foreach ($transactions as $transaction) {
                    // 查找成功的交易
                    if (isset($transaction['status']) && $transaction['status'] === 'success') {
                        // 从payment_details中获取支付方式类型
                        if (!empty($transaction['payment_details'])) {
                            $paymentDetails = $transaction['payment_details'];
                            if (isset($paymentDetails['payment_method_name'])) {
                                return $this->mapPaymentMethodNameToType($paymentDetails['payment_method_name']);
                            }
                        }
                        
                        // 从gateway字段推断支付方式类型
                        if (!empty($transaction['gateway'])) {
                            return $this->mapGatewayToPaymentMethodType($transaction['gateway']);
                        }
                    }
                }
            }
        }
        
        // 如果transactions中没有找到，尝试从gateway字段推断
        if (!empty($orderData['gateway'])) {
            return $this->mapGatewayToPaymentMethodType($orderData['gateway']);
        }
        
        // 如果还是没有，尝试从payment_gateway_names推断
        if (!empty($orderData['payment_gateway_names'])) {
            $gatewayNames = is_array($orderData['payment_gateway_names']) 
                ? $orderData['payment_gateway_names'] 
                : [$orderData['payment_gateway_names']];
            
            if (!empty($gatewayNames)) {
                return $this->mapGatewayToPaymentMethodType($gatewayNames[0]);
            }
        }
        
        return '';
    }

    /**
     * 将网关名称映射为支付方式名称
     */
    private function mapGatewayToPaymentMethodName(string $gateway): string
    {
        $gateway = strtolower($gateway);
        
        $mapping = [
            'shopify_payments' => 'Shopify Payments',
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'shop_pay' => 'Shop Pay',
            'amazon_pay' => 'Amazon Pay',
            'klarna' => 'Klarna',
            'afterpay' => 'Afterpay',
            'sezzle' => 'Sezzle',
            'affirm' => 'Affirm',
            'zip' => 'Zip',
            'manual' => 'Manual Payment',
            'cash_on_delivery' => 'Cash on Delivery',
            'bank_transfer' => 'Bank Transfer',
            'check' => 'Check',
            'money_order' => 'Money Order',
            'bitcoin' => 'Bitcoin',
            'cryptocurrency' => 'Cryptocurrency',
        ];
        
        return $mapping[$gateway] ?? ucfirst(str_replace('_', ' ', $gateway));
    }

    /**
     * 将网关名称映射为支付方式类型
     */
    private function mapGatewayToPaymentMethodType(string $gateway): string
    {
        $gateway = strtolower($gateway);
        
        $mapping = [
            'shopify_payments' => 'credit_card',
            'stripe' => 'credit_card',
            'paypal' => 'digital_wallet',
            'apple_pay' => 'digital_wallet',
            'google_pay' => 'digital_wallet',
            'shop_pay' => 'digital_wallet',
            'amazon_pay' => 'digital_wallet',
            'klarna' => 'buy_now_pay_later',
            'afterpay' => 'buy_now_pay_later',
            'sezzle' => 'buy_now_pay_later',
            'affirm' => 'buy_now_pay_later',
            'zip' => 'buy_now_pay_later',
            'manual' => 'manual',
            'cash_on_delivery' => 'cash_on_delivery',
            'bank_transfer' => 'bank_transfer',
            'check' => 'check',
            'money_order' => 'money_order',
            'bitcoin' => 'cryptocurrency',
            'cryptocurrency' => 'cryptocurrency',
        ];
        
        return $mapping[$gateway] ?? 'other';
    }

    /**
     * 格式化支付网关名称为逗号分隔的字符串
     */
    private function formatPaymentGatewayNames(array $gatewayNames): string
    {
        if (empty($gatewayNames)) {
            return '';
        }
        
        // 过滤空值并去重
        $gatewayNames = array_filter(array_unique($gatewayNames));
        
        // 用英文逗号分隔
        return implode(', ', $gatewayNames);
    }

    /**
     * 将支付方式名称映射为支付方式类型
     */
    private function mapPaymentMethodNameToType(string $paymentMethodName): string
    {
        $name = strtolower($paymentMethodName);
        
        // 信用卡类型
        if (strpos($name, 'visa') !== false || strpos($name, 'mastercard') !== false || 
            strpos($name, 'amex') !== false || strpos($name, 'discover') !== false ||
            strpos($name, 'jcb') !== false || strpos($name, 'diners') !== false) {
            return 'credit_card';
        }
        
        // 数字钱包类型
        if (strpos($name, 'paypal') !== false || strpos($name, 'apple pay') !== false || 
            strpos($name, 'google pay') !== false || strpos($name, 'shop pay') !== false ||
            strpos($name, 'amazon pay') !== false) {
            return 'digital_wallet';
        }
        
        // 先买后付类型
        if (strpos($name, 'klarna') !== false || strpos($name, 'afterpay') !== false || 
            strpos($name, 'sezzle') !== false || strpos($name, 'affirm') !== false ||
            strpos($name, 'zip') !== false) {
            return 'buy_now_pay_later';
        }
        
        // 加密货币类型
        if (strpos($name, 'bitcoin') !== false || strpos($name, 'crypto') !== false) {
            return 'cryptocurrency';
        }
        
        // 其他类型
        return 'other';
    }
}
