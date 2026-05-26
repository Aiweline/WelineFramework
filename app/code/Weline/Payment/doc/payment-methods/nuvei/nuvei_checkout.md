# Nuvei Checkout 配置指南

## 适用国家/地区
country_tags: CA, US, GB, IL

## 支持币种
USD, CAD, EUR, GBP 等 Nuvei 账户支持币种。

## 沙盒入口
https://sandbox.nuvei.com/

## 正式入口
https://cpanel.nuvei.com/

## 商户后台开通步骤
- 申请 Nuvei 商户并获取 merchant_id、site_id、secret。
- 启用 Checkout 或 REST API。
- 在 Nuvei 后台配置 DMN/Webhook。
- 在 WeShop 后台填写 API URL、API Key 和 notify_url。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Nuvei API endpoint。
- sandbox_api_key / live_api_key：接口密钥或 secret。
- merchant_id：Nuvei merchant ID。
- return_url / notify_url：客户返回和 DMN URL。

## Webhook/回调 URL
在 Nuvei 后台配置 DMN URL，对应 WeShop notify_url。

## 签名/证书要求
使用 Nuvei secret 计算 checksum/advance response checksum。

## 测试卡/测试账号
使用 Nuvei 官方测试卡和沙盒账号。

## 上线检查清单
- site_id/merchant_id 与正式账户一致。
- DMN URL 使用 HTTPS。
- checksum 验证已开启。
- 本地支付方式完成逐国测试。

## 常见错误
- checksum mismatch：字段顺序或 secret 错误。
- Transaction declined：风控或支付方式未开通。
- DMN 未收到：后台 URL 未配置或证书错误。

## 官方文档链接
- https://docs.nuvei.com/
