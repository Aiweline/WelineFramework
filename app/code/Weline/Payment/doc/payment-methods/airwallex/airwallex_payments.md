# Airwallex Payments 配置指南

## 适用国家/地区
country_tags: HK, SG, AU, GB, US, CN

## 支持币种
USD, EUR, GBP, HKD, SGD, AUD, CNY 等 Airwallex 支持币种。

## 沙盒入口
https://demo.airwallex.com/

## 正式入口
https://www.airwallex.com/app/

## 商户后台开通步骤
- 开通 Airwallex 账户并启用 Payments。
- 在 Developer > API keys 创建 key。
- 配置 Webhook endpoint。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Payment Intent 创建接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：Airwallex 账户或商户标识。
- return_url / notify_url：支付返回和 Webhook URL。

## Webhook/回调 URL
在 Airwallex Webhooks 添加 notify_url，订阅 payment_intent.succeeded、payment_intent.payment_failed。

## 签名/证书要求
使用 webhook_secret 校验 Airwallex Webhook 签名。

## 测试卡/测试账号
使用 Airwallex demo 环境测试卡。

## 上线检查清单
- 正式账户 KYC 已完成。
- live API key 已保存。
- Webhook 签名验证通过。
- 全球账户收款币种与结算币种已确认。

## 常见错误
- unauthorized：API key 环境错误。
- payment method unavailable：国家或币种未开通。
- webhook signature invalid：secret 错误。

## 官方文档链接
- https://www.airwallex.com/docs/payments/payment-methods/payment-methods-overview
- https://www.airwallex.com/docs/api
