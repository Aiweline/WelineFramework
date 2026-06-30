# Weline_Payment 模块扩展文档

## 扩展边界

`Weline_Payment` 提供统一支付内核。支付方式通过 `ProviderInterface` 接入，业务对象通过 `PayableResolverInterface` 接入。

Provider 模块最小交付物：

- `extends/module/Weline_Payment/PaymentProvider/{Provider}.php`
- checkout phtml，用于前台特殊展示、动态字段或跳转提示
- `extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml`

模块只写配置 phtml；scope 选择、继承来源、普通 key/value 保存、校验、审计和缓存失效由 `Weline_SystemConfig` 管理。支付方式复杂对象通过 `Weline_Payment` 的 adapter 和业务表管理。

## Provider

Provider 必须实现 `Weline\Payment\Interface\ProviderInterface`。核心动作包括：

- `checkAvailability(AvailabilityRequest $request)`
- `createPayment(PaymentRequest $request)`
- `resumePayment(ResumeRequest $request)`
- `authorize(AuthorizeRequest $request)`
- `capture(CaptureRequest $request)`
- `void(VoidRequest $request)`
- `refund(RefundRequest $request)`
- `query(QueryRequest $request)`
- `verifyCallback(CallbackRequest $request)`
- `parseCallback(CallbackRequest $request)`
- `testConnection(TestConnectionRequest $request)`

Provider 不保存全局配置状态，不暴露运行期配置注入方法。运行时配置由请求 DTO 的 context 提供；Provider capability 是硬上限，后台配置只能收窄，不能扩大 Provider 未声明能力。

## PayableResolver

`Weline_Payment` 内置默认 `payment_default`，只用于简单一次性支付。正式业务模块建议提供独立 `payable_type`，例如：

- `weline_order`
- `weshop_order`
- `app_market_order`
- `a2a_trade_order`
- `a2a_escrow_case`

Resolver 负责解析业务对象、生成金额快照、判断支付和退款权限，并在支付、部分支付、退款、过期、风控审核后通知业务模块处理自己的状态。支付内核不直接发货、发权益、改订单或释放 A2A 托管。

## Config phtml

Provider 配置模板放在：

```text
app/code/Vendor/Module/extends/module/Weline_SystemConfig/Config/backend/{code}.phtml
```

模板示例：

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
</w:config:group>
```

普通字段 key 必须使用 `payment/method/{method_code}/{field}` 前缀；运行时配置由 `Weline_SystemConfig` 解析 scope 后交给 `Weline_Payment`，Provider 模块不自己保存普通配置。

特殊授权页、回调辅助页、Provider SDK 封装、外部 API 差异由 Provider 模块自己的 controller 或 adapter 承担，最终支付状态必须回写 `Weline_Payment`。

## 资产支付与折扣

- 信用、积分、W币默认不启用。
- 同一资产在同一 Payable 上只能作为 `payment` 或 `discount` 一种角色。
- 资产动作按 `reserve -> commit -> release/refund` 处理。
- 金额使用 `amount_minor` 整数最小单位。

## 验证

- Provider 文件 `php -l` 通过。
- Config phtml `php -l` 通过。
- Provider scanner 能发现支付方式。
- 后台连接测试能调用 `testConnection()`。
- fake checkout 能展示可用支付方式、不可用原因和条款交互。
