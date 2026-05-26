# iDEAL 配置指南

## 适用国家/地区
country_tags: NL

## 支持币种
EUR。

## 沙盒入口
https://ca-test.adyen.com/ca/ca/login.shtml

## 正式入口
https://ca-live.adyen.com/ca/ca/login.shtml

## 商户后台开通步骤
- 在 Adyen Customer Area 启用 iDEAL。
- 配置 Checkout API credential 和 merchant account。
- 在 WeShop 后台填写 API URL、API Key、return_url。
- 结账页根据国家 NL 优先展示 iDEAL。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Adyen payments endpoint。
- sandbox_api_key / live_api_key：Checkout API key。
- merchant_id：merchant account。
- return_url / notify_url：银行跳转返回和通知 URL。

## Webhook/回调 URL
配置 Adyen Standard notification，接收 AUTHORISATION、CANCELLATION、REFUND。

## 签名/证书要求
使用 Adyen HMAC key 验签。

## 测试卡/测试账号
Adyen 测试环境选择 iDEAL test issuer。

## 上线检查清单
- iDEAL 已正式开通。
- return_url 使用 HTTPS。
- Webhook 幂等处理 AUTHORISED。
- 荷兰国家筛选下排序优先。

## 常见错误
- issuer unavailable：银行列表或测试 issuer 错误。
- redirect_url 缺失：action 解析错误或支付方式未启用。
- notification HMAC 失败。

## 官方文档链接
- https://docs.adyen.com/payment-methods/ideal
- https://docs.adyen.com/payment-methods
