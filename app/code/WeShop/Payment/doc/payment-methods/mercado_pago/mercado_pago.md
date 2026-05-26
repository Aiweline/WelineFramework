# Mercado Pago 配置指南

## 适用国家/地区
country_tags: AR, BR, MX, CL, CO, PE, UY

## 支持币种
ARS, BRL, MXN, CLP, COP, PEN, UYU；以 Mercado Pago 国家账户为准。

## 沙盒入口
https://www.mercadopago.com/developers/panel

## 正式入口
https://www.mercadopago.com/developers/panel

## 商户后台开通步骤
- 创建 Mercado Pago 开发者应用。
- 获取 test/live access token。
- 配置 Checkout Pro 或 Payments API。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：preference/payment 创建接口。
- sandbox_api_key / live_api_key：access token。
- merchant_id：user_id 或 collector_id。
- return_url / notify_url：返回和 webhook URL。

## Webhook/回调 URL
在应用 Webhooks 配置 notify_url，订阅 payments。

## 签名/证书要求
使用 Mercado Pago webhook secret/signature 校验通知。

## 测试卡/测试账号
使用 Mercado Pago 测试用户和测试卡。

## 上线检查清单
- 国家账户与币种一致。
- Checkout return URLs 已配置。
- Webhook 幂等处理 payment id。
- 现金券过期和退款已测试。

## 常见错误
- collector_id invalid：商户账户错误。
- invalid token：access token 环境错误。
- payment pending：现金券未完成付款。

## 官方文档链接
- https://www.mercadopago.com/developers/en/docs
- https://www.mercadopago.com/developers/en/docs/checkout-pro/landing
