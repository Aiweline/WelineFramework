# Weline_Payment 模块扩展文档

## 概述

Weline_Payment 模块提供了支付提供商扩展点，允许其他模块实现自定义支付提供商来接入支付系统。本文档介绍如何开发支付提供商扩展。

## 快速开始

### 1. 创建扩展目录

在您的模块中创建以下目录结构：

```
app/code/Vendor/ModuleName/
└── extends/
    └── module/
        └── Weline_Payment/
            └── PaymentProvider/
                └── YourProvider.php
```

### 2. 实现支付提供商接口

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
        return in_array($currency, ['CNY', 'USD', 'EUR']);
    }

    public function supportsAmount(float $amount): bool
    {
        return $amount >= 0.01 && $amount <= 100000.00;
    }

    public function getSupportedDiscountActions(): ?array
    {
        return ['discount_fixed_amount', 'discount_percentage'];
    }

    public function supportsDiscountAction(string $actionCode): bool
    {
        $supported = $this->getSupportedDiscountActions();
        if ($supported === null) {
            return false;
        }
        if (empty($supported)) {
            return true;
        }
        return in_array($actionCode, $supported, true);
    }

    // 私有辅助方法
    private function callPaymentApi(array $orderData): string
    {
        // 实现支付API调用
        return '';
    }

    private function queryStatusFromApi(string $transactionNo): string
    {
        // 实现状态查询
        return '';
    }

    private function callRefundApi(string $transactionNo, float $amount, string $reason): array
    {
        // 实现退款API调用
        return [];
    }

    private function buildSignString(array $data): string
    {
        // 实现签名字符串构建
        return '';
    }

    private function calculateSignature(string $signString): string
    {
        // 实现签名计算
        return '';
    }
}
```

### 3. 注册模块

确保您的模块已正确注册，并且依赖 `Weline_Payment` 模块。

### 4. 运行升级

运行以下命令来扫描和注册支付提供商：

```bash
php bin/w setup:upgrade
```

## 详细说明

### PaymentProvider 扩展点

**路径**: `extends/module/Weline_Payment/PaymentProvider`

**接口**: `Weline\Payment\Interface\PaymentProviderInterface`

**用途**: 扩展支付提供商，允许其他支付供应商开发支付模块，实现此接口来接入支付系统。

**要求**:
- 必须实现 `PaymentProviderInterface` 接口
- 必须实现所有接口方法
- 允许多个实现

#### 核心方法说明

- **createPayment()**: 创建支付订单，返回支付URL或表单
- **handleCallback()**: 处理支付回调，验证签名并更新订单状态
- **queryPaymentStatus()**: 查询支付状态
- **refund()**: 处理退款
- **verifySignature()**: 验证签名，确保回调安全性

## 配置字段类型

### text

文本输入框

```php
'app_id' => [
    'label' => '应用ID',
    'type' => 'text',
    'required' => true,
    'default' => '',
    'description' => '字段说明',
]
```

### password

密码输入框

```php
'app_secret' => [
    'label' => '应用密钥',
    'type' => 'password',
    'required' => true,
]
```

### select

下拉选择框

```php
'environment' => [
    'label' => '环境',
    'type' => 'select',
    'required' => true,
    'options' => [
        'sandbox' => '沙箱环境',
        'production' => '生产环境',
    ],
    'default' => 'sandbox',
]
```

## 支付结果处理

### 创建支付订单

```php
return PaymentResult::success([
    'payment_url' => 'https://payment.example.com/pay?order_id=xxx', // 跳转URL
    'payment_form' => '<form>...</form>', // 支付表单HTML
    'qr_code' => 'https://payment.example.com/qr.png', // 二维码URL
    'transaction_no' => 'PAY123456789', // 交易号
]);
```

### 处理回调

```php
public function handleCallback(array $callbackData): PaymentResult
{
    // 验证签名
    if (!$this->verifySignature($callbackData, $callbackData['signature'])) {
        return PaymentResult::failed('签名验证失败');
    }

    // 处理成功
    return PaymentResult::success([
        'transaction_no' => $callbackData['transaction_no'],
    ]);
}
```

## 优惠方式支持

支付提供商可以实现 `getSupportedDiscountActions()` 方法来声明支持的优惠方式：

```php
public function getSupportedDiscountActions(): ?array
{
    // 声明支持的优惠方式
    return ['discount_fixed_amount', 'discount_percentage', 'free_shipping'];
    
    // 支持所有优惠方式
    // return [];
    
    // 不支持任何优惠方式
    // return null;
}
```

## 最佳实践

1. **安全性**: 始终验证签名，确保回调数据的安全性
2. **错误处理**: 妥善处理异常情况，返回明确的错误信息
3. **日志记录**: 记录重要的支付、退款操作日志，便于问题排查
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

## 相关文档

详细开发指南请参考：`doc/extends.md`
