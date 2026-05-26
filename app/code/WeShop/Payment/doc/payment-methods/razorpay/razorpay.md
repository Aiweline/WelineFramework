# Razorpay 配置指南

## 适用国家/地区
country_tags: IN

## 支持币种
INR。

## 沙盒入口
https://dashboard.razorpay.com/app/dashboard?mode=test

## 正式入口
https://dashboard.razorpay.com/

## 商户后台开通步骤
- 注册 Razorpay 并完成 KYC。
- 获取 test/live key_id 和 key_secret。
- 启用 Cards、UPI、Netbanking、Wallets。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Orders 或 Payment Links endpoint。
- sandbox_api_key / live_api_key：key_id:key_secret。
- merchant_id：Razorpay account。
- return_url / notify_url：返回和 webhook URL。

## Webhook/回调 URL
在 Dashboard > Webhooks 配置 notify_url，订阅 payment.captured、payment.failed、refund.processed。

## 签名/证书要求
使用 webhook secret 校验 X-Razorpay-Signature。

## 测试卡/测试账号
使用 Razorpay test cards 和 test UPI ID success@razorpay。

## 上线检查清单
- KYC 已完成并切换 Live mode。
- INR 金额最小单位处理正确。
- Webhook 签名校验通过。
- 退款和部分付款状态已测试。

## 常见错误
- key_id/key_secret 环境不一致。
- order amount mismatch。
- signature verification failed。

## 官方文档链接
- https://razorpay.com/docs/payments/
- https://razorpay.com/docs/api/
