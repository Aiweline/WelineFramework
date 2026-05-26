# 连连支付配置指南

## 适用国家/地区
country_tags: CN, HK, US, GB, SG, AU, JP, KR, VN, TH, MY, ID, PH

## 支持币种
CNY, USD, HKD, EUR, GBP；以连连商户产品开通范围为准。

## 沙盒入口
https://global.lianlianpay.com/

## 正式入口
https://global.lianlianpay.com/

## 商户后台开通步骤
- 开通连连跨境或本地收款产品。
- 获取商户号、API 凭据和回调配置。
- 开通目标国家/币种能力。
- 在 WeShop 后台填写 API URL、API Key、merchant_id、notify_url。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：连连支付接口地址。
- sandbox_api_key / live_api_key：API 密钥或签名私钥标识。
- merchant_id：连连商户号。
- return_url / notify_url：页面返回和异步通知 URL。

## Webhook/回调 URL
在连连商户后台配置异步通知地址 notify_url。

## 签名/证书要求
按连连开放平台签名规则使用商户私钥签名、平台公钥验签。

## 测试卡/测试账号
使用连连提供的测试商户和测试支付账号。

## 上线检查清单
- 正式商户已开通对应国家。
- 签名密钥和平台公钥已区分环境。
- notify_url 已通过连连回调测试。
- 跨境结算和退款规则已确认。

## 常见错误
- 商户权限不足：国家或产品未开通。
- 签名验签失败：密钥环境错误。
- 回调状态重复：未做幂等。

## 官方文档链接
- https://global.lianlianpay.com/
- https://support.us.lianlianglobal.com/hc/en-us/articles/16824919989517-Which-countries-can-I-send-payments-to
