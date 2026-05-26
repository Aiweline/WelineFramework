# Midtrans 配置指南

## 适用国家/地区
country_tags: ID

## 支持币种
IDR。

## 沙盒入口
https://dashboard.sandbox.midtrans.com/

## 正式入口
https://dashboard.midtrans.com/

## 商户后台开通步骤
- 创建 Midtrans 商户。
- 获取 Server Key 和 Client Key。
- 启用 Snap 或 Core API。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Snap transaction endpoint。
- sandbox_api_key / live_api_key：Server Key。
- merchant_id：Midtrans merchant ID。
- return_url / notify_url：finish/notification URL。

## Webhook/回调 URL
在 Dashboard > Settings > Payment Notification URL 配置 notify_url。

## 签名/证书要求
使用 order_id、status_code、gross_amount、server_key 计算 signature_key。

## 测试卡/测试账号
使用 Midtrans sandbox cards、bank transfer 和 GoPay。

## 上线检查清单
- Production Server Key 已保存。
- Notification URL 可公网访问。
- IDR 金额为整数。
- fraud_status 已处理。

## 常见错误
- 401 unauthorized：Server Key 错误。
- signature_key invalid：验签字段错误。
- transaction_status pending 未处理。

## 官方文档链接
- https://docs.midtrans.com/
- https://docs.midtrans.com/reference/snap-api
