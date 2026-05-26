# UPI 配置指南

## 适用国家/地区
country_tags: IN

## 支持币种
INR。

## 沙盒入口
https://dashboard.razorpay.com/app/dashboard?mode=test

## 正式入口
https://dashboard.razorpay.com/

## 商户后台开通步骤
- 开通 Razorpay 商户并完成 KYC。
- 在 Dashboard 获取 key_id 和 key_secret。
- 启用 UPI。
- 在 WeShop 后台填写 API URL、API Key、merchant_id、notify_url。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Razorpay orders/payment link 接口。
- sandbox_api_key / live_api_key：key_id:key_secret 或 token。
- merchant_id：Razorpay account 标识。
- return_url / notify_url：返回和 Webhook URL。

## Webhook/回调 URL
在 Razorpay Dashboard > Webhooks 配置 notify_url，订阅 payment.captured、payment.failed。

## 签名/证书要求
使用 Razorpay webhook secret 校验 X-Razorpay-Signature。

## 测试卡/测试账号
使用 Razorpay test mode UPI ID success@razorpay。

## 上线检查清单
- KYC 已完成。
- UPI 已在正式环境启用。
- Webhook secret 已保存。
- INR 金额最小单位处理正确。

## 常见错误
- BAD_REQUEST_ERROR：金额或币种错误。
- payment failed：UPI handle 不可用。
- signature verification failed：secret 错误。

## 官方文档链接
- https://razorpay.com/docs/payments/payment-methods/upi/
- https://razorpay.com/docs/api/
