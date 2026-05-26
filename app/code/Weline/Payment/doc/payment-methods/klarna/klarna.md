# Klarna 配置指南

## 适用国家/地区
country_tags: SE, NO, FI, DK, DE, AT, NL, GB, US

## 支持币种
SEK, NOK, EUR, DKK, GBP, USD；以 Klarna market 配置为准。

## 沙盒入口
https://portal.playground.klarna.com/

## 正式入口
https://portal.klarna.com/

## 商户后台开通步骤
- 开通 Klarna 商户并选择 markets。
- 获取 API username/password 或 credentials。
- 配置 On-site messaging 和支付产品。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Klarna payments endpoint。
- sandbox_api_key / live_api_key：认证凭据。
- merchant_id：Klarna merchant ID。
- return_url / notify_url：返回和状态通知 URL。

## Webhook/回调 URL
在 Klarna Merchant Portal 配置 push/notification URL。

## 签名/证书要求
API 使用 Basic/Auth credentials；Webhook 按 Klarna 签名或认证规则校验。

## 测试卡/测试账号
使用 Klarna Playground 测试身份和测试卡。

## 上线检查清单
- 对应 market 已开通。
- 商品、税费、地址字段完整。
- 退款和部分捕获流程已测试。
- 风控拒绝状态已处理。

## 常见错误
- market not supported：国家和币种不匹配。
- order lines invalid：税费或金额不一致。
- authorization expired：未及时 capture。

## 官方文档链接
- https://docs.klarna.com/
- https://docs.klarna.com/payments/
