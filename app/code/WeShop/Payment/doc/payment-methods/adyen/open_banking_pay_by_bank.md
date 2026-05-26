# Open Banking / Pay by Bank 配置指南

## 适用国家/地区
country_tags: GB, IE, DE, NL, FR, ES, SE, FI, NO, DK

## 支持币种
GBP, EUR, SEK, NOK, DKK；以开放银行支付服务商覆盖范围为准。

## 沙盒入口
https://ca-test.adyen.com/ca/ca/login.shtml

## 正式入口
https://ca-live.adyen.com/ca/ca/login.shtml

## 商户后台开通步骤
- 在 Adyen 或开放银行服务商后台启用 Pay by Bank/Open Banking。
- 确认目标国家银行覆盖和退款能力。
- 创建 API credential 并配置 return_url。
- 在 WeShop 后台填写 API URL、API Key、merchant_id、notify_url。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：支付创建接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：merchant account 或商户标识。
- return_url / notify_url：银行授权返回和支付通知 URL。

## Webhook/回调 URL
配置 Standard notification 或 provider webhook，接收授权成功、支付失败、退款事件。

## 签名/证书要求
使用 HMAC 或服务商 webhook secret 校验回调；不要仅凭浏览器返回判断成功。

## 测试卡/测试账号
使用服务商 sandbox 测试银行和测试用户。

## 上线检查清单
- 目标国家银行覆盖已确认。
- 授权返回和 Webhook 都已测试。
- 支付 pending 状态不会触发发货。
- 退款和付款失败状态已处理。

## 常见错误
- bank unavailable：测试或真实银行暂不可用。
- consent expired：客户授权超时。
- notification missing：Webhook 未配置或验签失败。

## 官方文档链接
- https://docs.adyen.com/payment-methods/open-banking/
- https://docs.adyen.com/payment-methods
