# 支付提供商扩展开发指南

## 最小交付物

Provider 模块只需要交付三类文件：

- Provider 接口实现类：`extends/module/Weline_Payment/PaymentProvider/{Provider}.php`
- Checkout phtml：由 Provider 模块提供，用于前台特殊展示、动态字段或跳转提示，模板 code 必须和支付方式 code 对应。
- Config phtml：`extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml`

特殊授权页、OAuth 返回页、Webhook 辅助页、Provider SDK 封装和外部 API 差异由 Provider 模块自己的 controller 或 adapter 处理；最终支付状态必须回写 `Weline_Payment` 状态机。

## ProviderInterface

Provider 必须实现 `Weline\Payment\Interface\ProviderInterface`，不继承抽象类，不使用全局运行期配置状态。运行时配置由 `Weline_Payment` 通过请求 DTO 的 context 下发。

```php
<?php

declare(strict_types=1);

namespace Vendor\Module\Extends\Weline_Payment\PaymentProvider;

use Throwable;
use Weline\Payment\Api\Data\AuthorizeRequest;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\AvailabilityResult;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\CallbackResult;
use Weline\Payment\Api\Data\CaptureRequest;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\ProviderError;
use Weline\Payment\Api\Data\QueryRequest;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Api\Data\ResumeRequest;
use Weline\Payment\Api\Data\TestConnectionRequest;
use Weline\Payment\Api\Data\VoidRequest;
use Weline\Payment\Interface\ProviderInterface;

final class YourProvider implements ProviderInterface
{
    public function getCode(): string { return 'your_provider'; }
    public function getProviderCode(): string { return 'your_gateway'; }
    public function getProviderApiVersion(): string { return '1.0'; }
    public function getWebhookSchemaVersion(): string { return '1.0'; }

    public function getCapabilities(): array
    {
        return [
            'currencies' => ['USD'],
            'default_currency' => 'USD',
            'countries' => ['US'],
            'supports_refund' => true,
            'supports_partial_refund' => true,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return ['title' => 'Your Provider', 'checkout_template_code' => $this->getCode()];
    }

    public function getConfigSchema(): array { return []; }
    public function getDynamicFormSchema(AvailabilityRequest $request): array { return []; }
    public function checkAvailability(AvailabilityRequest $request): AvailabilityResult
    {
        return new AvailabilityResult([AvailabilityResult::FIELD_AVAILABLE => true]);
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        return new PaymentResult([
            PaymentResult::FIELD_STATUS => PaymentResult::STATUS_PENDING,
            PaymentResult::FIELD_PAYLOAD => ['method_code' => $this->getCode()],
        ]);
    }

    public function resumePayment(ResumeRequest $request): PaymentResult
    {
        return new PaymentResult([PaymentResult::FIELD_STATUS => PaymentResult::STATUS_PENDING]);
    }

    public function authorize(AuthorizeRequest $request): PaymentResult
    {
        return new PaymentResult([PaymentResult::FIELD_STATUS => PaymentResult::STATUS_UNSUPPORTED]);
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        return new PaymentResult([PaymentResult::FIELD_STATUS => PaymentResult::STATUS_UNSUPPORTED]);
    }

    public function void(VoidRequest $request): PaymentResult
    {
        return new PaymentResult([PaymentResult::FIELD_STATUS => PaymentResult::STATUS_UNSUPPORTED]);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return new RefundResult([RefundResult::FIELD_STATUS => RefundResult::STATUS_UNSUPPORTED]);
    }

    public function query(QueryRequest $request): PaymentResult
    {
        return new PaymentResult([PaymentResult::FIELD_STATUS => PaymentResult::STATUS_PENDING]);
    }

    public function verifyCallback(CallbackRequest $request): CallbackResult
    {
        return new CallbackResult([CallbackResult::FIELD_VERIFIED => false, CallbackResult::FIELD_EVENT_TYPE => 'ignored']);
    }

    public function parseCallback(CallbackRequest $request): CallbackResult
    {
        return new CallbackResult([CallbackResult::FIELD_VERIFIED => false, CallbackResult::FIELD_EVENT_TYPE => 'ignored']);
    }

    public function testConnection(TestConnectionRequest $request): PaymentResult
    {
        return new PaymentResult([PaymentResult::FIELD_STATUS => PaymentResult::STATUS_PAID]);
    }

    public function normalizeError(Throwable|array $error): ProviderError
    {
        return $error instanceof Throwable
            ? ProviderError::fromThrowable($error)
            : new ProviderError($error);
    }
}
```

## Config phtml

配置模板交给 `Weline_SystemConfig` 管理。Provider 模块只声明字段和 adapter，不写保存逻辑。

```phtml
<?php
/**
 * @meta.title {default="Your Provider",description="Your Provider 配置模板标题"}
 * @meta.description {default="Configure Your Provider."}
 * @config.area {backend}
 * @config.sort {80}
 * @config.acl {Weline_Payment::payment_method}
 */
?>

<w:config:group code="your_provider" label="Your Provider" sort="10">
    <w:config:field key="payment/method/your_provider/enabled" type="switch" value-type="bool" default="0" scope="global,website,store" label="启用" />
    <w:config:field key="payment/method/your_provider/api_key" type="secret" value-type="encrypted" scope="global,website,store" required="true" label="API Key" />
    <w:config:adapter code="payment.method.your_provider" label="支付方式管理" provider="payment" summary-operation="getPaymentMethodSummary" manage-url="payment/backend/method/edit?code=your_provider" />
</w:config:group>
```

普通字段 key 必须使用 `payment/method/{method_code}/{field}` 前缀；`Weline_Payment` 运行时只读取这个前缀下的 SystemConfig effective config。`method_code`、Provider `getCode()`、checkout template code、config template code 和支付方式 EAV 属性归属必须一致。

## PayableResolver

业务模块通过 `Weline\Payment\Interface\PayableResolverInterface` 接入可支付对象。订单、商城订单、应用市场订单、A2A 交易单等业务对象都应提供自己的 Resolver；简单一次性支付可以使用默认 `payment_default`。

Resolver 只负责业务对象解析、金额快照、支付权限、退款权限和生命周期通知。`Weline_Payment` 负责 payment method、checkout session、intent、allocation、transaction、refund、ledger、callback 和事件可靠性。

## 资产支付与折扣边界

- 信用、积分、W币默认不启用，不参与支付，不参与折扣。
- 同一资产在同一 Payable 上只能作为 `payment` 或 `discount` 一种角色。
- 资产分配按 `reserve -> commit -> release/refund` 流转。
- 金额使用 `amount_minor` 整数最小单位，不使用 float。

## 验证

- `php -l extends/module/Weline_Payment/PaymentProvider/{Provider}.php`
- `php -l extends/module/Weline_SystemConfig/Config/backend/{code}.phtml`
- 后台连接测试必须调用 Provider `testConnection()`。
- checkout fake 模式应能渲染 Provider 的可用性、禁用原因、条款交互，并通过 `ProviderInterface` 展示成功、失败、取消、退款状态结果。
