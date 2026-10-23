# PayPal 配置指南

## 适用国家/地区
country_tags: US, GB, DE, CA, AU, FR, IT, ES, JP, HK, SG

## 支持币种
USD, EUR, GBP, CAD, AUD, JPY, HKD, SGD 等 PayPal 支持币种。

## 沙盒入口
https://developer.paypal.com/dashboard/applications/sandbox

## 正式入口
https://developer.paypal.com/dashboard/applications/live

## 商户后台开通步骤
- 登录 PayPal Developer Dashboard。
- 创建 REST App，分别获取 sandbox/live Client ID 和 Secret。
- 在 Live 前确认商户账号完成收款审核。
- 在 WeShop 后台填写返回 URL 和取消 URL。

## 后台字段说明
required_fields: client_id, client_secret, return_url, cancel_url
- sandbox_client_id / live_client_id：REST App Client ID。
- sandbox_client_secret / live_client_secret：REST App Secret。
- return_url / cancel_url：PayPal 跳转回站点的 URL。
- webhook_id：PayPal Developer 注册的 Webhook ID；配置后 Provider 会调用 `/v1/notifications/verify-webhook-signature` 验真签（官方要求）。未配置且无 Transmission 头时，仅允许本地注入/无签名联调。
- google_pay_enabled / apple_pay_enabled：默认开启。控制 PayPal JS SDK 的 `enable-funding` / `disable-funding`（googlepay、applepay）。关闭后本站不再展示对应钱包按钮。需商户侧具备 Google/Apple Pay 能力；Apple Pay 生产还需域名登记（完整收单见 Phase 2）。
- card_fields_enabled：默认开启。结账页选中 PayPal 时，在站内展开银行卡收银台（PayPal Card Fields / Advanced Credit and Debit Card）。需商户具备 `advanced_cards` 资格；前端会通过 `sdk.findEligibleMethods({currencyCode}).isEligible("advanced_cards")` 实时检测，不满足、币种需折算、拆单或 express 场景自动回退到 PayPal 跳转。

## 站内银行卡收银台（Card Fields / ACDC）

### 能力边界

- 卡号、有效期、CVV 全部在 PayPal 托管 iframe 内输入，本站不接收、不落库、不打印原始卡数据；PCI 范围按 PayPal 的 SAQ A-EP 说明执行。
- 本站只负责：创建 PayPal order（`payment_source.card.experience_context`）→ 前端 `cardSession.submit(orderId)` → 服务端 `capture`。
- 3DS/SCA 由 PayPal SDK 弹出；建单默认传 `attributes.verification.method=SCA_WHEN_REQUIRED`（PSD2 区域自动触发，非 PSD2 区域不强制）。
- 不做保存卡（vault）、订阅、多订单合并 capture；这些场景回退跳转。

### 链路

1. 结账页选中 PayPal；`Weline_Payment` 前端模块读取 `payment.paypalCardFieldsConfig`（只返回公开 client_id、SDK URL、币种、CSS URL）。
2. SDK 检测 `advanced_cards` 资格后展开卡字段；资格不满足直接隐藏，不影响原跳转。
3. 买家点「提交订单」：
   - 前端 `checkout.submitV2({payment_method:'paypal', client_action:true})`；
   - 服务端 `CheckoutOrderPaymentService::pay()` → `PayPalProvider::createPayment()` 建 `payment_source.card` 单，返回通用 `payment.client_action`（`type=card_fields`，`data` 为 PayPal 单号/SDK 配置）；
   - 前端 Payment handler 读取 `client_action.data`，`cardSession.submit(order_id, {billingAddress})` 完成卡输入与 3DS；
   - 前端 `checkout.confirmPaymentV2({order_uuid, transaction_no, quote_token, idempotency_key, provider_reference})`；
   - 服务端 `PaymentService::confirmPayment()` → `PayPalProvider::resumePayment()` capture，事务落 success，`notifyOrderPaid` 更新订单。
4. 任一环节失败：前端展示可重试错误；服务端未 capture 时不改订单状态，避免假成功/重复扣款。

### 降级规则（必须保持）

- `card_fields_enabled=0`、无 client_id、SDK URL 为空、币种需折算（如沙箱 CNY→USD）、订单拆分、express 上下文、前端资格失败 → 一律回退 PayPal 跳转。
- 服务端 `sanitizeResponse()` 只向前端暴露通用 `client_action`（`type` / `method_code` / `provider_code` / 标量 `data`），不透传 provider 原始 payload。
- **解耦边界**：Checkout 只知道 `client_action` / `confirmPaymentV2` 两个通用扩展点；`card_fields` 的 `type`、卡字段 SDK 配置和 PayPal capture 细节全部由 Payment 模块及其前端 handler 负责。结账页不引用 `WelinePayPalCardFields` 等模块全局对象，Payment handler 通过 `window.WelineCheckout.registerPaymentHandler('paypal', ...)` 注册。

### 验收与排查

- 单测：`php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php app/code/Weline/Payment/Test/Unit/Service/PayPalCardFieldsTest.php`
- Checkout 单测：`php vendor/bin/phpunit --bootstrap app/code/Weline/Checkout/test/Unit/bootstrap.php app/code/Weline/Checkout/test/Unit/Service/CheckoutOrderPaymentServiceTest.php`
- 前端语法：`node --check app/code/Weline/Payment/view/statics/js/paypal-card-fields.js`
- 常见失败：
  - 卡字段不出现：商户无 `advanced_cards` 资格 / `card_fields_enabled=0` / 币种不一致 / CSP 拦截 `c.paypal.com`。
  - `confirmPaymentV2` 400：`quote_token` 已过期或订单不属于该 checkout session；检查 `checkout_payment_session_not_found` / `checkout_payment_order_scope_mismatch`。
  - 卡字段 iframe 空白：检查 CSP `script-src`/`frame-src` 是否包含 `https://c.paypal.com` 与 `https://www.paypal.com`。

## Webhook/回调 URL
在 Developer Dashboard > Webhooks 配置 WeShop 回调 URL，建议至少订阅：

- 支付：`CHECKOUT.ORDER.APPROVED`、`PAYMENT.CAPTURE.COMPLETED`、`PAYMENT.CAPTURE.PENDING`、`PAYMENT.CAPTURE.DENIED`
- 退款/撤销：`PAYMENT.CAPTURE.REFUNDED`、`PAYMENT.CAPTURE.REVERSED`
- 争议/风控：`CUSTOMER.DISPUTE.CREATED`、`CUSTOMER.DISPUTE.UPDATED`、`CUSTOMER.DISPUTE.RESOLVED`

线上 URL 形态：`https://www.aiweline.com/payment/frontend/callback/notify?endpoint_code=paypal.sandbox.default`  
本地开发通过 DevRelay 中继拉取并重放，见 [`doc/dev-webhook-relay.md`](../../dev-webhook-relay.md)。

## 发货物流回传（Add Tracking）

### 功能

订单用 PayPal（Orders v2 Checkout）支付成功后，本站发货/后补运单号时，自动调用官方推荐接口：

`POST /v2/checkout/orders/{checkout_order_id}/track`

（见 [Add package tracking](https://developer.paypal.com/docs/tracking/) → [Orders v2 integrate](https://developer.paypal.com/docs/tracking/orders-api/integrate/)）

链路：`Weline_Order::order_shipped` → Payment 壳 → `PayPalProvider::syncShipmentTracking` → 上述 `/track`。

**本站没有单独开关。** 启用 PayPal + 凭据可用即可。

### 在本站哪里看 / 哪里配

| 目的 | 位置 |
|---|---|
| 阅读说明 | 后台 **业务运营 → 支付管理 → 支付方式 → PayPal** →「发货物流回传（Add Tracking）」 |
| 触发回传 | 订单发货/履约：填运单号并保存 |
| 审计 | 支付交易 `response_data.paypal_tracking_sync` |
| 手动补传 | `php bin/w payment:paypal:sync-tracking --order-uuid=… --tracking-number=… --carrier=SF` |

### PayPal 控制台：不需要勾选 Shipping

**默认就支持，不必在 App Features 勾任何 Shipping 项**（新版 Features 页也没有该项）。

本站用 Checkout Orders v2，发货回传走官方推荐的 `/v2/checkout/orders/{id}/track`。只要 PayPal 支付方式已启用、凭据可用、capture 仍为 `COMPLETED`，发货填运单号即可。

### 手动补传

```bash
php bin/w payment:paypal:sync-tracking --order-uuid=ORDER_UUID --tracking-number=SF1234567890 --carrier=SF
```

## 签名/证书要求
生产环境应使用 PayPal Webhook ID 验证回调签名。

## 测试卡/测试账号
沙盒 REST App（Client ID / Secret）提交在 `etc/platform/paypal.sandbox.bundled.php`。local/dev 没有本机覆盖时，`PayPalPlatformCredentialService` 用它跑沙盒支付。本机 `app/etc/payment/paypal.platform.sandbox.php`、`env.php` 或环境变量优先；生产/预发不读 bundled。

沙盒买家登录提交在 `etc/platform/paypal.sandbox.buyer.json`。e2e 经 `tests/e2e/framework/paypal-sandbox-buyer.js` 读取，可用 `PAYPAL_SANDBOX_BUYER_EMAIL` / `PAYPAL_SANDBOX_BUYER_PASSWORD` 覆盖。

## 上线检查清单
- live_client_id 和 live_client_secret 已填写。
- return_url、cancel_url 是 HTTPS。
- 商户账户可收款。
- Webhook 使用正式 App。

## 常见错误
- invalid_client：Client ID/Secret 或环境错误。
- payer-action 链接为空：订单创建响应异常。
- capture 失败：订单未由买家批准或已过期。
- **NOT_AUTHORIZED / CAPTURE_STATUS_NOT_VALID（物流回传）**：默认已走 Orders v2 `/track`，不依赖 Features 勾选。若 capture 已全额退款会报状态不合法；换未退款的 COMPLETED 单即可。

## 官方文档链接
- https://developer.paypal.com/docs/checkout/
- https://developer.paypal.com/api/rest/
