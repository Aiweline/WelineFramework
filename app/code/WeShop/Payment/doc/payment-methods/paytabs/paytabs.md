# PayTabs 配置指南

## 适用国家/地区
country_tags: AE, SA, BH, KW, QA, OM, EG, JO

## 支持币种
AED, SAR, BHD, KWD, QAR, OMR, EGP, JOD, USD。

## 沙盒入口
https://secure-global.paytabs.com/

## 正式入口
https://secure.paytabs.com/

## 商户后台开通步骤
- 创建 PayTabs 商户并完成审核。
- 获取 profile_id 和 server key。
- 配置 hosted payment page。
- 在 WeShop 后台填写 API URL、API Key、merchant_id。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：PayTabs payment request endpoint。
- sandbox_api_key / live_api_key：server key。
- merchant_id：profile_id。
- return_url / notify_url：return/callback URL。

## Webhook/回调 URL
在 PayTabs Dashboard 配置 callback URL 或在请求中传入 notify_url。

## 签名/证书要求
使用 PayTabs server key 验证回调签名。

## 测试卡/测试账号
使用 PayTabs 测试卡和沙盒 profile。

## 上线检查清单
- 正式 profile 已激活。
- 目标国家币种已开通。
- callback 签名已校验。
- 3DS 成功和失败都已测试。

## 常见错误
- profile not found：merchant_id/profile_id 错误。
- currency not supported：币种未开通。
- callback 被重复处理。

## 官方文档链接
- https://support.paytabs.com/en/support/solutions/folders/6000231734
- https://www.paytabs.com/
