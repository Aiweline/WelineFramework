# Pix 配置指南

## 适用国家/地区
country_tags: BR

## 支持币种
BRL。

## 沙盒入口
https://sandbox.ebanxpay.com/

## 正式入口
https://api.ebanxpay.com/

## 商户后台开通步骤
- 在 EBANX 或本地收单机构开通 Pix。
- 获取 API key、merchant_id 和 Pix 支付 endpoint。
- 在 WeShop 后台填写 API URL、API Key、notify_url。
- 结账页对巴西客户优先展示 Pix。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Pix 支付创建接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：商户标识。
- return_url / notify_url：二维码页返回和支付通知 URL。

## Webhook/回调 URL
配置 Pix payment notification URL，接收 paid、expired、cancelled。

## 签名/证书要求
按 EBANX 或本地机构 Webhook 签名规则校验。

## 测试卡/测试账号
使用 EBANX sandbox Pix 测试 QR code。

## 上线检查清单
- Pix 已正式开通。
- QR code 过期时间展示正确。
- Webhook paid 后才改为已支付。
- 退款流程已测试。

## 常见错误
- QR code expired：客户超时未付。
- BRL 以外币种被拒绝。
- Webhook 重复导致状态倒退。

## 官方文档链接
- https://docs.ebanx.com/docs/pay-in/payment-methods/pix
- https://docs.ebanx.com/docs/pay-in/processing/payment-methods/payment-methods-overview
