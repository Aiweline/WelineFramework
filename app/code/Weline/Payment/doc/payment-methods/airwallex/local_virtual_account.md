# 本地虚拟账户配置指南

## 适用国家/地区
country_tags: US, GB, HK, SG, AU, DE

## 支持币种
USD, EUR, GBP, HKD, SGD, AUD, CNY；以账户服务商支持币种为准。

## 沙盒入口
https://demo.airwallex.com/

## 正式入口
https://www.airwallex.com/app/

## 商户后台开通步骤
- 开通 Airwallex 全球账户或同类虚拟账户产品。
- 为需要的国家和币种申请本地收款账号。
- 配置到账通知 webhook。
- 在 WeShop 后台填写 API URL、API Key、merchant_id、notify_url。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：虚拟账户或收款记录接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：账户或商户标识。
- return_url / notify_url：付款说明返回和到账通知 URL。

## Webhook/回调 URL
在账户服务商后台配置入账通知 URL，接收 deposit/transfer received 事件。

## 签名/证书要求
使用 webhook_secret 校验到账通知签名；入账对账必须按 provider reference 幂等。

## 测试卡/测试账号
使用服务商 demo/sandbox 虚拟账户和测试入账事件。

## 上线检查清单
- 每个币种的本地账号已审核。
- 客户付款备注和 account reference 已展示。
- 入账 Webhook 与银行对账都已测试。
- 退款或误汇处理流程已定义。

## 常见错误
- account not active：虚拟账户未审核完成。
- payer reference 缺失导致无法自动对账。
- 入账币种与订单币种不一致。

## 官方文档链接
- https://www.airwallex.com/docs/global-treasury/global-accounts
- https://www.airwallex.com/docs/api
