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
使用 PayPal Sandbox buyer 和 facilitator 账号完成测试。

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
