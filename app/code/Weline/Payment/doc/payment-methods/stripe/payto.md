# PayTo 配置指南

## 适用国家/地区
country_tags: AU

## 支持币种
AUD。

## 沙盒入口
https://dashboard.stripe.com/test/payment-methods

## 正式入口
https://dashboard.stripe.com/settings/payment_methods

## 商户后台开通步骤
- 在 Stripe 或澳洲本地收单机构开通 PayTo。
- 配置客户授权流程。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。
- 使用 Webhook 更新授权和扣款状态。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：PayTo 支付创建接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：商户或 Stripe account。
- return_url / notify_url：授权返回和 webhook URL。

## Webhook/回调 URL
监听 mandate 和 payment intent 状态变化。

## 签名/证书要求
使用 webhook_secret 校验签名。

## 测试卡/测试账号
使用官方 PayTo sandbox 账号或 Stripe 测试数据。

## 上线检查清单
- PayTo 已对 AU 开通。
- 授权文本已展示。
- AUD 金额处理正确。
- 撤销授权和失败回调已处理。

## 常见错误
- mandate rejected：客户拒绝授权。
- unsupported account：银行账户不支持 PayTo。
- Webhook 乱序导致状态错误。

## 官方文档链接
- https://docs.stripe.com/payments/payment-methods/payment-method-support
- https://www.auspaynet.com.au/resources/payto/
