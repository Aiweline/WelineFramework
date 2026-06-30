# Flutterwave 配置指南

## 适用国家/地区
country_tags: NG, KE, GH, ZA, UG, TZ, RW, ZM, CI, SN, CM

## 支持币种
NGN, KES, GHS, ZAR, USD 等 Flutterwave 支持币种。

## 沙盒入口
https://developer.flutterwave.com/

## 正式入口
https://app.flutterwave.com/

## 商户后台开通步骤
- 创建 Flutterwave 商户并完成 KYC。
- 获取 public key、secret key、encryption key。
- 启用卡、银行、移动钱包等方式。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Flutterwave payment link/charge endpoint。
- sandbox_api_key / live_api_key：secret key。
- merchant_id：商户标识。
- return_url / notify_url：返回和 webhook URL。

## Webhook/回调 URL
在 Dashboard > Settings > Webhooks 配置 notify_url。

## 签名/证书要求
使用 secret hash 校验 Flutterwave Webhook。

## 测试卡/测试账号
使用 Flutterwave 测试卡 5531886652142950。

## 上线检查清单
- KYC 已通过。
- 目标国家方式已启用。
- Webhook 验签通过。
- 退款和失败状态已测试。

## 常见错误
- No payment option available：国家未开通。
- Invalid secret key：环境错误。
- charge.completed 重复：未做幂等。

## 官方文档链接
- https://developer.flutterwave.com/
- https://developer.flutterwave.com/docs/collecting-payments
