# 第三方物流跟踪 Provider 开发指南

本文面向接入 `Weline_Order` 跟踪壳的第三方物流模块。新承运商不继承抽象类，只实现 `TrackingProviderInterface`，并交付图标、名称与完整流程阶段。

## 1. 最小模块结构

```text
app/code/Vendor/YourCarrier/
├─ extends/module/Weline_Order/TrackingProvider/YourCarrierProvider.php
├─ view/statics/img/tracking/your-carrier.svg
├─ i18n/zh_Hans_CN.csv
├─ i18n/en_US.csv
└─ register.php
```

## 2. Provider 类

路径：

```text
extends/module/Weline_Order/TrackingProvider/YourCarrierProvider.php
```

命名空间：

```php
namespace Vendor\YourCarrier\Extends\Module\Weline_Order\TrackingProvider;
```

必须实现 `Weline\Order\Interface\TrackingProviderInterface` 全部方法。

| 函数 | 必须处理 |
| --- | --- |
| `getCode()` | 稳定 method code，例如 `sf_express` |
| `getProviderCode()` | 网关/通道 code |
| `getProviderApiVersion()` / `getWebhookSchemaVersion()` | 版本写入快照 |
| `getCapabilities()` | formal_carrier、feedback_webhook、carrier_aliases 等 |
| `getDisplayMetadata()` | **必须**非空 `icon_url`/`icon`、`title`；建议 `description` |
| `getFlowStages()` | 完整阶段列表（code/label/sort/icon） |
| `queryTracking()` | 按订单/运单返回 stages + nodes + summary |
| `verifyFeedback()` / `parseFeedback()` | **纯函数**：不写库、不改订单状态 |
| `testConnection()` / `normalizeError()` | 测连与错误归一化 |

## 3. 反馈回调

统一登记：

`https://{host}/order/frontend/tracking-callback/notify?endpoint_code={method}.sandbox.default`

壳流程：endpoint → Provider verify → parse → 不可变 Inbox → 事件 `Weline_Order::order_tracking_feedback_received`。

本地联调见 [dev-tracking-relay.md](dev-tracking-relay.md)。

## 4. 无物流商

不要为「无承运商」写 Provider。发货未绑定 `tracking_provider_code` 时，壳自动走 `system`：展示「已发货 / 发往目的地」。

## 5. 校验

```bash
php -l app/code/Vendor/YourCarrier/extends/module/Weline_Order/TrackingProvider/YourCarrierProvider.php
php bin/w setup:upgrade --route
```
