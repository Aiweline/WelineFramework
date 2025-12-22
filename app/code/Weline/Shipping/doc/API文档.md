# Shipping模块API文档

## 目录

1. [前端API](#前端api)
2. [后端API](#后端api)
3. [数据模型](#数据模型)
4. [错误码说明](#错误码说明)

## 前端API

### 1. 获取可用配送服务

**接口地址**：`/shipping/frontend/shippingservice/getAvailableServices`

**请求方法**：POST

**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| country_code | string | 是 | ISO国家代码（如：CN） |
| province | string | 否 | 省/州 |
| city | string | 否 | 市 |
| district | string | 否 | 区县 |

**请求示例**：

```json
{
  "country_code": "CN",
  "province": "北京",
  "city": "北京市",
  "district": "朝阳区"
}
```

**响应示例**：

```json
{
  "success": true,
  "data": [
    {
      "service_id": 1,
      "service_name": "顺丰快递-华东地区",
      "service_code": "SF_EAST_CHINA",
      "carrier_id": 1,
      "estimated_days_min": 1,
      "estimated_days_max": 3,
      "is_free_shipping": false
    }
  ]
}
```

### 2. 计算配送费用

**接口地址**：`/shipping/frontend/shippingservice/calculateFee`

**请求方法**：POST

**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| service_id | int | 是 | 配送服务ID |
| order_amount | float | 否 | 订单金额（用于免邮判断） |
| weight | float | 否 | 重量（kg） |
| volume | float | 否 | 体积（m³） |
| quantity | int | 否 | 件数 |
| member_level_id | int | 否 | 会员等级ID（用于免邮判断） |
| region_id | int | 否 | 地区ID（用于免邮判断） |
| coupon_code | string | 否 | 优惠券代码（用于免邮判断） |

**请求示例**：

```json
{
  "service_id": 1,
  "order_amount": 150.00,
  "weight": 2.5,
  "volume": 0.1,
  "quantity": 3,
  "member_level_id": 2,
  "region_id": 10,
  "coupon_code": "FREE_SHIP"
}
```

**响应示例**：

```json
{
  "success": true,
  "data": {
    "fee": 0,
    "is_free": true,
    "reason": "free_shipping_rule",
    "rule_name": "订单满100元免邮"
  }
}
```

或

```json
{
  "success": true,
  "data": {
    "fee": 25.50,
    "is_free": false,
    "reason": "calculated"
  }
}
```

### 3. 查询物流跟踪

**接口地址**：`/shipping/frontend/tracking/query`

**请求方法**：POST

**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| tracking_number | string | 是 | 物流单号 |
| carrier_id | int | 是 | 快递公司ID |
| force_refresh | bool | 否 | 是否强制刷新（默认false） |

**请求示例**：

```json
{
  "tracking_number": "1234567890",
  "carrier_id": 1,
  "force_refresh": false
}
```

**响应示例（支持追踪）**：

```json
{
  "success": true,
  "tracking_number": "1234567890",
  "carrier": {
    "code": "SF",
    "name": "顺丰速运"
  },
  "status": "in_transit",
  "current_location": "北京分拨中心",
  "estimated_delivery_date": "2024-01-15 18:00:00",
  "nodes": [
    {
      "time": "2024-01-10 10:00:00",
      "location": "北京分拨中心",
      "status": "已发货",
      "description": "快件已从北京分拨中心发出",
      "type": "pickup"
    },
    {
      "time": "2024-01-12 14:30:00",
      "location": "上海分拨中心",
      "status": "运输中",
      "description": "快件已到达上海分拨中心",
      "type": "transit"
    }
  ],
  "tracking_url": "https://www.sf-express.com/track/1234567890"
}
```

**响应示例（不支持追踪）**：

```json
{
  "success": false,
  "tracking_number": "1234567890",
  "carrier": {
    "code": "CUSTOM",
    "name": "自定义快递"
  },
  "status": "not_supported",
  "message": "该快递公司暂不支持在线追踪，请联系客服查询",
  "tracking_url": "https://example.com/track/1234567890",
  "support_contact": "客服电话：400-xxx-xxxx"
}
```

### 4. 根据订单获取物流单号

**接口地址**：`/shipping/frontend/tracking/getTrackingNumber`

**请求方法**：POST

**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| order_id | int | 是 | 订单ID |

**请求示例**：

```json
{
  "order_id": 123
}
```

**响应示例**：

```json
{
  "success": true,
  "data": {
    "tracking_number": "1234567890",
    "carrier_id": 1,
    "carrier_code": "SF",
    "carrier_name": "顺丰速运"
  }
}
```

## 后端API

所有后端API都需要管理员权限，通过ACL控制访问。

### 地区管理

**路由**：`shipping/backend/region`

- `index` - 地区列表（树形结构）
- `edit` - 编辑地区
- `save` - 保存地区
- `delete` - 删除地区
- `syncFromI18n` - 从i18n同步国家数据

### 配送区域管理

**路由**：`shipping/backend/zone`

- `index` - 区域列表
- `edit` - 编辑区域
- `save` - 保存区域
- `delete` - 删除区域
- `regions` - 管理区域地区关联

### 快递公司管理

**路由**：`shipping/backend/carrier`

- `index` - 快递公司列表
- `edit` - 编辑快递公司
- `save` - 保存快递公司
- `delete` - 删除快递公司

### 费用模板管理

**路由**：`shipping/backend/ratetemplate`

- `index` - 模板列表
- `edit` - 编辑模板
- `save` - 保存模板
- `delete` - 删除模板

### 免邮规则管理

**路由**：`shipping/backend/freeshippingrule`

- `index` - 规则列表
- `edit` - 编辑规则
- `save` - 保存规则
- `delete` - 删除规则

### 配送服务管理

**路由**：`shipping/backend/shippingservice`

- `index` - 服务列表
- `edit` - 编辑服务
- `save` - 保存服务
- `delete` - 删除服务

### 物流跟踪管理

**路由**：`shipping/backend/tracking`

- `index` - 跟踪记录列表
- `detail` - 跟踪详情
- `query` - 批量查询
- `update` - 手动更新跟踪信息

## 数据模型

### Region（地区）

| 字段 | 类型 | 说明 |
|------|------|------|
| region_id | int | 地区ID |
| country_code | string | ISO国家代码 |
| parent_region_id | int | 父级地区ID |
| region_code | string | 地区代码 |
| region_name | string | 地区名称 |
| region_type | string | 地区类型：country/province/city/district |
| postal_code_pattern | string | 邮政编码格式（正则） |
| is_active | int | 是否启用 |
| sort_order | int | 排序 |

### Zone（配送区域）

| 字段 | 类型 | 说明 |
|------|------|------|
| zone_id | int | 区域ID |
| zone_name | string | 区域名称 |
| zone_code | string | 区域代码（唯一） |
| description | text | 描述 |
| is_active | int | 是否启用 |
| sort_order | int | 排序 |

### Carrier（快递公司）

| 字段 | 类型 | 说明 |
|------|------|------|
| carrier_id | int | 快递公司ID |
| carrier_code | string | 快递公司代码（唯一） |
| carrier_name | string | 快递公司名称 |
| carrier_type | string | 类型：manual/api |
| api_config | text | API配置（JSON） |
| tracking_url_template | string | 追踪URL模板（必填） |
| tracking_api_endpoint | string | 追踪API端点 |
| tracking_api_method | string | API请求方法：GET/POST |
| tracking_support_status | string | 追踪支持状态 |
| is_active | int | 是否启用 |
| sort_order | int | 排序 |

### RateTemplate（费用模板）

| 字段 | 类型 | 说明 |
|------|------|------|
| template_id | int | 模板ID |
| template_name | string | 模板名称 |
| template_code | string | 模板代码（唯一） |
| calculation_type | string | 计算类型 |
| base_fee | decimal | 基础费用 |
| weight_unit | string | 重量单位 |
| weight_rate | decimal | 重量费率 |
| volume_unit | string | 体积单位 |
| volume_rate | decimal | 体积费率 |
| quantity_rate | decimal | 件数费率 |
| mixed_config | text | 混合模式配置（JSON） |
| currency_code | string | 货币代码 |
| is_active | int | 是否启用 |

### FreeShippingRule（免邮规则）

| 字段 | 类型 | 说明 |
|------|------|------|
| rule_id | int | 规则ID |
| rule_name | string | 规则名称 |
| rule_code | string | 规则代码（唯一） |
| condition_type | string | 条件类型 |
| min_order_amount | decimal | 最小订单金额 |
| member_level_ids | text | 会员等级ID列表（JSON） |
| region_ids | text | 地区ID列表（JSON） |
| coupon_codes | text | 优惠券代码列表（JSON） |
| mixed_config | text | 混合条件配置（JSON） |
| is_active | int | 是否启用 |
| priority | int | 优先级 |

### ShippingService（配送服务）

| 字段 | 类型 | 说明 |
|------|------|------|
| service_id | int | 服务ID |
| service_name | string | 服务名称 |
| service_code | string | 服务代码（唯一） |
| carrier_id | int | 快递公司ID |
| zone_id | int | 配送区域ID |
| rate_template_id | int | 费用模板ID |
| free_shipping_rule_id | int | 免邮规则ID |
| estimated_days_min | int | 预计配送天数（最小） |
| estimated_days_max | int | 预计配送天数（最大） |
| is_free_shipping | int | 是否免邮 |
| is_active | int | 是否启用 |
| sort_order | int | 排序 |

### Tracking（物流跟踪）

| 字段 | 类型 | 说明 |
|------|------|------|
| tracking_id | int | 跟踪记录ID |
| tracking_number | string | 物流单号 |
| carrier_id | int | 快递公司ID |
| order_id | int | 订单ID |
| customer_id | int | 客户ID |
| status | string | 物流状态 |
| current_location | string | 当前位置 |
| estimated_delivery_date | datetime | 预计送达时间 |
| actual_delivery_date | datetime | 实际送达时间 |
| tracking_data | text | 详细跟踪数据（JSON） |
| last_tracked_at | datetime | 最后跟踪时间 |
| tracking_count | int | 跟踪次数 |

### TrackingNode（跟踪节点）

| 字段 | 类型 | 说明 |
|------|------|------|
| node_id | int | 节点ID |
| tracking_id | int | 跟踪记录ID |
| node_time | datetime | 节点时间 |
| node_location | string | 节点位置 |
| node_status | string | 节点状态描述 |
| node_description | text | 节点详细描述 |
| node_type | string | 节点类型 |
| sort_order | int | 排序 |

## 错误码说明

### 通用错误

| 错误消息 | 说明 |
|----------|------|
| 参数错误 | 请求参数不正确 |
| 地址不存在 | 指定的地址不存在 |
| 快递公司不存在 | 指定的快递公司不存在 |
| 配送服务不存在 | 指定的配送服务不存在 |
| 费用模板不存在 | 指定的费用模板不存在 |

### 业务错误

| 错误消息 | 说明 |
|----------|------|
| 物流跟踪URL模板为必填项，所有快递公司必须支持追踪功能 | 创建快递公司时必须配置追踪URL模板 |
| 客户ID不能为空 | 创建运送地址时必须指定客户ID |
| 无权操作此地址 | 用户无权操作该地址 |
| 国家代码不能为空 | 查询配送服务时必须提供国家代码 |
| 物流单号不能为空 | 查询物流跟踪时必须提供物流单号 |
| 快递公司ID不能为空 | 查询物流跟踪时必须提供快递公司ID |

### 验证错误

| 错误消息 | 说明 |
|----------|------|
| %{1}不能为空 | 必填字段为空 |
| 电话号码格式不正确 | 电话号码格式验证失败 |
| 邮政编码格式不正确，应为6位数字 | 邮政编码格式验证失败 |

## 使用示例

### PHP代码示例

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingServiceManager;

// 获取配送服务管理器
$shippingManager = ObjectManager::getInstance(ShippingServiceManager::class);

// 获取可用配送服务
$services = $shippingManager->getAvailableServices(
    'CN',      // 国家代码
    '北京',     // 省
    '北京市',   // 市
    '朝阳区'    // 区县
);

// 计算配送费用
$feeResult = $shippingManager->calculateShippingFee(
    1,          // 服务ID
    150.00,     // 订单金额
    2.5,        // 重量
    0.1,        // 体积
    3,          // 件数
    2,          // 会员等级ID
    10,         // 地区ID
    'FREE_SHIP' // 优惠券代码
);
```

### JavaScript代码示例

```javascript
// 获取可用配送服务
async function getAvailableServices(countryCode, province, city, district) {
    const response = await fetch('/shipping/frontend/shippingservice/getAvailableServices', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            country_code: countryCode,
            province: province,
            city: city,
            district: district
        })
    });
    return await response.json();
}

// 计算配送费用
async function calculateFee(serviceId, orderAmount, weight, volume, quantity) {
    const response = await fetch('/shipping/frontend/shippingservice/calculateFee', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            service_id: serviceId,
            order_amount: orderAmount,
            weight: weight,
            volume: volume,
            quantity: quantity
        })
    });
    return await response.json();
}

// 查询物流跟踪
async function queryTracking(trackingNumber, carrierId) {
    const response = await fetch('/shipping/frontend/tracking/query', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            tracking_number: trackingNumber,
            carrier_id: carrierId,
            force_refresh: false
        })
    });
    return await response.json();
}
```

## 注意事项

1. **所有API都需要正确的权限**：前端API需要用户登录，后端API需要管理员权限
2. **参数验证**：所有必填参数必须提供，否则返回错误
3. **数据格式**：JSON格式，使用UTF-8编码
4. **错误处理**：所有API都返回统一的JSON格式，包含success字段
5. **缓存机制**：物流跟踪查询有1小时缓存，使用force_refresh=true强制刷新

