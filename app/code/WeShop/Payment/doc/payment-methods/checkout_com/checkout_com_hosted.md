# Checkout.com Hosted Payments 配置指南

## 适用国家/地区
country_tags: GB, AE, US, FR, HK

## 支持币种
USD, EUR, GBP, AED, HKD 等 Checkout.com 支持币种。

## 沙盒入口
https://dashboard.sandbox.checkout.com/

## 正式入口
https://dashboard.checkout.com/

## 商户后台开通步骤
- 在 Checkout.com Dashboard 创建 API key。
- 启用 Hosted Payments Page 或 Payments API。
- 开通目标国家的卡、本地支付和钱包。
- 在 WeShop 后台填写环境、API URL、API Key 和商户 ID。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：支付创建接口。
- sandbox_api_key / live_api_key：Secret key。
- merchant_id：商户或 processing channel 标识。
- return_url / notify_url：返回和 Webhook URL。

## Webhook/回调 URL
在 Developers > Webhooks 添加 notify_url，订阅 payment_approved、payment_declined、payment_captured。

## 签名/证书要求
使用 webhook_secret 校验 Checkout.com Webhook 签名。

## 测试卡/测试账号
使用 Checkout.com 官方测试卡 4242424242424242。

## 上线检查清单
- Live processing channel 已配置。
- Webhook secret 已保存。
- 3DS 和风控规则已配置。
- 每个目标币种完成测试。

## 常见错误
- invalid_secret_key：环境和 key 不一致。
- payment_method_not_supported：支付方式未开通。
- processing_channel_id 错误导致拒付。

## 官方文档链接
- https://www.checkout.com/payment-method/accept
- https://api-reference.checkout.com/
