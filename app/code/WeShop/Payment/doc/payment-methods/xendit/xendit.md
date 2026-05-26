# Xendit 配置指南

## 适用国家/地区
country_tags: ID, PH, MY, TH, VN

## 支持币种
IDR, PHP, MYR, THB, VND, USD；以 Xendit 账户开通范围为准。

## 沙盒入口
https://dashboard.xendit.co/settings/developers

## 正式入口
https://dashboard.xendit.co/

## 商户后台开通步骤
- 创建 Xendit 商户并完成验证。
- 获取 API key。
- 启用 Virtual Account、E-wallet、QR、Cards。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Invoice 或 payment request endpoint。
- sandbox_api_key / live_api_key：secret API key。
- merchant_id：Xendit account ID。
- return_url / notify_url：返回和 callback URL。

## Webhook/回调 URL
在 Settings > Callbacks 配置 notify_url。

## 签名/证书要求
使用 callback verification token 校验请求。

## 测试卡/测试账号
使用 Xendit test mode invoice、VA 和 e-wallet。

## 上线检查清单
- 正式账户已激活。
- Callback token 已保存。
- 国家和支付通道已开通。
- VA/QR 过期规则已配置。

## 常见错误
- API key not authorized：环境或权限错误。
- channel not available：国家未开通。
- callback token mismatch：回调验签失败。

## 官方文档链接
- https://docs.xendit.co/
- https://docs.xendit.co/docs/payment-request
