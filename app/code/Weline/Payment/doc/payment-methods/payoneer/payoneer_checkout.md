# Payoneer Checkout 配置指南

## 适用国家/地区
country_tags: US, GB, CN, DE, HK

## 支持币种
USD, EUR, GBP, CAD, AUD, JPY 等 Payoneer 支持币种。

## 沙盒入口
https://developer.payoneer.com/

## 正式入口
https://myaccount.payoneer.com/

## 商户后台开通步骤
- 申请 Payoneer Checkout 或收款服务。
- 获取 API 凭据、商户 ID 和回调配置。
- 开通卡支付或全球账户收款能力。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Payoneer 支付创建接口。
- sandbox_api_key / live_api_key：API credential。
- merchant_id：Payoneer 商户标识。
- return_url / notify_url：客户返回和通知 URL。

## Webhook/回调 URL
在 Payoneer 商户后台配置 notify_url，订阅支付状态变化。

## 签名/证书要求
按 Payoneer API credential 和 Webhook 签名规范校验。

## 测试卡/测试账号
使用 Payoneer 提供的测试商户和测试交易凭据。

## 上线检查清单
- 正式收款服务已审批。
- 结算币种已确认。
- 回调签名验证通过。
- 跨境风控和拒付流程已配置。

## 常见错误
- merchant not active：商户未完成审批。
- callback rejected：回调 URL 或签名配置错误。
- unsupported country：买家国家未开通。

## 官方文档链接
- https://developer.payoneer.com/
- https://www.payoneer.com/
