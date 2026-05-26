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
- webhook_id：用于后续 Webhook 验签。

## Webhook/回调 URL
在 Developer Dashboard > Webhooks 配置 WeShop 回调 URL，至少订阅 CHECKOUT.ORDER.APPROVED、PAYMENT.CAPTURE.COMPLETED。

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

## 官方文档链接
- https://developer.paypal.com/docs/checkout/
- https://developer.paypal.com/api/rest/
