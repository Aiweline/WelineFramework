# Weline Checkout API文档

## 概述

本文档介绍 Weline_Checkout 模块提供的所有API接口，包括前端API和后台管理API。

## 前端API

### 1. 创建订单

**URL**: `/weline_checkout/frontend/checkout/create-order`

**方法**: `POST`

**权限**: 需要登录

**请求参数**:
```json
{
    "items": [
        {
            "product_id": 1,
            "product_name": "商品名称",
            "product_sku": "SKU001",
            "quantity": 2,
            "price": 100.00,
            "attributes": {
                "color": "红色",
                "size": "L"
            }
        }
    ],
    "shipping_address": {
        "name": "张三",
        "phone": "13800138000",
        "address": "北京市朝阳区xxx街道xxx号",
        "postal_code": "100000"
    },
    "billing_address": {
        "name": "张三",
        "phone": "13800138000",
        "address": "北京市朝阳区xxx街道xxx号"
    },
    "shipping_method": "standard",
    "shipping_amount": 10.00,
    "tax_amount": 0.00,
    "discount_amount": 0.00,
    "payment_method": "alipay",
    "currency": "CNY",
    "remark": "订单备注"
}
```

**响应示例**:
```json
{
    "success": true,
    "message": "订单创建成功",
    "data": {
        "order_id": 1,
        "order_number": "ORD20250101123456",
        "redirect_url": "/weline_checkout/frontend/checkout/success-page?order_id=1"
    }
}
```

**错误响应**:
```json
{
    "success": false,
    "message": "订单创建失败：客户ID不能为空"
}
```

### 2. 处理支付

**URL**: `/weline_checkout/frontend/checkout/process-payment`

**方法**: `POST`

**权限**: 需要登录

**请求参数**:
```json
{
    "order_id": 1,
    "payment_method": "alipay",
    "payment_data": {
        "gateway_response": {}
    }
}
```

**响应示例**:
```json
{
    "success": true,
    "message": "支付处理成功",
    "data": {
        "transaction_id": 1,
        "order_id": 1,
        "status": "pending"
    }
}
```

### 3. 获取订单列表

**URL**: `/weline_checkout/frontend/order/list`

**方法**: `GET`

**权限**: 需要登录

**请求参数**:
- `page` (可选): 页码，默认1
- `page_size` (可选): 每页数量，默认20

**响应**: 返回HTML页面

### 4. 获取订单详情

**URL**: `/weline_checkout/frontend/order/view`

**方法**: `GET`

**权限**: 需要登录

**请求参数**:
- `order_id` (必需): 订单ID
- `order_number` (可选): 订单号（如果提供order_id则不需要）

**响应**: 返回HTML页面

### 5. 取消订单

**URL**: `/weline_checkout/frontend/order/cancel`

**方法**: `POST`

**权限**: 需要登录

**请求参数**:
```json
{
    "order_id": 1
}
```

**响应示例**:
```json
{
    "success": true,
    "message": "订单已取消"
}
```

## 后台管理API

### 1. 订单列表

**URL**: `/checkout/backend/order/index`

**方法**: `GET`

**权限**: `Weline_Checkout::order_list`

**请求参数**:
- `page` (可选): 页码，默认1
- `limit` (可选): 每页数量，默认20
- `status` (可选): 订单状态筛选
- `payment_status` (可选): 支付状态筛选
- `keyword` (可选): 订单号搜索

**响应**: 返回HTML页面

### 2. 订单详情

**URL**: `/checkout/backend/order/view`

**方法**: `GET`

**权限**: `Weline_Checkout::order_view`

**请求参数**:
- `order_id` (必需): 订单ID
- `order_number` (可选): 订单号

**响应**: 返回HTML页面

### 3. 更新订单状态

**URL**: `/checkout/backend/order/updateStatus`

**方法**: `POST`

**权限**: `Weline_Checkout::order_update_status`

**请求参数**:
```json
{
    "order_id": 1,
    "status": "processing"
}
```

**响应示例**:
```json
{
    "success": true,
    "message": "订单状态更新成功"
}
```

## 服务层API

### CheckoutService

#### validateCheckout()

验证结账数据

```php
public function validateCheckout(array $data): array
```

**参数**:
- `$data`: 订单数据数组

**返回**:
```php
[
    'valid' => true/false,
    'errors' => ['错误1', '错误2']
]
```

#### calculateTotals()

计算订单总额

```php
public function calculateTotals(
    array $items, 
    float $shippingAmount = 0.0, 
    float $taxAmount = 0.0, 
    float $discountAmount = 0.0
): array
```

**参数**:
- `$items`: 订单项数组
- `$shippingAmount`: 运费
- `$taxAmount`: 税费
- `$discountAmount`: 折扣金额

**返回**:
```php
[
    'subtotal' => 250.00,
    'shipping_amount' => 10.00,
    'tax_amount' => 0.00,
    'discount_amount' => 5.00,
    'total_amount' => 255.00
]
```

#### createOrder()

创建订单

```php
public function createOrder(array $data): Order
```

**参数**: 见"创建订单"API说明

**返回**: Order对象

**异常**: 如果数据验证失败或创建失败，抛出Exception

### OrderService

#### getOrder()

根据订单ID获取订单

```php
public function getOrder(int $orderId): ?Order
```

#### getOrderByNumber()

根据订单号获取订单

```php
public function getOrderByNumber(string $orderNumber): ?Order
```

#### updateOrderStatus()

更新订单状态

```php
public function updateOrderStatus(
    int $orderId, 
    string $status, 
    ?string $oldStatus = null
): bool
```

#### cancelOrder()

取消订单

```php
public function cancelOrder(int $orderId): bool
```

#### getCustomerOrders()

获取客户的订单列表

```php
public function getCustomerOrders(
    int $customerId, 
    int $page = 1, 
    int $pageSize = 20
): array
```

### PaymentService

#### validatePayment()

验证支付数据

```php
public function validatePayment(array $data): array
```

#### processPayment()

处理支付

```php
public function processPayment(
    int $orderId, 
    string $paymentMethod, 
    array $paymentData = []
): array
```

**返回**:
```php
[
    'transaction_id' => 1,
    'order_id' => 1,
    'status' => 'pending'
]
```

#### handlePaymentCallback()

处理支付回调

```php
public function handlePaymentCallback(array $callbackData): bool
```

## 数据模型API

### Order模型

#### 基本方法

```php
// 生成订单号
$order->generateOrderNumber(): string

// 获取订单项
$order->getItems(): array

// 检查是否可以取消
$order->canCancel(): bool

// 检查是否已支付
$order->isPaid(): bool

// 检查是否已完成
$order->isCompleted(): bool

// 获取状态文本
$order->getStatusText(): string
$order->getPaymentStatusText(): string
$order->getShippingStatusText(): string

// 获取地址（解析JSON）
$order->getShippingAddressArray(): array
$order->getBillingAddressArray(): array
```

#### 字段常量

```php
Order::fields_ID
Order::fields_ORDER_NUMBER
Order::fields_CUSTOMER_ID
Order::fields_STATUS
Order::fields_TOTAL_AMOUNT
// ... 其他字段
```

#### 状态常量

```php
Order::STATUS_PENDING
Order::STATUS_PROCESSING
Order::STATUS_COMPLETED
Order::STATUS_CANCELLED
Order::STATUS_REFUNDED

Order::PAYMENT_STATUS_PENDING
Order::PAYMENT_STATUS_PAID
Order::PAYMENT_STATUS_FAILED
Order::PAYMENT_STATUS_REFUNDED

Order::SHIPPING_STATUS_PENDING
Order::SHIPPING_STATUS_SHIPPED
Order::SHIPPING_STATUS_DELIVERED
```

### OrderItem模型

#### 基本方法

```php
// 根据订单ID获取订单项
$orderItem->getItemsByOrderId(int $orderId): array

// 计算订单项总价
$orderItem->calculateTotalPrice(): float

// 获取产品属性（解析JSON）
$orderItem->getAttributesArray(): array

// 批量插入订单项
$orderItem->batchInsertItems(array $items): bool

// 删除订单的所有订单项
$orderItem->deleteByOrderId(int $orderId): bool
```

### PaymentTransaction模型

#### 基本方法

```php
// 根据订单ID获取支付交易
$transaction->getTransactionsByOrderId(int $orderId): array

// 检查交易是否成功
$transaction->isSuccess(): bool

// 获取交易状态文本
$transaction->getStatusText(): string

// 获取支付网关响应（解析JSON）
$transaction->getGatewayResponseArray(): array
```

## 错误码说明

| 错误消息 | 说明 | 解决方案 |
|---------|------|---------|
| 客户ID不能为空 | 订单数据中缺少customer_id | 提供有效的客户ID |
| 订单项不能为空 | 订单数据中缺少items或items为空 | 至少添加一个订单项 |
| 订单项 %{1} 的产品ID不能为空 | 某个订单项缺少product_id | 为所有订单项提供product_id |
| 订单项 %{1} 的数量必须大于0 | 某个订单项的数量无效 | 确保数量大于0 |
| 订单项 %{1} 的价格无效 | 某个订单项的价格无效 | 确保价格大于等于0 |
| 收货地址不能为空 | 订单数据中缺少shipping_address | 提供收货地址 |
| 订单不存在 | 指定的订单ID不存在 | 检查订单ID是否正确 |
| 订单已支付 | 订单已经支付，不能重复支付 | 检查订单支付状态 |
| 订单无法取消 | 订单当前状态不允许取消 | 只有pending或processing状态的订单可以取消 |
| 支付方式不能为空 | 支付数据中缺少payment_method | 提供支付方式 |
| 支付金额无效 | 支付金额小于等于0 | 确保支付金额有效 |

## 使用示例

### 完整订单创建流程

```php
use Weline\Checkout\Service\CheckoutService;
use Weline\Checkout\Service\PaymentService;
use Weline\Framework\Manager\ObjectManager;

// 1. 创建订单
$checkoutService = ObjectManager::getInstance(CheckoutService::class);

$orderData = [
    'customer_id' => 1,
    'items' => [
        [
            'product_id' => 1,
            'product_name' => '商品名称',
            'quantity' => 2,
            'price' => 100.00
        ]
    ],
    'shipping_address' => [
        'name' => '张三',
        'phone' => '13800138000',
        'address' => '北京市朝阳区xxx街道xxx号'
    ],
    'payment_method' => 'alipay',
    'currency' => 'CNY'
];

$order = $checkoutService->createOrder($orderData);

// 2. 处理支付
$paymentService = ObjectManager::getInstance(PaymentService::class);

$result = $paymentService->processPayment(
    $order->getId(),
    'alipay',
    []
);

// 3. 查询订单
$orderService = ObjectManager::getInstance(\Weline\Checkout\Service\OrderService::class);
$order = $orderService->getOrder($order->getId());

echo "订单号：" . $order->getOrderNumber();
echo "订单状态：" . $order->getStatusText();
echo "支付状态：" . $order->getPaymentStatusText();
```

## 相关文档

- [使用指南](./使用指南.md)
- [Hook使用指南](./Hook使用指南.md)
- [README](./README.md)
