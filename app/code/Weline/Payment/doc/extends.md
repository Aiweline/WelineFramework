# 支付提供商扩展开发指南

## 概述

本文档介绍如何开发支付提供商模块，实现 `PaymentProviderInterface` 接口来接入支付系统。

## 快速开始

### 步骤 1: 创建扩展目录

在您的模块中创建以下目录结构：

```
app/code/Vendor/ModuleName/
└── extends/
    └── module/
        └── Weline_Payment/
            └── PaymentProvider/
                └── YourProvider.php
```

### 步骤 2: 创建支付提供商类

创建实现 `PaymentProviderInterface` 接口的类：

```php
<?php
declare(strict_types=1);

namespace Vendor\ModuleName\Extends\Weline_Payment\PaymentProvider;

use Weline\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Model\PaymentResult;

class YourProvider implements PaymentProviderInterface
{
    private array $config = [];

    public function getCode(): string
    {
        return 'your_provider';
    }

    public function getName(): string
    {
        return '您的支付提供商';
    }

    public function getDescription(): string
    {
        return '支付提供商描述';
    }

    public function getIconUrl(): ?string
    {
        return '/static/images/payment/your-provider.png';
    }

    public function createPayment(array $orderData): PaymentResult
    {
        // 实现创建支付订单逻辑
        // 返回 PaymentResult::success() 或 PaymentResult::failed()
        
        try {
            // 调用支付API创建订单
            $paymentUrl = $this->callPaymentApi($orderData);
            
            return PaymentResult::success([
                'payment_url' => $paymentUrl,
                'transaction_no' => $orderData['transaction_no'] ?? '',
            ]);
        } catch (\Exception $e) {
            return PaymentResult::failed($e->getMessage());
        }
    }

    public function handleCallback(array $callbackData): PaymentResult
    {
        // 实现支付回调处理逻辑
        
        $transactionNo = $callbackData['transaction_no'] ?? '';
        $status = $callbackData['status'] ?? '';
        
        if ($status === 'success') {
            return PaymentResult::success([
                'transaction_no' => $transactionNo,
            ]);
        }
        
        return PaymentResult::failed('支付失败');
    }

    public function queryPaymentStatus(string $transactionNo): PaymentResult
    {
        // 实现查询支付状态逻辑
        
        try {
            $status = $this->queryStatusFromApi($transactionNo);
            
            if ($status === 'success') {
                return PaymentResult::success([
                    'transaction_no' => $transactionNo,
                    'status' => 'success',
                ]);
            }
            
            return PaymentResult::pending([
                'transaction_no' => $transactionNo,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            return PaymentResult::failed($e->getMessage());
        }
    }

    public function refund(string $transactionNo, float $amount, string $reason = ''): PaymentResult
    {
        // 实现退款逻辑
        
        try {
            $result = $this->callRefundApi($transactionNo, $amount, $reason);
            
            if ($result['success']) {
                return PaymentResult::success([
                    'refund_no' => $result['refund_no'],
                ]);
            }
            
            return PaymentResult::failed($result['message'] ?? '退款失败');
        } catch (\Exception $e) {
            return PaymentResult::failed($e->getMessage());
        }
    }

    public function verifySignature(array $data, string $signature): bool
    {
        // 实现签名验证逻辑
        
        $signString = $this->buildSignString($data);
        $calculatedSign = $this->calculateSignature($signString);
        
        return $calculatedSign === $signature;
    }

    public function getConfigFields(): array
    {
        return [
            'app_id' => [
                'label' => '应用ID',
                'type' => 'text',
                'required' => true,
                'description' => '支付提供商分配的应用ID',
            ],
            'app_secret' => [
                'label' => '应用密钥',
                'type' => 'password',
                'required' => true,
                'description' => '支付提供商分配的应用密钥',
            ],
            'merchant_id' => [
                'label' => '商户号',
                'type' => 'text',
                'required' => true,
                'description' => '商户号',
            ],
            'is_sandbox' => [
                'label' => '是否沙箱环境',
                'type' => 'select',
                'required' => false,
                'options' => [
                    '0' => '生产环境',
                    '1' => '沙箱环境',
                ],
                'default' => '0',
            ],
        ];
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function supportsCurrency(string $currency): bool
    {
        // 定义支持的货币
        return in_array($currency, ['CNY', 'USD', 'EUR']);
    }

    public function supportsAmount(float $amount): bool
    {
        // 定义支持的金额范围
        return $amount >= 0.01 && $amount <= 100000.00;
    }

    /**
     * 获取支持的优惠方式代码列表
     * 
     * 返回支持的营销模块优惠方式代码数组，如：
     * ['discount_fixed_amount', 'discount_percentage', 'free_shipping']
     * 
     * 如果返回空数组，表示支持所有优惠方式
     * 如果返回null，表示不支持任何优惠方式
     * 
     * @return array|null
     */
    public function getSupportedDiscountActions(): ?array
    {
        // 示例：只支持固定金额折扣和百分比折扣
        return ['discount_fixed_amount', 'discount_percentage'];
        
        // 支持所有优惠方式
        // return [];
        
        // 不支持任何优惠方式
        // return null;
    }

    /**
     * 检查是否支持特定优惠方式
     * 
     * @param string $actionCode 优惠方式代码（如：discount_fixed_amount）
     * @return bool
     */
    public function supportsDiscountAction(string $actionCode): bool
    {
        $supported = $this->getSupportedDiscountActions();
        
        // 如果返回null，表示不支持任何
        if ($supported === null) {
            return false;
        }
        
        // 如果返回空数组，表示支持所有
        if (empty($supported)) {
            return true;
        }
        
        return in_array($actionCode, $supported, true);
    }

    // 私有方法：调用支付API
    private function callPaymentApi(array $orderData): string
    {
        // 实现调用支付API的逻辑
        // 返回支付URL
    }

    // 私有方法：查询状态
    private function queryStatusFromApi(string $transactionNo): string
    {
        // 实现查询状态的逻辑
    }

    // 私有方法：调用退款API
    private function callRefundApi(string $transactionNo, float $amount, string $reason): array
    {
        // 实现调用退款API的逻辑
    }

    // 私有方法：构建签名字符串
    private function buildSignString(array $data): string
    {
        // 实现构建签名字符串的逻辑
    }

    // 私有方法：计算签名
    private function calculateSignature(string $signString): string
    {
        // 实现计算签名的逻辑
    }
}
```

### 步骤 3: 注册模块

确保您的模块已正确注册，并且依赖 `Weline_Payment` 模块。

### 步骤 4: 运行升级

运行以下命令来扫描和注册支付提供商：

```bash
php bin/w setup:upgrade
```

## 配置字段类型

### text
文本输入框

```php
'field_name' => [
    'label' => '字段标签',
    'type' => 'text',
    'required' => true,
    'default' => '默认值',
    'description' => '字段说明',
]
```

### password
密码输入框

```php
'field_name' => [
    'label' => '字段标签',
    'type' => 'password',
    'required' => true,
]
```

### textarea
多行文本输入框

```php
'field_name' => [
    'label' => '字段标签',
    'type' => 'textarea',
    'required' => false,
]
```

### select
下拉选择框

```php
'field_name' => [
    'label' => '字段标签',
    'type' => 'select',
    'required' => true,
    'options' => [
        'value1' => '选项1',
        'value2' => '选项2',
    ],
    'default' => 'value1',
]
```

## 支付结果处理

### 创建支付订单

创建支付订单时，可以返回以下数据：

```php
PaymentResult::success([
    'payment_url' => 'https://payment.example.com/pay?order_id=xxx', // 跳转URL
    'payment_form' => '<form>...</form>', // 支付表单HTML
    'qr_code' => 'https://payment.example.com/qr.png', // 二维码URL
    'transaction_no' => 'PAY123456789', // 交易号
]);
```

### 处理回调

处理回调时，需要验证签名并返回结果：

```php
// 验证签名
if (!$this->verifySignature($callbackData, $callbackData['signature'])) {
    return PaymentResult::failed('签名验证失败');
}

// 处理成功
return PaymentResult::success([
    'transaction_no' => $callbackData['transaction_no'],
]);
```

## 优惠方式支持

支付提供商可以实现 `getSupportedDiscountActions()` 和 `supportsDiscountAction()` 方法来声明支持的优惠方式。

### 优惠方式类型

营销模块定义了以下优惠方式（可通过扩展机制扩展）：
- `discount_fixed_amount` - 固定金额折扣
- `discount_percentage` - 百分比折扣
- `free_shipping` - 免运费
- `buy_x_get_y` - 买X送Y
- `gift_product` - 赠品

### 实现示例

```php
public function getSupportedDiscountActions(): ?array
{
    // 声明支持的优惠方式
    return ['discount_fixed_amount', 'discount_percentage', 'free_shipping'];
}

public function supportsDiscountAction(string $actionCode): bool
{
    $supported = $this->getSupportedDiscountActions();
    if ($supported === null) {
        return false; // 不支持任何
    }
    if (empty($supported)) {
        return true; // 支持所有
    }
    return in_array($actionCode, $supported, true);
}
```

### 配置优先级

优惠方式支持的优先级：
1. **后台配置**：在支付方式编辑页面配置的支持列表（优先级最高）
2. **代码声明**：支付提供商实现的 `getSupportedDiscountActions()` 方法
3. **默认行为**：如果都未配置，默认支持所有优惠方式（向后兼容）

## 最佳实践

1. **安全性**: 始终验证签名，确保回调数据的安全性
2. **错误处理**: 妥善处理异常情况，返回明确的错误信息
3. **优惠方式支持**: 根据支付提供商的业务规则，明确声明支持的优惠方式
3. **日志记录**: 记录重要的操作日志，便于问题排查
4. **配置验证**: 在创建支付前验证配置是否完整
5. **金额验证**: 验证支付金额是否在支持范围内
6. **货币支持**: 检查货币是否支持

## 常见问题

### Q: 支付提供商没有被扫描到？

A: 请确认：
1. 类实现了 `PaymentProviderInterface` 接口
2. 文件位于 `extends/module/Weline_Payment/PaymentProvider/` 目录
3. 文件正确声明了命名空间
4. 运行了 `setup:upgrade` 命令

### Q: 如何测试支付提供商？

A: 建议：
1. 使用沙箱环境进行测试
2. 在配置中添加 `is_sandbox` 选项
3. 使用测试账号和测试金额

### Q: 如何处理异步回调？

A: 在 `handleCallback()` 方法中处理异步回调，确保：
1. 验证签名
2. 更新交易状态
3. 触发相关事件
4. 返回正确的响应

## 示例模块

可以参考以下示例模块：
- Weline_Payment_Alipay (支付宝)
- Weline_Payment_WeChatPay (微信支付)

