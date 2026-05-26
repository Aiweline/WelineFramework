# Adyen Checkout 配置指南

## 适用国家/地区
country_tags: NL, DE, GB, FR, US, AU, SG, HK, BR, MX, CN

## 支持币种
USD, EUR, GBP, CAD, AUD, JPY, HKD, SGD, CNY；以 Adyen merchant account 开通币种为准。

## 沙盒入口
https://ca-test.adyen.com/ca/ca/login.shtml

## 正式入口
https://ca-live.adyen.com/ca/ca/login.shtml

## 商户后台开通步骤
- 在 Customer Area 创建 API credential。
- 授予 Checkout webservice API 权限。
- 复制 API key 和 merchant account。
- 在 Payment methods 启用目标国家的本地支付方式。

## 后台字段说明
required_fields: api_key, merchant_account, api_url, return_url
- sandbox_api_url：默认 https://checkout-test.adyen.com/v71/payments。
- live_api_url：正式 Checkout endpoint。
- sandbox_api_key / live_api_key：API credential key。
- merchant_account：Adyen 商户账户。
- return_url：3DS 或跳转支付返回 URL。

## Webhook/回调 URL
在 Customer Area > Developers > Webhooks 配置 Standard notification URL。

## 签名/证书要求
建议启用 HMAC，后台填写 webhook_hmac_key。

## 测试卡/测试账号
使用 Adyen test cards，例如 4111111111111111 或官方 3DS 测试卡。

## 上线检查清单
- live_api_url 使用正式 prefix。
- merchant_account 已开通所需币种。
- Webhook HMAC 已启用。
- return_url 使用 HTTPS。

## 常见错误
- 905 Payment details are not supported：支付方式未开通。
- Invalid Merchant Account：merchant_account 输入错误。
- HMAC validation failed：Webhook 密钥不一致。

## 官方文档链接
- https://docs.adyen.com/online-payments
- https://docs.adyen.com/payment-methods
