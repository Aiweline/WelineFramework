# PingPong 配置指南

## 适用国家/地区
country_tags: CN, US, GB, DE, FR, IT, ES, JP, CA, AU, SG, HK

## 支持币种
CNY, USD, EUR, GBP, JPY, CAD, AUD；以 PingPong 商户开通范围为准。

## 沙盒入口
https://www.international.pingpongx.com/

## 正式入口
https://www.international.pingpongx.com/

## 商户后台开通步骤
- 申请 PingPong 跨境收款或支付产品。
- 获取商户号、API 凭据、回调密钥。
- 开通目标币种和地区。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：PingPong 支付接口。
- sandbox_api_key / live_api_key：API key 或签名密钥。
- merchant_id：PingPong 商户标识。
- return_url / notify_url：返回和异步通知地址。

## Webhook/回调 URL
在 PingPong 后台配置支付状态通知 URL。

## 签名/证书要求
按 PingPong API 签名规则校验请求和回调。

## 测试卡/测试账号
使用 PingPong 提供的测试商户和测试交易数据。

## 上线检查清单
- 正式商户审核通过。
- 目标币种已开通。
- 回调签名校验通过。
- 结算账户和费用规则已确认。

## 常见错误
- invalid merchant：商户号错误。
- signature error：签名字段或密钥错误。
- unsupported currency：币种未开通。

## 官方文档链接
- https://www.international.pingpongx.com/
