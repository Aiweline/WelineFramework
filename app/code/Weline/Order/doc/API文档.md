# Weline_Order API 文档

## 基础信息

- **Base URL**: `/weline_api/rest/v1/backend/order`
- **认证**: 需要后端API认证（Bearer Token或Session）
- **响应格式**: JSON

## 通用响应格式

### 成功响应

```json
{
    "msg": "请求成功",
    "data": {...},
    "code": 200
}
```

### 错误响应

```json
{
    "msg": "错误消息",
    "data": "",
    "code": 400
}
```

## API接口列表

### 1. 获取订单列表

**接口**: `GET /weline_api/rest/v1/backend/order/list`

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认1 |
| page_size | int | 否 | 每页数量，默认20，最大100 |
| status | string | 否 | 订单状态筛选 |
| customer_id | int | 否 | 客户ID筛选 |
| order_number | string | 否 | 订单号筛选 |
| payment_status | string | 否 | 支付状态筛选 |
| fulfillment_status | string | 否 | 发货状态筛选 |
| keyword | string | 否 | 关键词搜索（订单号、客户姓名、邮箱） |

**响应示例**:

```json
{
    "msg": "获取订单列表成功",
    "data": {
        "orders": [...],
        "filters": {...}
    },
    "code": 200
}
```

### 2. 获取订单详情

**接口**: `GET /weline_api/rest/v1/backend/order/{id}`

**路径参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 订单ID |

**响应示例**:

```json
{
    "msg": "获取订单详情成功",
    "data": {
        "order": {...},
        "items": [...],
        "payments": [...],
        "shipments": [...],
        "refunds": [...],
        "invoices": [...],
        "history": [...],
        "available_transitions": [...]
    },
    "code": 200
}
```

### 3. 创建订单

**接口**: `POST /weline_api/rest/v1/backend/order/create`

**请求体**:

```json
{
    "customer_id": 1,
    "customer_name": "张三",
    "customer_email": "zhangsan@example.com",
    "customer_phone": "13800138000",
    "items": [
        {
            "product_name": "商品1",
            "product_sku": "SKU001",
            "qty_ordered": 2,
            "price": 100.00
        }
    ],
    "shipping_amount": 10.00,
    "tax_amount": 5.00,
    "discount_amount": 0.00,
    "currency": "CNY"
}
```

**响应示例**:

```json
{
    "msg": "订单创建成功",
    "data": {
        "order_id": 1,
        "order_number": "ORD20240101120000001"
    },
    "code": 201
}
```

### 4. 更新订单

**接口**: `PUT /weline_api/rest/v1/backend/order/{id}`

**路径参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 订单ID |

**请求体**: 同创建订单，但所有字段可选

### 5. 取消订单

**接口**: `POST /weline_api/rest/v1/backend/order/{id}/cancel`

**路径参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 订单ID |

**请求体**:

```json
{
    "reason": "客户要求取消"
}
```

### 6. 更新订单状态

**接口**: `POST /weline_api/rest/v1/backend/order/status`

**请求体**:

```json
{
    "order_id": 1,
    "status": "processing",
    "comment": "订单已确认",
    "notify_customer": false
}
```

### 7. 处理支付

**接口**: `POST /weline_api/rest/v1/backend/order/payment`

**请求体**:

```json
{
    "order_id": 1,
    "amount": 200.00,
    "payment_method": "alipay",
    "currency": "CNY",
    "transaction_id": "TXN123456"
}
```

### 8. 创建发货记录

**接口**: `POST /weline_api/rest/v1/backend/order/shipment`

**请求体**:

```json
{
    "order_id": 1,
    "tracking_number": "SF1234567890",
    "carrier": "顺丰速运"
}
```

### 9. 创建退款

**接口**: `POST /weline_api/rest/v1/backend/order/refund`

**请求体**:

```json
{
    "order_id": 1,
    "amount": 100.00,
    "reason": "商品质量问题"
}
```

### 10. 生成发票

**接口**: `POST /weline_api/rest/v1/backend/order/invoice`

**请求体**:

```json
{
    "order_id": 1
}
```

## 状态码说明

| 状态码 | 说明 |
|--------|------|
| 200 | 请求成功 |
| 201 | 创建成功 |
| 400 | 请求参数错误 |
| 401 | 未授权 |
| 404 | 资源不存在 |
| 500 | 服务器错误 |

## 错误处理

所有错误都会返回统一的错误格式：

```json
{
    "msg": "错误消息",
    "data": "",
    "code": 400
}
```

常见错误：

- `订单ID不能为空` - 缺少必需的订单ID参数
- `订单不存在` - 指定的订单不存在
- `订单当前状态不允许取消` - 订单状态不允许执行该操作
- `订单状态不能从 X 转换到 Y` - 状态转换不符合规则

