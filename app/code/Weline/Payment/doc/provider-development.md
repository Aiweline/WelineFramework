# 第三方支付模块开发指南

本文面向接入 `Weline_Payment` 的第三方支付模块。新支付方式不继承抽象类，只实现一个 Provider 接口类，并交付 checkout 模板和 SystemConfig 配置模板。特殊授权页、Webhook 辅助页或 OAuth 返回页可以由第三方模块自己的 controller 实现，但支付、退款、回调归一化和业务状态推进必须回到 `Weline_Payment`。

## 1. 最小模块结构

以 `Vendor_YourPay` 为例：

```text
app/code/Vendor/YourPay/
├─ extends/module/Weline_Payment/PaymentProvider/YourPayProvider.php
├─ extends/module/Weline_SystemConfig/Config/backend/your_pay.phtml
├─ view/templates/Frontend/checkout/your_pay.phtml
├─ etc/env.php
└─ register.php
```

`etc/env.php` 只在第三方模块需要暴露自己路由时配置；`Weline_Payment` 自身路由前缀是 `payment`，例如 `payment/backend/method/edit?code=your_pay`。第三方支付模块不应声明 `weline_payment` 路由。

三个 code 必须一致：

| 项 | 要求 |
| --- | --- |
| Provider `getCode()` | 返回稳定 `method_code`，例如 `your_pay`。 |
| Config phtml 文件名 | `backend/your_pay.phtml`。 |
| Checkout 模板 code | `view/templates/Frontend/checkout/your_pay.phtml`，并在 `getDisplayMetadata()` 中声明 `checkout_template_code => your_pay`。 |

## 2. Provider 类

Provider 类放在：

```text
extends/module/Weline_Payment/PaymentProvider/YourPayProvider.php
```

命名空间遵守模块扩展路径约定：

```php
namespace Vendor\YourPay\Extends\Module\Weline_Payment\PaymentProvider;
```

Provider 必须实现 `Weline\Payment\Interface\ProviderInterface`。接口函数全部要实现，不能只实现支付成功路径。

| 函数 | 必须处理 |
| --- | --- |
| `getCode()` | 稳定 method code。 |
| `getProviderCode()` | 网关或通道 code，用于统计、路由和对账。 |
| `getProviderApiVersion()` | Provider API 版本，写入快照。 |
| `getWebhookSchemaVersion()` | 回调 schema 版本，历史回调按版本解析。 |
| `getCapabilities()` | 货币、国家、默认货币、授权、捕获、取消、退款、部分退款、保存支付工具、线下确认等硬能力。 |
| `getDisplayMetadata()` | 标题、描述、icon、`checkout_template_code`、`config_template_code`。 |
| `getConfigSchema()` | Provider 动态校验补充；后台 UI 仍由 config phtml 生成。 |
| `getDynamicFormSchema()` | checkout 需要的本地字段，例如税号、银行、手机号、UPI、分期数。 |
| `checkAvailability()` | 按 amount、currency、country、scope、Payable、runtime config 判断是否可用并返回禁用原因。 |
| `createPayment()` | 创建支付，返回跳转、二维码、票据、Provider reference 或终态。 |
| `resumePayment()` | 继续未完成支付，恢复跳转、二维码、票据或返回终态。 |
| `authorize()` / `capture()` / `void()` | 授权、捕获、取消授权；不支持时明确返回 unsupported。 |
| `refund()` | 全额、部分、多次部分退款；不支持的退款类型明确返回 unsupported。 |
| `query()` | 查询支付、退款、争议或结算状态，用于补偿。 |
| `verifyCallback()` | 校验签名、时间窗、secret 版本、endpoint 和重放。 |
| `parseCallback()` | 归一化事件，返回 intent、transaction、refund、dispute 的目标和建议状态。 |
| `testConnection()` | 后台连接测试，输出脱敏结果。 |
| `normalizeError()` | 统一错误 code、retryable、user_visible、provider_error_code。 |

Provider 不保存全局配置状态。`Weline_Payment` 会把 SystemConfig effective config 放进请求 DTO 的 `context.runtime_config`，Provider 从 request context 读取即可。

## 3. 能力与国际化

`getCapabilities()` 是硬上限，后台配置只能收窄，不能扩大未声明能力。生产级 Provider 至少声明：

```php
[
    'payment' => true,
    'refund' => true,
    'partial_refund' => true,
    'authorize' => false,
    'capture' => false,
    'void' => false,
    'saved_instrument' => false,
    'offline_confirmation' => false,
    'default_currency' => 'USD',
    'supported_currencies' => ['USD', 'EUR'],
    'supported_countries' => ['US', 'DE', 'FR'],
    'supported_languages' => ['en_US'],
]
```

默认货币必须属于支持货币。checkout 可支付对象的支付货币不在支持货币内时，支付方式不能被选择；用户国家不在支持国家内时，默认不展示，点击“更多支付方式”时可灰色展示并给出原因。

金额一律使用 `amount_minor` 整数最小单位，不能使用 float。语言、国家、时区和币种从 DTO 或 Payable snapshot 获取，不在 Provider 内猜测。

## 4. SystemConfig 配置模板

配置模板放在：

```text
extends/module/Weline_SystemConfig/Config/backend/your_pay.phtml
```

普通配置字段必须使用 `payment/method/{method_code}/{field}` 前缀。scope 选择、继承来源、普通 key/value 保存、校验、审计和缓存失效由 `Weline_SystemConfig` 管理；支付模块不写保存 controller。

```phtml
<?php
/**
 * @meta.title {default="Your Pay",description="Your Pay 配置模板标题"}
 * @meta.description {default="配置 Your Pay 支付方式。"}
 * @config.area {backend}
 * @config.sort {80}
 * @config.acl {Weline_Payment::payment_method}
 */
?>

<w:config:group code="your_pay" label="Your Pay" sort="10">
    <w:config:field
        key="payment/method/your_pay/enabled"
        label="启用 Your Pay"
        type="switch"
        value-type="bool"
        default="0"
        scope="global,website,store" />

    <w:config:field
        key="payment/method/your_pay/api_key"
        label="API Key"
        type="secret"
        value-type="encrypted"
        required="true"
        scope="global,website,store" />

    <w:config:field
        key="payment/method/your_pay/default_currency"
        label="默认支付货币"
        type="select"
        value-type="string"
        default="USD"
        scope="global,website,store"
        options="USD:USD,EUR:EUR"
        validation="required|in_options" />

    <w:config:field
        key="payment/method/your_pay/supported_currencies"
        label="支持货币"
        type="multiselect"
        value-type="json"
        default="USD,EUR"
        scope="global,website,store"
        options="USD:USD,EUR:EUR"
        validation="required|in_options" />

    <w:config:field
        key="payment/method/your_pay/supported_countries"
        label="支持国家"
        type="multiselect"
        value-type="json"
        default="US,DE,FR"
        scope="global,website,store"
        options="US:US,DE:DE,FR:FR"
        validation="in_options" />

    <w:config:adapter
        code="payment.method.your_pay"
        label="支付方式管理"
        provider="payment"
        summary-operation="getPaymentMethodSummary"
        manage-url="payment/backend/method/edit?code=your_pay" />
</w:config:group>
```

通用字段建议交给 `Weline_Payment` 管理：启用、排序、默认货币、支持货币、支持国家、最小/最大金额、允许的 Payable、禁止的 Payable、退款策略、是否允许部分退款、是否要求付款人身份、是否参与折扣、最大折扣比例。Provider 只处理网关专属字段，例如 API key、merchant id、webhook secret、OAuth client、descriptor、mandate 规则。

## 5. Checkout 模板

Provider 的 checkout 模板建议放在：

```text
view/templates/Frontend/checkout/your_pay.phtml
```

模板 code 默认等于 `method_code`。Provider 在 `getDisplayMetadata()` 中返回：

```php
[
    'title' => 'Your Pay',
    'checkout_template_code' => 'your_pay',
    'config_template_code' => 'your_pay',
]
```

checkout 模板只负责展示和收集本支付方式需要的字段，不保存配置、不直接调用 Provider API、不直接改订单状态。提交支付时由 `Weline_Payment` 创建 checkout session、intent、attempt 和 transaction。

## 6. Payable 接入

支付对象不等于订单。业务模块需要实现 `Weline\Payment\Interface\PayableResolverInterface`，或在简单一次性支付场景使用默认 `payment_default`。

业务模块 Resolver 负责：

- 解析业务对象和付款人。
- 生成金额、币种、国家、语言、税费、运费、折扣和行项目快照。
- 判断谁可以支付、能否取消、能否退款。
- 在 paid、partially paid、refunded、expired、risk review 后处理自己的业务状态。

`Weline_Payment` 不直接发货、不发权益、不改订单、不释放 A2A 托管，只通过 Resolver 和事件通知业务模块。

## 7. 退款、锁与幂等

Provider 必须把支付、授权、捕获、取消、退款和查询结果映射为 `Weline_Payment` DTO。退款必须带稳定 code：

- `refund_code`
- `transaction_code`
- `intent_code`
- `attempt_code`
- `provider_reference`
- `amount_minor`
- `currency_code`
- `reason_code`

部分退款、多次部分退款和资产退款必须能从 allocation、transaction、refund 和 ledger 追溯来源。相同 idempotency key 只允许相同请求指纹复用结果；金额、币种、Payable、method、operation 或 request body hash 不一致时必须报冲突。Provider 不自己绕过 Payment 锁创建 active intent 或 active attempt。

## 8. 资产支付与折扣

信用、积分、W币默认都不启用，不参与支付，也不参与折扣。开启兑换比例后才允许参与；后台可以配置它们作为支付方式或折扣方式。同一资产在同一 Payable 上不能同时作为支付和折扣角色，必须按配置选择一种角色。

资产必须经过 `reserve -> commit -> release/refund`，并写 allocation 和 ledger。部分退款时按原 allocation 或运营指定来源退回，尾差进入明确账本项。

## 9. 支付方式 EAV

通用配置不足以覆盖所有支付方式。Provider 专属扩展字段应走支付方式 EAV，按 `method_code + scope` 读取和写入，不改 Payment 核心表结构。

`Weline_Payment` 提供 `Weline\Payment\Helper\PaymentMethodAttributeHelper` 协助第三方模块按支付 code 管理普通扩展属性：

```php
use Weline\Payment\Helper\PaymentMethodAttributeHelper;

final class YourPaySetup
{
    public function __construct(
        private readonly PaymentMethodAttributeHelper $methodAttributeHelper
    ) {
    }

    public function declareAttributes(): void
    {
        $this->methodAttributeHelper->declareAttribute(
            'your_pay_bank_group',
            'Your Pay Bank Group',
            'input_string'
        );
    }

    public function saveRuntimeHint(string $value): void
    {
        $this->methodAttributeHelper->setValue('your_pay', 'your_pay_bank_group', $value);
    }

    public function getRuntimeHint(): ?string
    {
        return $this->methodAttributeHelper->getValue('your_pay', 'your_pay_bank_group');
    }
}
```

可用类型以当前 EAV 类型表为准，常用值包括 `input_string`、`input_int`、`input_bool`、`select_option`、`select_option_multiple`、`textarea_text`。普通字符串默认使用 `input_string`，不要把数据库字段类型名 `varchar` 当作 EAV 类型 code。

建议规则：

- 属性 code 带 Provider 前缀，例如 `your_pay_bank_group`。
- 敏感值仍走 SystemConfig 加密字段，不放进普通 EAV。
- EAV 变更必须失效 checkout availability、动态表单和风险规则缓存。
- Provider 从请求 context 或 Payment 下发的 AttributeBag 读取属性，不直接跨模块查询业务表。

## 10. 验证清单

第三方模块至少执行：

```powershell
php -l app/code/Vendor/YourPay/extends/module/Weline_Payment/PaymentProvider/YourPayProvider.php
php -l app/code/Vendor/YourPay/extends/module/Weline_SystemConfig/Config/backend/your_pay.phtml
php bin/w setup:upgrade --route
php bin/w route:list
php bin/w http:request /payment/frontend/checkout/fake
```

浏览器验收使用独立 WLS 实例，不使用默认 9501：

```powershell
php bin/w server:start -p 9506 -n ai-test-payment-fake
php bin/w server:stop -n ai-test-payment-fake
```

必须在 Browser 中验证：支付方式展示、不可用原因、条款同意、fake 成功、失败、取消、退款。未验证的能力不得在交付说明里写成已通过。
