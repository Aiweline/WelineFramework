# HyperPay 配置指南

## 适用国家/地区
country_tags: SA, AE, BH, KW, QA, OM, JO, EG

## 支持币种
SAR, AED, BHD, KWD, QAR, OMR, JOD, EGP, USD。

## 沙盒入口
https://test.oppwa.com/

## 正式入口
https://oppwa.com/

## 商户后台开通步骤
- 开通 HyperPay 商户。
- 获取 entityId、access token 和支付品牌。
- 配置 checkout 和 webhook。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Checkout/Payment endpoint。
- sandbox_api_key / live_api_key：Bearer access token。
- merchant_id：entityId 或 merchant ID。
- return_url / notify_url：返回和通知 URL。

## Webhook/回调 URL
在 HyperPay 后台配置 notification/webhook URL。

## 签名/证书要求
使用 HyperPay integrity 或 webhook signature 校验。

## 测试卡/测试账号
使用 HyperPay test cards 和 test entityId。

## 上线检查清单
- Live entityId 已开通品牌。
- access token 已区分环境。
- 3DS 和 Mada 等本地品牌已测试。
- Webhook 验签通过。

## 常见错误
- invalid entityId：商户实体错误。
- brand not configured：支付品牌未开通。
- result code 映射错误。

## 官方文档链接
- https://hyperpay.docs.oppwa.com/
- https://www.hyperpay.com/
