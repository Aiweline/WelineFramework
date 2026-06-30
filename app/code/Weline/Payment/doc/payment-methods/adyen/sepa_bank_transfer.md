# SEPA 银行转账配置指南

## 适用国家/地区
country_tags: AT, BE, CY, DE, EE, ES, FI, FR, GR, HR, IE, IT, LT, LU, LV, MT, NL, PT, SI, SK

## 支持币种
EUR。

## 沙盒入口
https://ca-test.adyen.com/ca/ca/login.shtml

## 正式入口
https://ca-live.adyen.com/ca/ca/login.shtml

## 商户后台开通步骤
- 在 Adyen Customer Area 启用 SEPA Direct Debit 或 Bank Transfer。
- 配置 merchant account 欧元结算。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。
- 在结账页展示授权或转账说明。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Adyen SEPA 支付接口。
- sandbox_api_key / live_api_key：Checkout API key。
- merchant_id：merchant account。
- return_url / notify_url：返回和通知 URL。

## Webhook/回调 URL
在 Adyen Webhooks 配置 Standard notification URL。

## 签名/证书要求
使用 Adyen HMAC key 验证通知。

## 测试卡/测试账号
使用 Adyen SEPA 测试 IBAN，例如 NL13TEST0123456789。

## 上线检查清单
- SEPA 支付方式已在目标国家启用。
- Mandate 文案已确认。
- 延迟成功和失败状态已处理。
- HMAC 验签已开启。

## 常见错误
- IBAN 格式错误。
- payment method not allowed：国家或商户未开通。
- AUTHORISED 与 SETTLED 状态混淆。

## 官方文档链接
- https://docs.adyen.com/payment-methods/sepa-direct-debit
- https://docs.adyen.com/payment-methods
