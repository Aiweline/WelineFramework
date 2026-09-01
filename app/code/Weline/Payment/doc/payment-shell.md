# 万能支付壳（Weline_Payment）

`Weline_Payment` 是唯一支付内核（壳）。业务通过 Payable 接入；支付能力通过 Provider 按 `method_code` 接入。壳拥有统一 URL、编排、状态机、配置托管与幂等；Provider 只实现网关差异。

相关：[README.md](README.md)、[provider-development.md](provider-development.md)、[extends.md](extends.md)、[webhook.md](webhook.md)。

## 1. 边界

### 壳必须拥有

| 职责 | 说明 |
|---|---|
| 身份路由 | `method_code`，或可还原的 `endpoint_code` / OAuth `state` |
| 统一 URL | 浏览器 `payment/frontend/callback/return?target_scope={storage_scope}`；Webhook `payment/frontend/callback/notify` |
| 编排与状态 | Intent / Attempt / Transaction / Refund / Ledger / Inbox |
| 配置托管 | 通用字段 + `payment/method/{method_code}/*` |
| 连接入口 | `payment/backend/connect/*?method_code=` |
| 结账壳 | 方式列表、提交 API、结果页、NextAction 契约 |
| 幂等与重放 | 创建支付幂等键、Webhook Inbox、状态 CAS |

### Provider 必须拥有

| 职责 | 说明 |
|---|---|
| `ProviderInterface` | 支付生命周期 + verify/parse + 测连 |
| 专属配置 | schema + config phtml |
| 可选 `ProviderConnectInterface` | OAuth / 一键授权，经壳调度 |
| 结账呈现 | `checkout_mode` + 模板或仅 `next_action`；iframe/SDK 自担 |

### 禁止

- 壳 `Callback` / Facade 硬编码某网关（如直接依赖 `PayPalOAuthService`）。
- 每个 Provider 再登记一条独立 Developer Return URL（本仓统一一条）。
- Provider checkout 模板直接改订单/库存/支付终态或绕过 Facade。
- 新代码实现已废弃的 `PaymentProviderInterface`（只用 `ProviderInterface`）。

## 2. 统一 URL

| 用途 | 路径 |
|---|---|
| 浏览器 OAuth / 支付回跳 | `https://{public_origin}/payment/frontend/callback/return?target_scope={website.store.channel}` |
| Webhook | `https://{public_origin}/payment/frontend/callback/notify?endpoint_code={method_code}.{env}.default` |
| 一键授权 | `payment/backend/connect/authorize?method_code={code}&environment=sandbox\|live` |
| 连接测试 | `payment/backend/connect/test?method_code={code}&environment=...` |

常量：`Weline\Payment\Service\PaymentBrowserCallbackRoutes`。

浏览器 return：state（或查询）含 `method_code` → `PaymentBrowserReturnDispatcher` → Connect.complete 或 `resumePayment`。

## 3. 第三方最小交付

1. `extends/module/Weline_Payment/PaymentProvider/{X}Provider.php`
2. `extends/module/Weline_SystemConfig/Config/backend/{method_code}.phtml`
3. （推荐）`view/templates/Frontend/checkout/{method_code}.phtml` + CustomerGuide

`getCode()`、config 文件名、`checkout_template_code` 三者一致。细节见 [provider-development.md](provider-development.md)。

## 4. 结账三层

| 层 | 谁写 | 内容 |
|---|---|---|
| A. 结账壳 | `Weline_Payment` | 方式列表、提交、结果页；只调 Facade |
| B. 内置呈现 | 壳可选模板 | redirect / qr / poll / 演示卡（非 PCI） |
| C. Provider 呈现 | Provider 模块 | 自定义 phtml 或 iframe/SDK；只渲染与收集 |

`getDisplayMetadata()['checkout_mode']`：

- `shell`：壳内置呈现 + `next_action`
- `template`：Provider `checkout_template_code`
- `hybrid`：壳外框 + Provider 槽（iframe/SDK 默认）

未声明：有 `checkout_template_code` → `template`，否则 `shell`。

### NextAction

`PaymentOperationResult`：`none` / `redirect` / `qr` / `sdk` / `poll` / `iframe`。

| type | 壳 | Provider |
|---|---|---|
| `none` | 结果页 | 终态或等 webhook |
| `redirect` | 继续支付按钮 | `redirect_url` |
| `qr` | 二维码 + 轮询 | image/url |
| `poll` | 轮询状态 | interval |
| `sdk` | 挂载 Provider 槽 | 加载 SDK |
| `iframe` | 安全容器契约 | `iframe_url` 或自绘；PCI/CSP 自担 |

槽位：`data-payment-slot="provider-checkout"`。事件约定：`weline:payment:ready` / `weline:payment:tokenize` / `weline:payment:error`。提交必须带 idempotency key；禁止 iframe 脚本直接改 Intent。

生产卡支付：`hybrid` + Provider iframe/SDK；壳不碰卡号。

## 5. 重复通知 / 幂等

### Webhook（已满足）

| 场景 | 行为 |
|---|---|
| 同 `endpoint_code` + `provider_event_id` 重放 | 200，不新建 Inbox |
| 同 event、不同 `payload_hash` | 409 conflict |
| 缺 `provider_event_id` | 400 |
| Consumer | CAS + 确定性 effect key |

接收只入箱；消费才推进状态。`verifyCallback` / `parseCallback` 必须纯函数。详见 [webhook.md](webhook.md)。

### 缺口与要求

| 点 | 要求 |
|---|---|
| 浏览器 return 重复打开 | Connect.complete / resume 必须幂等 |
| 裸访问统一 Return URL | **开发**：渲染壳落地页并说明情况；**生产**：空参/无效直接跳转站点首页 |
| 稳定 `provider_event_id` | Provider 必须提供；指南强制 |
| 乱序通知 | 以壳状态机为准，倒序 ignored；对账兜底 |
| 结账重复点击 | 强制 idempotency key |
| DevRelay 重放 | 同一幂等键，不得双记账 |

## 6. 内置 Provider

| method_code | provider_code | 说明 |
|---|---|---|
| `fake_card` | `fake` | 本地测试样板，无 OAuth |
| `paypal` | `paypal` | 内置网关；Connect/OAuth 在 Provider 包内，经壳调度 |

## 7. 反模式

- 在 `Callback.php` 注入具体网关 OAuth 服务。
- 为每个网关登记不同 Return URL。
- checkout 模板直调网关并写支付终态。
- 新实现 `PaymentProviderInterface`。
