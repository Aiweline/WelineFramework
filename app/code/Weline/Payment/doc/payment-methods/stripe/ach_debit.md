# ACH Debit 配置指南

## 适用国家/地区
country_tags: US

## 支持币种
USD。

## 沙盒入口
https://dashboard.stripe.com/test/payment-methods

## 正式入口
https://dashboard.stripe.com/settings/payment_methods

## 商户后台开通步骤
- 在 Stripe 后台启用 US bank account/ACH Direct Debit。
- 完成公司身份和银行扣款权限审核。
- 在 WeShop 后台填写 Stripe API 配置或 ACH 专用托管接口。
- 在结账页收集客户授权。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：ACH payment intent 或 checkout 接口。
- sandbox_api_key / live_api_key：Stripe Secret Key。
- merchant_id：Stripe account 或 connected account。
- return_url / notify_url：授权返回和 webhook URL。

## Webhook/回调 URL
监听 payment_intent.processing、payment_intent.succeeded、payment_intent.payment_failed。

## 签名/证书要求
使用 Stripe webhook_secret 校验 Stripe-Signature。

## 测试卡/测试账号
使用 Stripe 测试银行账户和 microdeposit 测试码。

## 上线检查清单
- ACH 已在正式环境启用。
- 客户授权文本已展示。
- 处理中状态不会误判为已支付。
- 退票和失败回调已处理。

## 常见错误
- payment_method_unactivated：ACH 未启用。
- verification_failed：银行账户验证失败。
- 订单过早发货：ACH 处理中未等成功。

## 官方文档链接
- https://docs.stripe.com/payments/ach-debit
- https://docs.stripe.com/payments/payment-methods/payment-method-support
