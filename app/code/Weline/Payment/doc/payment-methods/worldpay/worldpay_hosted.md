# Worldpay Hosted Payment Page 配置指南

## 适用国家/地区
country_tags: GB, US, CA, AU

## 支持币种
USD, GBP, EUR, CAD, AUD 等 Worldpay 商户账户支持币种。

## 沙盒入口
https://try.access.worldpay.com/

## 正式入口
https://developer.worldpay.com/

## 商户后台开通步骤
- 申请 Worldpay 商户并开通 Access Worldpay。
- 创建 service key。
- 开通 HPP 或 Sessions/Payments API。
- 在 WeShop 后台填写 API URL、API Key、商户 ID。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：Worldpay 支付创建接口。
- sandbox_api_key / live_api_key：service key。
- merchant_id：merchant entity 或商户标识。
- return_url / notify_url：跳转返回和事件通知地址。

## Webhook/回调 URL
在 Worldpay 后台配置 webhook endpoint，订阅支付授权、捕获、失败事件。

## 签名/证书要求
使用 Worldpay webhook secret 或签名头校验请求。

## 测试卡/测试账号
使用 Worldpay Access 官方测试卡和测试金额触发不同结果。

## 上线检查清单
- live service key 已启用。
- merchant entity 与币种匹配。
- webhook 签名验证通过。
- 3DS 策略已配置。

## 常见错误
- authentication failed：service key 错误。
- payment instrument rejected：卡数据或 3DS 配置错误。
- webhook 重放未做幂等。

## 官方文档链接
- https://developer.worldpay.com/
- https://developer.worldpay.com/products/access/payments
