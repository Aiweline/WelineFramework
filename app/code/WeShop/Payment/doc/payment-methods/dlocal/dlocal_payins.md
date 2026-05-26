# dLocal Payins 配置指南

## 适用国家/地区
country_tags: BR, MX, AR, CO, CL, PE, UY, PY, EC, BO, NG, ZA, EG, MA, IN, ID, TH, VN

## 支持币种
USD, BRL, MXN, ARS, COP, CLP, PEN, UYU 等 dLocal 支持币种。

## 沙盒入口
https://dashboard.dlocal.com/

## 正式入口
https://dashboard.dlocal.com/

## 商户后台开通步骤
- 申请 dLocal 商户并完成国家开通。
- 获取 API key、secret 和 merchant_id。
- 为目标国家启用本地卡、银行转账、现金券或钱包。
- 在 WeShop 后台填写 API URL、API Key 和通知地址。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：dLocal Payins 创建接口。
- sandbox_api_key / live_api_key：API key 或认证 token。
- merchant_id：dLocal 商户 ID。
- return_url / notify_url：跳转和状态通知 URL。

## Webhook/回调 URL
在 dLocal Dashboard 配置 payments notification URL。

## 签名/证书要求
按 dLocal X-Date、Authorization、secret 签名规范校验。

## 测试卡/测试账号
使用 dLocal sandbox 国家测试数据和测试卡。

## 上线检查清单
- 国家、币种、payment_method_id 已开通。
- 正式密钥和正式 endpoint 已保存。
- Webhook 签名验证通过。
- 每个本地方式完成小额测试。

## 常见错误
- Invalid x-login：商户或 key 错误。
- Unsupported payment method：国家未开通该方式。
- Currency mismatch：币种与国家不匹配。

## 官方文档链接
- https://docs.dlocal.com/docs/payment-method
- https://docs.dlocal.com/reference/payments-create
