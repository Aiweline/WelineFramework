# Stripe Checkout 配置指南

## 适用国家/地区
country_tags: US, CA, GB, AU, SG, HK, FR, DE, NL, JP, BR, MX, CN

## 支持币种
USD, EUR, GBP, CAD, AUD, JPY, HKD, SGD, CNY；以 Stripe 后台开通币种为准。

## 沙盒入口
https://dashboard.stripe.com/test/apikeys

## 正式入口
https://dashboard.stripe.com/apikeys

## 商户后台开通步骤
- 在 Stripe Dashboard 创建账号并完成商户验证。
- 打开 Developers > API keys，复制沙盒和正式 Secret Key。
- 打开 Checkout 或 Payment methods，启用需要的卡、钱包和本地支付方式。
- 在 WeShop 后台选择运行环境并保存对应密钥。

## 后台字段说明
required_fields: secret_key, success_url, cancel_url
- sandbox_secret_key / live_secret_key：Stripe Secret Key。
- webhook_secret：Stripe Webhook Signing secret。
- success_url / cancel_url：客户支付后返回地址。

## Webhook/回调 URL
在 Stripe Dashboard > Developers > Webhooks 增加 WeShop 回调 URL，监听 checkout.session.completed、payment_intent.payment_failed。

## 签名/证书要求
使用 webhook_secret 校验 Stripe-Signature，不需要上传证书。

## 测试卡/测试账号
沙盒卡 4242424242424242，任意未来有效期和 CVC。

## 上线检查清单
- live_secret_key 已保存。
- Webhook 使用正式 endpoint secret。
- success_url、cancel_url 是 HTTPS。
- 已启用目标国家常用支付方式。

## 常见错误
- invalid_api_key：环境和密钥不匹配。
- payment_method_unactivated：Stripe 后台未启用对应方式。
- webhook 签名失败：secret 复制错误或 payload 被代理改写。

## 官方文档链接
- https://docs.stripe.com/payments/checkout
- https://docs.stripe.com/payments/payment-methods/payment-method-support
