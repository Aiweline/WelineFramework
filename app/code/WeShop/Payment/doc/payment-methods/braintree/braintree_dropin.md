# Braintree Drop-in 配置指南

## 适用国家/地区
country_tags: US, GB, AU, CA

## 支持币种
USD, GBP, AUD, CAD, EUR；以 Braintree merchant account 支持币种为准。

## 沙盒入口
https://sandbox.braintreegateway.com/

## 正式入口
https://www.braintreegateway.com/

## 商户后台开通步骤
- 创建 Braintree 商户账号并完成审核。
- 在 Control Panel 创建 API keys。
- 获取 merchant_id、public key、private key 或托管支付 API endpoint。
- 在 WeShop 后台填写沙盒/正式 API URL 和 API Key。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Braintree 支付创建接口。
- sandbox_api_key / live_api_key：API 访问密钥。
- merchant_id：Braintree Merchant ID。
- return_url / notify_url：跳转返回和通知地址。

## Webhook/回调 URL
在 Braintree Control Panel > Webhooks 配置 notify_url。

## 签名/证书要求
使用 Braintree Webhook signature 校验通知；API 使用密钥认证。

## 测试卡/测试账号
沙盒卡 4111111111111111，或使用 Braintree 官方测试 nonce。

## 上线检查清单
- 正式 merchant account 已审核。
- live_api_url 与 live_api_key 对应。
- Webhook 已开启并通过签名校验。
- PayPal、Apple Pay 等钱包按需启用。

## 常见错误
- Merchant account 币种未开通。
- Webhook notification signature 校验失败。
- 客户端 nonce 与服务端环境不一致。

## 官方文档链接
- https://developer.paypal.com/braintree/docs/start/overview
- https://developer.paypal.com/braintree/docs/reference/general/testing
