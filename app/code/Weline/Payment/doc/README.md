# Weline_Payment

`Weline_Payment` 是统一支付抽象层。业务模块通过 Payable 接入可支付对象，支付模块通过 Provider 接入支付能力，核心层负责配置、可用性判断、支付请求、退款请求、回调校验和结果归一化。

Marketing 是可选折扣动作目录：存在时提供动作元数据，不存在时支付核心返回空动作目录并继续工作。

## 核心接口

### ProviderInterface

所有新支付方式必须实现 `Weline\Payment\Interface\ProviderInterface`。

必实现能力包括：

- `getCode()` / `getProviderCode()`：支付方式 code 与 Provider code。
- `getProviderApiVersion()` / `getWebhookSchemaVersion()`：接口与回调版本。
- `getCapabilities()`：货币、国家、退款、授权、捕获、void、动态表单等能力声明。
- `getDisplayMetadata()`：后台和 checkout 展示元数据。
- `getConfigSchema()`：运行时配置校验或 Provider 元数据；后台配置 UI 由 `Weline_SystemConfig` 的 config phtml 提供。
- `checkAvailability()`：按 Payable、scope、货币、国家、金额和配置判断是否可用。
- `createPayment()` / `resumePayment()` / `authorize()` / `capture()` / `void()`：支付生命周期动作。
- `refund()` / `query()`：退款与查询。
- `verifyCallback()` / `parseCallback()`：回调验签和事件解析。
- `testConnection()`：后台配置测试。
- `normalizeError()`：Provider 错误归一化。

Provider 不接收全局运行期配置状态。有效配置由 `Weline_Payment` 在请求 DTO 的 `context.runtime_config` 中下发。

### PayableResolverInterface

业务模块通过 `Weline\Payment\Interface\PayableResolverInterface` 接入可支付对象。订单、应用商城、A2A、托管单或其他业务对象都应提供自己的 PayableResolver；简单场景可以使用默认 PayableResolver。

### 配置模板

支付方式配置表单由 `Weline_SystemConfig` 的配置模板扩展点提供，不由 `Weline_Payment` 另行定义配置模板扩展路径。

Provider 模块把配置模板放在自己的 SystemConfig 扩展路径下：

```text
extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml
```

`{area}` 按配置使用场景命名，例如 `backend`；`{code}` 建议与支付方式 code 或 provider code 保持一致，便于后台配置、运行时配置快照和支付方式绑定排查。

## 扩展路径

完整第三方支付模块开发流程见 [provider-development.md](provider-development.md)。

支付方式扩展路径：

```text
extends/module/Weline_Payment/PaymentProvider/
```

Payable 扩展路径：

```text
extends/module/Weline_Payment/PayableResolver/
```

Provider 模块的最小交付物是：

- Provider 接口实现类：放在 `extends/module/Weline_Payment/PaymentProvider/`。
- checkout phtml：由该 Provider 模块负责前台特殊展示或输入字段。
- config phtml：放在 `extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml`。

特殊授权页、回调辅助页、Provider SDK 封装或外部 API 差异由 Provider 模块自己的 controller 或 adapter 承担，最终支付状态仍回写 `Weline_Payment`。

## Fake Provider

模块内置 `fake_card` Provider：

```text
app/code/Weline/Payment/extends/module/Weline_Payment/PaymentProvider/FakeProvider.php
```

它实现完整 `ProviderInterface`，用于本地 fake 模式、接口契约验证和浏览器冒烟，不用于真实收款。

## Checkout Fake 页面

浏览器 fake 页面：

```text
/payment/frontend/checkout/fake
```

该页面渲染 checkout 支付选择模板，展示可用 fake 支付方式和不可用资产支付示例，用于验证支付方式选择、更多不可用项、禁用原因、支付条款勾选，以及通过 `ProviderInterface` 生成的成功、失败、取消和退款可见结果。

## 折扣能力

支付方式参与活动折扣必须由后台配置或 Provider capabilities 显式声明。未声明时不默认支持折扣。

常见 capability key：

```php
[
    'supported_discount_actions' => ['discount_fixed_amount', 'discount_percentage'],
]
```

跨模块校验只能依赖
`Weline\Payment\Api\Discount\DiscountActionSupportInterface`，不得引用
`Payment\Service` 或 `Payment\Model`。营销动作元数据是可选集成：
`Weline_Marketing` 通过 `ActionCatalogInterface` 只提供不可变描述数据，
`Weline_Payment` 不感知 Marketing `RuleEngine` 或动作实例。未安装
Marketing 时动作目录为空，支付核心仍可独立启动。

## 跨模块支付编排契约

结账、订单或其他业务模块发起支付时，使用
`Weline\Payment\Api\PaymentFacadeInterface`。返回值是纯数据
`PaymentTransactionRecord`，不暴露 Payment ORM 模型、表字段常量或内部服务。

`tryCreatePayment()` 只在支付方式不存在或当前作用域未启用时返回
`null`；Provider、持久化或协议错误会显式抛出，不会被误判为“无此支付方式”。

## 支付方式自定义属性

`PaymentMethodAttributeEntity` 只是支付方式的 EAV 实体定义：它继续映射现有 `payment_method` 表，属性值的 `entity_id` 仍是 `method_id`，但不再继承 Eav 内部 `EavModel`。

`PaymentMethodAttributeHelper` 只依赖：

- `Weline\Eav\Api\Entity\EntityDefinitionInterface`
- `Weline\Eav\Api\Attribute\EntityAttributeStoreInterface`
- 不可变 `AttributeDefinition` / `AttributeRecord`

属性 ORM、属性集/组/类型查询、PostgreSQL 序列同步、以及 `eav_payment_method_{type}` 值表读写全部由 `Weline_Eav` Provider 所有。Payment 不得引用 `Weline\Eav\Model` / `Service` / 根命名空间实现类。

## 验证建议

- `php -l app/code/Weline/Payment/**/*.php`
- Provider scanner 应能发现 `fake_card`。
- 后台配置测试应调用 Provider `testConnection()`。
- checkout fake 页面应能在浏览器中打开并完成支付方式选择、条款勾选、fake 支付成功/失败/取消/退款状态切换。
