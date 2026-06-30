# M-Pesa 配置指南

## 适用国家/地区
country_tags: KE, TZ, UG, RW

## 支持币种
KES, TZS, UGX, RWF, USD；以 Flutterwave 或 M-Pesa 开通范围为准。

## 沙盒入口
https://developer.flutterwave.com/

## 正式入口
https://app.flutterwave.com/

## 商户后台开通步骤
- 在 Flutterwave 或 Safaricom 开通 M-Pesa 收款。
- 获取 public key、secret key、encryption key。
- 启用目标国家 mobile money。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Mobile Money 支付创建接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：商户 ID。
- return_url / notify_url：返回和 webhook URL。

## Webhook/回调 URL
在 Flutterwave Dashboard 配置 Webhook URL，接收 charge.completed。

## 签名/证书要求
使用 Flutterwave secret hash 校验 webhook。

## 测试卡/测试账号
使用 Flutterwave sandbox mobile money 测试号码。

## 上线检查清单
- KE/TZ 等国家 mobile money 已开通。
- 手机号格式校验正确。
- Webhook secret hash 已保存。
- 失败和超时状态已处理。

## 常见错误
- invalid phone number：手机号国家码错误。
- payment pending 长时间未完成。
- secret hash 校验失败。

## 官方文档链接
- https://developer.flutterwave.com/docs/mobile-money
- https://developer.flutterwave.com/
