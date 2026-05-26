# Paystack 配置指南

## 适用国家/地区
country_tags: NG, GH, ZA, KE

## 支持币种
NGN, GHS, ZAR, KES, USD；以 Paystack 商户国家为准。

## 沙盒入口
https://dashboard.paystack.com/#/settings/developer

## 正式入口
https://dashboard.paystack.com/

## 商户后台开通步骤
- 创建 Paystack 商户并完成业务验证。
- 获取 test/live secret key。
- 启用卡、银行转账、移动钱包。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Paystack initialize transaction endpoint。
- sandbox_api_key / live_api_key：secret key。
- merchant_id：商户或子账户标识。
- return_url / notify_url：返回和 webhook URL。

## Webhook/回调 URL
在 Settings > API Keys & Webhooks 配置 Webhook URL。

## 签名/证书要求
使用 X-Paystack-Signature 和 secret key 校验回调。

## 测试卡/测试账号
使用 Paystack 测试卡 4084084084084081。

## 上线检查清单
- 商户已激活。
- live secret key 已保存。
- Webhook 签名校验通过。
- 国家和币种匹配。

## 常见错误
- Invalid key：key 环境错误。
- currency not supported：商户国家不支持该币种。
- reference 重复：幂等键未设计。

## 官方文档链接
- https://paystack.com/docs/
- https://paystack.com/docs/payments/
