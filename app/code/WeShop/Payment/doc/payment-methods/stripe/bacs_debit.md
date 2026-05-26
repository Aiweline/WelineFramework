# Bacs Direct Debit 配置指南

## 适用国家/地区
country_tags: GB

## 支持币种
GBP。

## 沙盒入口
https://dashboard.stripe.com/test/payment-methods

## 正式入口
https://dashboard.stripe.com/settings/payment_methods

## 商户后台开通步骤
- 在 Stripe 后台启用 Bacs Direct Debit。
- 配置 Direct Debit Guarantee 文案。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。
- 使用 Webhook 更新扣款状态。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Bacs 支付接口。
- sandbox_api_key / live_api_key：Stripe Secret Key。
- merchant_id：Stripe account。
- return_url / notify_url：授权返回和 webhook URL。

## Webhook/回调 URL
监听 payment_intent.processing、payment_intent.succeeded、payment_intent.payment_failed。

## 签名/证书要求
使用 Stripe webhook_secret 校验 Stripe-Signature。

## 测试卡/测试账号
使用 Stripe Bacs 测试 sort code 和 account number。

## 上线检查清单
- Bacs 正式开通。
- Guarantee 文案已显示。
- 处理期订单状态已设计。
- 失败和退款流程已测试。

## 常见错误
- mandate missing：客户授权未创建。
- processing 被误认为 paid。
- sort code/account number 格式错误。

## 官方文档链接
- https://docs.stripe.com/payments/bacs-debit
- https://docs.stripe.com/payments/payment-methods/payment-method-support
