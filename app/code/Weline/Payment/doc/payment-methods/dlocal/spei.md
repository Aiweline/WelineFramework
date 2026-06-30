# SPEI 配置指南

## 适用国家/地区
country_tags: MX

## 支持币种
MXN。

## 沙盒入口
https://dashboard.dlocal.com/

## 正式入口
https://dashboard.dlocal.com/

## 商户后台开通步骤
- 在 dLocal 开通墨西哥 SPEI。
- 获取 Payins API 凭据。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。
- 结账页向墨西哥客户展示银行转账说明。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：SPEI 支付创建接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：dLocal 商户 ID。
- return_url / notify_url：返回和付款状态 URL。

## Webhook/回调 URL
配置 dLocal notification URL 接收 PENDING、PAID、REJECTED。

## 签名/证书要求
按 dLocal Authorization 签名规则校验回调。

## 测试卡/测试账号
使用 dLocal sandbox SPEI 测试付款。

## 上线检查清单
- MXN 币种已开通。
- 转账参考号清晰展示。
- 超时未付自动关闭。
- Webhook 幂等处理。

## 常见错误
- transfer reference missing：客户无法完成付款。
- unsupported country：未开通 MX。
- 签名日期头错误导致验签失败。

## 官方文档链接
- https://docs.dlocal.com/docs/payment-method
- https://docs.dlocal.com/
