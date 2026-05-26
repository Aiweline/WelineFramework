# PayNow 配置指南

## 适用国家/地区
country_tags: SG

## 支持币种
SGD。

## 沙盒入口
https://dashboard.stripe.com/test/payment-methods

## 正式入口
https://dashboard.stripe.com/settings/payment_methods

## 商户后台开通步骤
- 在 Stripe 后台启用 PayNow。
- 确认商户实体和结算账户支持新加坡。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。
- 结账页向 SG 客户展示 PayNow。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：PayNow payment intent 接口。
- sandbox_api_key / live_api_key：Stripe Secret Key。
- merchant_id：Stripe account。
- return_url / notify_url：返回和 webhook URL。

## Webhook/回调 URL
监听 payment_intent.succeeded、payment_intent.payment_failed。

## 签名/证书要求
使用 Stripe webhook_secret 校验回调。

## 测试卡/测试账号
使用 Stripe test mode PayNow 测试 QR。

## 上线检查清单
- PayNow 已正式开通。
- QR 过期时间展示正确。
- 成功回调后再发货。
- SGD 金额处理正确。

## 常见错误
- payment_method_unactivated：PayNow 未启用。
- unsupported currency：非 SGD。
- webhook delayed：订单状态处理太早。

## 官方文档链接
- https://docs.stripe.com/payments/paynow
- https://docs.stripe.com/payments/payment-methods/payment-method-support
