# ACSS Debit 配置指南

## 适用国家/地区
country_tags: CA

## 支持币种
CAD。

## 沙盒入口
https://dashboard.stripe.com/test/payment-methods

## 正式入口
https://dashboard.stripe.com/settings/payment_methods

## 商户后台开通步骤
- 在 Stripe 后台启用 ACSS Debit。
- 配置授权协议和付款确认流程。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。
- 使用 Webhook 更新处理中的扣款状态。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：ACSS 支付创建接口。
- sandbox_api_key / live_api_key：Stripe Secret Key。
- merchant_id：Stripe account。
- return_url / notify_url：授权返回和 webhook URL。

## Webhook/回调 URL
监听 payment_intent.processing、payment_intent.succeeded、payment_intent.payment_failed。

## 签名/证书要求
使用 Stripe webhook_secret 校验回调签名。

## 测试卡/测试账号
使用 Stripe ACSS 测试银行账户。

## 上线检查清单
- ACSS Debit 已正式开通。
- 授权文本和退款规则已确认。
- 延迟成功/失败状态已处理。
- Webhook 使用正式 secret。

## 常见错误
- mandate_invalid：授权信息缺失。
- unsupported currency：非 CAD。
- bank account verification failed：账户验证失败。

## 官方文档链接
- https://docs.stripe.com/payments/acss-debit
- https://docs.stripe.com/payments/payment-methods/payment-method-support
