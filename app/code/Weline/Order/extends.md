# Weline_Order 模块扩展文档

## 概述

Weline_Order 模块提供了订单管理系统的扩展点，允许其他模块扩展支付方式、配送方式、订单状态和订单计算逻辑等功能。

## 快速开始

### 1. 创建扩展目录

根据要扩展的功能，在您的模块中创建相应的目录：

```
app/code/YourModule/
└── Service/
    ├── PaymentMethod/
    │   └── YourPaymentMethod.php
    ├── ShippingMethod/
    │   └── YourShippingMethod.php
    ├── OrderStatus/
    │   └── YourOrderStatus.php
    └── Calculator/
        └── YourCalculator.php
```

### 2. 实现支付方式扩展

创建支付方式类，实现 `PaymentMethodInterface` 接口：

```php
<?php
declare(strict_types=1);

namespace YourModule\Service\PaymentMethod;

use Weline\Order\Service\PaymentMethod\PaymentMethodInterface;
use Weline\Order\Model\Order;

class YourPaymentMethod implements PaymentMethodInterface
{
    public function getCode(): string
    {
        return 'your_payment_method';
    }

    public function getName(): string
    {
        return '您的支付方式';
    }

    public function process(Order $order, array $params = []): array
    {
        // 处理支付逻辑
        return [
            'success' => true,
            'payment_url' => 'https://payment.example.com/pay',
            'transaction_no' => $this->generateTransactionNo()
        ];
    }

    public function refund(Order $order, float $amount, string $reason = ''): array
    {
        // 处理退款逻辑
        return [
            'success' => true,
            'refund_no' => $this->generateRefundNo()
        ];
    }

    public function getConfig(): array
    {
        return [
            'app_id' => '应用ID',
            'app_secret' => '应用密钥'
        ];
    }

    private function generateTransactionNo(): string
    {
        return 'TXN' . time();
    }

    private function generateRefundNo(): string
    {
        return 'REF' . time();
    }
}
```

### 3. 实现配送方式扩展

创建配送方式类，实现 `ShippingMethodInterface` 接口：

```php
<?php
declare(strict_types=1);

namespace YourModule\Service\ShippingMethod;

use Weline\Order\Service\ShippingMethod\ShippingMethodInterface;
use Weline\Order\Model\Order;

class YourShippingMethod implements ShippingMethodInterface
{
    public function getCode(): string
    {
        return 'your_shipping_method';
    }

    public function getName(): string
    {
        return '您的配送方式';
    }

    public function calculate(Order $order, array $params = []): float
    {
        // 计算运费
        $baseShipping = 10.00;
        $weight = $order->getWeight();
        $shipping = $baseShipping + ($weight * 2);
        
        return $shipping;
    }

    public function track(string $trackingNumber): array
    {
        // 物流跟踪
        return [
            'status' => 'in_transit',
            'location' => '配送中心',
            'estimated_delivery' => date('Y-m-d H:i:s', strtotime('+3 days'))
        ];
    }

    public function getConfig(): array
    {
        return [
            'api_key' => 'API密钥',
            'base_shipping' => 10.00
        ];
    }
}
```

## 详细说明

### PaymentMethods 扩展点

**路径**: `Service/PaymentMethod/{PaymentMethodName}.php`

**接口**: `Weline\Order\Service\PaymentMethod\PaymentMethodInterface`

**用途**: 扩展支付方式，允许其他模块注册自定义支付方式（如第三方支付、货到付款等）。

**要求**:
- 必须实现 `PaymentMethodInterface` 接口
- 必须实现 `process()` 方法处理支付
- 必须实现 `refund()` 方法处理退款
- 必须实现 `getConfig()` 方法返回配置字段
- 允许多个实现

#### 接口方法说明

- **getCode()**: 返回支付方式代码（唯一标识）
- **getName()**: 返回支付方式显示名称
- **process()**: 处理支付，返回支付结果数组
- **refund()**: 处理退款，返回退款结果数组
- **getConfig()**: 返回配置字段定义

### ShippingMethods 扩展点

**路径**: `Service/ShippingMethod/{ShippingMethodName}.php`

**接口**: `Weline\Order\Service\ShippingMethod\ShippingMethodInterface`

**用途**: 扩展配送方式，允许其他模块注册自定义配送方式（如快递、物流、自提等）。

**要求**:
- 必须实现 `ShippingMethodInterface` 接口
- 必须实现 `calculate()` 方法计算运费
- 必须实现 `track()` 方法提供物流跟踪
- 必须实现 `getConfig()` 方法返回配置字段
- 允许多个实现

#### 接口方法说明

- **getCode()**: 返回配送方式代码（唯一标识）
- **getName()**: 返回配送方式显示名称
- **calculate()**: 计算运费，返回运费金额
- **track()**: 物流跟踪，返回跟踪信息数组
- **getConfig()**: 返回配置字段定义

### OrderStatuses 扩展点

**路径**: `Service/OrderStatus/{StatusName}.php`

**接口**: `Weline\Order\Service\OrderStatus\OrderStatusInterface`

**用途**: 扩展订单状态，允许其他模块注册自定义订单状态及其转换规则。

**要求**:
- 必须实现 `OrderStatusInterface` 接口
- 必须实现 `canTransition()` 方法检查状态转换
- 必须实现 `onEnter()` 方法处理进入状态
- 必须实现 `onExit()` 方法处理退出状态
- 允许多个实现

#### 接口方法说明

- **getCode()**: 返回状态代码（唯一标识）
- **getName()**: 返回状态显示名称
- **canTransition()**: 检查是否可以从某个状态转换到当前状态
- **onEnter()**: 进入状态时的处理逻辑
- **onExit()**: 退出状态时的处理逻辑

### OrderCalculators 扩展点

**路径**: `Service/Calculator/{CalculatorName}.php`

**接口**: `Weline\Order\Service\Calculator\CalculatorInterface`

**用途**: 扩展订单计算逻辑，允许其他模块注册自定义计算器（如税费、折扣、服务费等）。

**要求**:
- 必须实现 `CalculatorInterface` 接口
- 必须实现 `calculate()` 方法执行计算
- 必须实现 `getType()` 方法返回计算器类型
- 允许多个实现

#### 接口方法说明

- **getCode()**: 返回计算器代码（唯一标识）
- **getName()**: 返回计算器显示名称
- **calculate()**: 执行计算，返回计算结果
- **getType()**: 返回计算器类型（tax, discount, fee 等）

## 使用场景

### 1. 第三方支付集成

```php
class AlipayPaymentMethod implements PaymentMethodInterface
{
    public function process(Order $order, array $params = []): array
    {
        // 调用支付宝API创建支付订单
        $alipay = new AlipayClient($this->getConfig());
        $payment = $alipay->createOrder([
            'order_id' => $order->getId(),
            'amount' => $order->getTotal(),
            'subject' => $order->getItemsDescription()
        ]);
        
        return [
            'success' => true,
            'payment_url' => $payment['payment_url'],
            'transaction_no' => $payment['trade_no']
        ];
    }
    
    // ... 其他方法
}
```

### 2. 自定义运费计算

```php
class WeightBasedShipping implements ShippingMethodInterface
{
    public function calculate(Order $order, array $params = []): float
    {
        $baseFee = $params['base_fee'] ?? 10.00;
        $weightFee = $params['weight_fee'] ?? 2.00;
        $freeThreshold = $params['free_threshold'] ?? 99.00;
        
        // 满额免运费
        if ($order->getSubtotal() >= $freeThreshold) {
            return 0;
        }
        
        // 按重量计算
        $weight = $order->getWeight();
        return $baseFee + ($weight * $weightFee);
    }
    
    // ... 其他方法
}
```

### 3. 税费计算

```php
class TaxCalculator implements CalculatorInterface
{
    public function calculate(Order $order, array $params = []): array
    {
        $taxRate = $params['rate'] ?? 0.10; // 10%税率
        $taxableAmount = $order->getSubtotal();
        $tax = $taxableAmount * $taxRate;
        
        return [
            'type' => 'tax',
            'amount' => $tax,
            'description' => '税费',
            'items' => [
                [
                    'name' => '商品税',
                    'amount' => $tax
                ]
            ]
        ];
    }
    
    public function getType(): string
    {
        return 'tax';
    }
}
```

## 最佳实践

1. **错误处理**: 完善异常处理，返回清晰的错误信息
2. **配置管理**: 使用配置字段管理敏感信息，不要硬编码
3. **日志记录**: 记录重要的支付、退款、配送操作日志
4. **事务处理**: 涉及金额操作时，使用数据库事务确保数据一致性
5. **安全性**: 验证签名、加密敏感数据、防止重放攻击
6. **可测试性**: 编写单元测试，模拟各种场景

## 常见问题

### Q: 支付方式如何处理异步回调？

A: 需要在支付方式模块中实现回调处理接口，系统会自动路由到对应的支付方式处理回调。

### Q: 配送方式如何获取实时物流信息？

A: 在 `track()` 方法中调用第三方物流API获取实时信息，可以缓存结果提升性能。

### Q: 订单状态转换有什么限制？

A: 状态转换需要在 `canTransition()` 方法中定义规则，系统会检查规则后才允许转换。

### Q: 计算器的执行顺序如何确定？

A: 系统按照计算器的优先级顺序执行，可以通过配置文件调整优先级。

## 相关文档

详细扩展点说明请参考：`doc/扩展点说明.md`
