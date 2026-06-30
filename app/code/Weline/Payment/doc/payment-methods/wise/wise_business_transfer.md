# Wise Business 转账配置指南

## 适用国家/地区
country_tags: GB, US, AU, SG

## 支持币种
USD, EUR, GBP, AUD, SGD 等 Wise Business 支持币种。

## 沙盒入口
https://api.sandbox.transferwise.tech/

## 正式入口
https://api.transferwise.com/

## 商户后台开通步骤
- 开通 Wise Business 账户。
- 在 API tokens 创建 token。
- 创建或确认多币种账户收款信息。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Wise API endpoint。
- sandbox_api_key / live_api_key：API token。
- merchant_id：profile ID 或业务账户标识。
- return_url / notify_url：付款说明返回和事件通知地址。

## Webhook/回调 URL
在 Wise API webhook subscriptions 中配置 notify_url。

## 签名/证书要求
使用 Wise Webhook 公钥或签名头校验事件。

## 测试卡/测试账号
使用 Wise sandbox profile 和测试 quote/transfer。

## 上线检查清单
- Business profile 已通过验证。
- 收款账户币种和路由信息正确。
- Webhook 验签已开启。
- 客户付款备注规则已展示。

## 常见错误
- profile not found：merchant_id/profile ID 错误。
- quote expired：汇率报价过期。
- webhook 未到达：subscription 未启用。

## 官方文档链接
- https://docs.wise.com/
- https://docs.wise.com/api-docs/guides/webhooks
