# Weline_Payment 支付管理模块

## 模块概述

Weline_Payment 是支付管理核心模块，提供统一的支付接口标准，支持第三方支付供应商通过模块扩展机制接入，前端统一展示到结账界面。

## 主要功能

1. **支付方式管理**: 管理所有支付方式，包括启用/禁用、配置等
2. **支付交易管理**: 记录和管理所有支付交易
3. **支付提供商扩展**: 支持第三方支付供应商通过扩展机制接入
4. **国际化支持**: 完整的中英文翻译支持
5. **Hook系统集成**: 在结账界面提供多个Hook点，支持功能扩展

## 核心接口

### PaymentProviderInterface

所有支付提供商必须实现 `Weline\Payment\Interface\PaymentProviderInterface` 接口。

主要方法：
- `getCode()`: 获取支付方式代码
- `getName()`: 获取支付方式名称
- `createPayment()`: 创建支付订单
- `handleCallback()`: 处理支付回调
- `queryPaymentStatus()`: 查询支付状态
- `refund()`: 处理退款
- `verifySignature()`: 验证签名
- `getConfigFields()`: 获取配置表单字段
- `getSupportedDiscountActions()`: 获取支持的优惠方式代码列表（新增）
- `supportsDiscountAction()`: 检查是否支持特定优惠方式（新增）

## 扩展机制

其他支付供应商可以通过以下方式开发支付模块：

1. 在模块中创建 `extends/module/Weline_Payment/PaymentProvider/` 目录
2. 创建实现 `PaymentProviderInterface` 接口的类
3. 系统会自动扫描并注册支付提供商

详细文档请参考：[扩展开发指南](extends.md)

## Hook系统

模块在结账界面提供以下 Hook 文档，统一位于 `doc/hook/frontend/checkout/`：

- `payment-methods-before.md` - 支付方式选择区域之前
- `payment-methods-after.md` - 支付方式选择区域之后
- `payment-form-before.md` - 支付表单之前
- `payment-form-after.md` - 支付表单之后
- `payment-result.md` - 支付结果展示

## 使用示例

### 获取可用支付方式

```php
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Framework\Manager\ObjectManager;

$methodManager = ObjectManager::getInstance(PaymentMethodManager::class);
$methods = $methodManager->getActiveMethods();
```

### 创建支付订单

```php
use Weline\Payment\Service\PaymentService;
use Weline\Framework\Manager\ObjectManager;

$paymentService = ObjectManager::getInstance(PaymentService::class);
$transaction = $paymentService->createPayment('alipay', [
    'order_id' => 'ORDER123',
    'amount' => 100.00,
    'currency' => 'CNY',
    'subject' => '订单支付',
    'description' => '订单号: ORDER123',
]);
```

### 处理支付回调

```php
$transaction = $paymentService->handleCallback('alipay', $callbackData);
```

## 数据库表

### weline_payment_method (支付方式表)
- method_id: 主键
- code: 支付方式代码（唯一）
- name: 支付方式名称
- provider_module: 支付提供商模块名
- provider_class: 支付提供商类名
- is_active: 是否启用
- sort_order: 排序
- config: 配置信息（JSON）

### weline_payment_transaction (支付交易表)
- transaction_id: 主键
- order_id: 订单ID
- method_code: 支付方式代码
- transaction_no: 交易号（唯一）
- amount: 支付金额
- currency: 货币代码
- status: 支付状态
- request_data: 请求数据（JSON）
- response_data: 响应数据（JSON）
- callback_data: 回调数据（JSON）

## 依赖模块

- Weline_Framework (核心框架)
- Weline_Backend (后台管理)
- Weline_Frontend (前端支持)
- Weline_I18n (国际化支持)
- Weline_Hook (Hook系统)
- Weline_Theme (主题系统)

## 优惠方式支持

支付模块支持与营销模块的优惠方式集成，每个支付方式可以声明支持哪些优惠方式。

### 支持的优惠方式类型

支付方式可以支持以下优惠方式（由营销模块定义和扩展）：
- `discount_fixed_amount` - 固定金额折扣
- `discount_percentage` - 百分比折扣
- `free_shipping` - 免运费
- `buy_x_get_y` - 买X送Y
- `gift_product` - 赠品
- 以及其他通过营销模块扩展的优惠方式

### 配置支持的优惠方式

在后台"支付管理 > 支付方式管理"中，编辑支付方式时可以：
1. 查看所有可用的优惠方式列表
2. 选择该支付方式支持的优惠方式
3. 如果未选择任何项，默认支持所有优惠方式（向后兼容）

### 在代码中声明支持

支付提供商可以实现 `getSupportedDiscountActions()` 方法来声明支持的优惠方式：

```php
class AlipayProvider implements PaymentProviderInterface
{
    public function getSupportedDiscountActions(): ?array
    {
        // 返回支持的优惠方式代码数组
        return ['discount_fixed_amount', 'discount_percentage', 'free_shipping'];
        
        // 返回空数组表示支持所有
        // return [];
        
        // 返回null表示不支持任何优惠方式
        // return null;
    }
    
    public function supportsDiscountAction(string $actionCode): bool
    {
        $supported = $this->getSupportedDiscountActions();
        if ($supported === null) {
            return false;
        }
        if (empty($supported)) {
            return true; // 支持所有
        }
        return in_array($actionCode, $supported, true);
    }
}
```

### 检查支付方式支持

```php
use Weline\Payment\Service\DiscountActionSupportService;
use Weline\Framework\Manager\ObjectManager;

$supportService = ObjectManager::getInstance(DiscountActionSupportService::class);

// 检查是否支持
$isSupported = $supportService->checkSupport('alipay', 'discount_fixed_amount');

// 获取所有支持的优惠方式
$supported = $supportService->getSupportedActions('alipay');

// 获取所有可用的优惠方式
$allActions = $supportService->getAllDiscountActions();
```

## 版本历史

- 1.0.0: 初始版本，支持基础支付功能
- 1.1.0: 新增优惠方式支持功能，与营销模块集成

