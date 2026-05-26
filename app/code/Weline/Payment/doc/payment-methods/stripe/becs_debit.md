# BECS Direct Debit 配置指南

## 适用国家/地区
country_tags: AU

## 支持币种
AUD。

## 沙盒入口
https://dashboard.stripe.com/test/payment-methods

## 正式入口
https://dashboard.stripe.com/settings/payment_methods

## 商户后台开通步骤
- 在 Stripe 后台启用 BECS Direct Debit。
- 配置直接扣款授权协议。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。
- 通过 Webhook 处理成功、失败和退款。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：BECS 支付创建接口。
- sandbox_api_key / live_api_key：Stripe Secret Key。
- merchant_id：Stripe account。
- return_url / notify_url：授权返回和 webhook URL。

## Webhook/回调 URL
监听 payment_intent.processing、payment_intent.succeeded、payment_intent.payment_failed。

## 签名/证书要求
使用 Stripe webhook_secret 校验回调签名。

## 测试卡/测试账号
使用 Stripe BECS 测试 BSB 和账号。

## 上线检查清单
- BECS 已正式开通。
- 客户授权文本符合本地要求。
- 延迟状态不触发立即发货。
- Webhook 幂等已处理。

## 常见错误
- invalid_bsb：BSB 格式错误。
- mandate_invalid：授权信息不完整。
- webhook 重复触发状态回退。

## 官方文档链接
- https://docs.stripe.com/payments/au-becs-debit
- https://docs.stripe.com/payments/payment-methods/payment-method-support
