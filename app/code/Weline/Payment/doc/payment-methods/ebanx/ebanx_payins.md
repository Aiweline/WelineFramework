# EBANX Payins 配置指南

## 适用国家/地区
country_tags: BR, MX, CO, CL, PE, AR, EC, BO, UY

## 支持币种
BRL, MXN, COP, CLP, PEN, ARS, USD 等 EBANX 支持币种。

## 沙盒入口
https://sandbox.ebanxpay.com/

## 正式入口
https://api.ebanxpay.com/

## 商户后台开通步骤
- 开通 EBANX 商户账号。
- 获取 integration key。
- 在 Dashboard 启用国家和支付方式。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：EBANX Pay API endpoint。
- sandbox_api_key / live_api_key：integration key。
- merchant_id：商户标识。
- return_url / notify_url：客户返回和 notification URL。

## Webhook/回调 URL
在 EBANX Dashboard 配置 notification_url，接收 payment status update。

## 签名/证书要求
使用 integration key 和 EBANX notification 规则校验请求来源。

## 测试卡/测试账号
使用 EBANX sandbox 测试卡、Pix 和本地现金券。

## 上线检查清单
- 国家和 payment type 已正式开通。
- notification_url 使用 HTTPS。
- Pix、现金券过期规则已展示。
- 退款和拒付流程已测试。

## 常见错误
- invalid integration key：环境和 key 错误。
- payment type unavailable：本地方式未开通。
- notification 未处理幂等导致重复改状态。

## 官方文档链接
- https://docs.ebanx.com/docs/pay-in/processing/payment-methods/payment-methods-overview
- https://docs.ebanx.com/docs/pay-in/processing
