# Rapyd Collect 配置指南

## 适用国家/地区
country_tags: SG, BR, MX, ID, ZA

## 支持币种
USD, SGD, BRL, MXN, IDR, ZAR 等 Rapyd 支持币种。

## 沙盒入口
https://dashboard.rapyd.net/

## 正式入口
https://dashboard.rapyd.net/

## 商户后台开通步骤
- 创建 Rapyd Client Portal 账号。
- 在 Developers 获取 access key 和 secret key。
- 选择 Collect/payment methods 并确认国家覆盖。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Rapyd Collect 创建支付接口。
- sandbox_api_key / live_api_key：access key 或组合后的认证 key。
- merchant_id：Rapyd merchant/customer 标识。
- return_url / notify_url：返回和 webhook URL。

## Webhook/回调 URL
在 Client Portal > Developers > Webhooks 配置 notify_url。

## 签名/证书要求
Rapyd API 请求和 Webhook 使用 salt、timestamp、signature 规则。

## 测试卡/测试账号
使用 Rapyd sandbox 测试钱包、卡和本地支付方式。

## 上线检查清单
- access key/secret key 属于正式环境。
- 国家和 payment_method_type 已审核开通。
- Webhook 签名校验通过。
- 幂等 reference 规则已确认。

## 常见错误
- signature mismatch：签名串或 timestamp 错误。
- payment_method_type unsupported：未在国家开通。
- currency not supported：币种与国家不匹配。

## 官方文档链接
- https://docs.rapyd.net/
- https://docs.rapyd.net/en/collect-payments.html
