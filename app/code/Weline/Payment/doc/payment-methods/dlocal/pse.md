# PSE 配置指南

## 适用国家/地区
country_tags: CO

## 支持币种
COP。

## 沙盒入口
https://dashboard.dlocal.com/

## 正式入口
https://dashboard.dlocal.com/

## 商户后台开通步骤
- 在 dLocal 开通哥伦比亚 PSE。
- 获取 API 凭据和 bank redirect 配置。
- 在 WeShop 后台填写 API URL、API Key、return_url、notify_url。
- 对 CO 国家客户优先展示 PSE。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：PSE 创建支付接口。
- sandbox_api_key / live_api_key：API key。
- merchant_id：dLocal 商户 ID。
- return_url / notify_url：银行返回和状态通知。

## Webhook/回调 URL
配置 dLocal payment notification URL。

## 签名/证书要求
使用 dLocal 签名规范校验回调。

## 测试卡/测试账号
使用 dLocal sandbox PSE 银行测试数据。

## 上线检查清单
- PSE 已对 CO 开通。
- COP 金额和税务字段正确。
- 银行跳转返回状态已处理。
- Webhook 幂等已实现。

## 常见错误
- bank unavailable：测试银行或真实银行不可用。
- redirect_url 为空：支付方式未开通。
- currency mismatch：非 COP。

## 官方文档链接
- https://docs.dlocal.com/docs/payment-method
- https://docs.dlocal.com/
