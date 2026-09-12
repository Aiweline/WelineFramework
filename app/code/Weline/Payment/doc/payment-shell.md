# 万能支付壳（Weline_Payment）

`Weline_Payment` 是唯一支付内核（壳）。业务通过 Payable 接入；支付能力通过 Provider 按 `method_code` 接入。壳拥有统一 URL、编排、状态机、配置托管与幂等；Provider 只实现网关差异。

相关：[README.md](README.md)、[provider-development.md](provider-development.md)、[extends.md](extends.md)、[webhook.md](webhook.md)。

## 1. 边界

### 壳必须拥有

| 职责 | 说明 |
|---|---|
| 身份路由 | `method_code`，或可还原的 `endpoint_code` / OAuth `state` |
| 统一 URL | 浏览器唯一 `payment/frontend/callback/{method_code}?target_scope={storage_scope}`（取消加 `outcome=cancel`）；Webhook `payment/frontend/callback/notify` |
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
- 在壳 Controller / 壳 Service **重写**某支付方式的创建/退款/回调解析/能力判定（供应商业务必须在 Extends Provider；MCP `shell_provider_business_isomorph`）。
- 每个 Provider 再登记一条独立 Developer Return URL（本仓统一一条）。
- Provider checkout 模板直接改订单/库存/支付终态或绕过 Facade。
- 新代码实现已废弃的 `PaymentProviderInterface`（只用 `ProviderInterface`）。

## 2. 统一 URL

| 用途 | 路径 / query |
|---|---|
| Developer 登记 Return | `.../callback/{method_code}?target_scope={storage_scope}` |
| 运行时 Return | `.../callback/{method_code}?target_scope=...&transaction_no=...&shell_token=...` |
| 浏览器取消 | 同路径 + `outcome=cancel`（+ transaction_no / shell_token）→ 有 `browser_landing_url` / `order_uuid` 直达 Checkout L3 `/checkout/success?outcome=cancel`；否则 status 页带 `outcome=cancel` 展示「支付已取消」 |
| Webhook | `.../callback/notify?endpoint_code={method_code}.{env}.default` |
| 一键授权 | `payment/backend/connect/authorize?method_code={code}&environment=sandbox\|live` |

常量：`PaymentBrowserCallbackRoutes`；**唯一 URL 生成**：`PaymentShellCallbackUrlCatalog`（`browserReturnRegister` / `browserReturn` / `browserCancel` / `webhookNotify` / `browserFailure`）。创建支付时由 `PaymentService::createPayment` 注入带 `shell_token` 的运行时 URL，禁止 Controller 手拼。

### shell_token

HMAC 签名 token（`PaymentBrowserCallbackTokenService`），解码得 `method_code`、`transaction_no`、`target_scope`。Dispatcher 优先读 token，其次读显式 `method_code`+`transaction_no`，再读网关异构 param（`token`/`session_id`/`out_trade_no` 等）。

浏览器 return：`shell_token` 或显式支付码 → `PaymentBrowserReturnContextResolver` → 按 `method_code` 实例化 Provider → `resumePayment`（网关 reference 单独传入）。OAuth 仍走 `code+state`。

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
| `paypal` | `paypal` | 内置网关；Connect/OAuth 在 Provider 包内，经壳调度；**快捷智能支付**声明 `express_checkout`（GET_FROM_FILE 取址） |

## 6.1 快捷智能支付（Express）

- 能力位：`express_checkout`（可选 `express_modes` / `express_next_action`）
- 壳 Facade：`PaymentExpressFacadeInterface` → `ExpressCheckoutOrchestrator`
- 地址回写 SPI：`payment.express_address_sink.*`（Checkout 提供配送上下文实现）
- 结账槽 `checkout-express-payment` 与 PDP 槽 `product-express-payment` 都只渲染壳 `listExpressMethods`
- PDP：`startExpressCheckout` → 打开支付商窗体；回跳 `express_prepare_only`（不 capture）→ `/checkout/express-review` 轻量确认 → `express_confirm_capture`
- PayPal express：`user_action=CONTINUE`；确认前可 `patchOrder`；元数据 `express_awaiting_confirm`
- 支付商核心地址只读（卖家保护）；缺口字段（phone/email）在确认页补全
- **后台开关（万能支付 SystemConfig，默认开启）**：
  - 壳级：`payment/general/express_checkout_enabled`（支付核心配置 → 启用快捷智能支付）
  - 方式级：`payment/method/paypal/express_enabled`（PayPal 支付方式 → 启用 PayPal 快捷支付）；`express_enabled=false` 时收窄掉 `express_checkout` 能力
  - 布局级：主题部件参数「展示快捷支付」仅控制槽位渲染，与上两项独立
- 禁止：在部件或 Checkout 内硬编码某一网关的取址逻辑；禁止 HelpPay 短链充当快捷智能支付；壳列表为空时禁止假按钮兜底；禁止 PDP 快捷跳完整结账表单

## 7. 反模式

- 在 `Callback.php` 注入具体网关 OAuth 服务。
- 为每个网关登记不同 Return URL。
- checkout 模板直调网关并写支付终态。
- 新实现 `PaymentProviderInterface`。
