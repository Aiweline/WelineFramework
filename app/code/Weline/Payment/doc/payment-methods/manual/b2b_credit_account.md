# 信用额度支付配置指南

## 适用国家/地区
country_tags: US, GB, DE, CN, SG, AE

## 支持币种
不限；授信币种由监听 WeShop_Payment::credit_payment_requested 的业务模块决定。

## 沙盒入口
https://example.com/weshop/credit-line/sandbox

## 正式入口
https://example.com/weshop/credit-line/live

## 商户后台开通步骤
- 启用信用额度支付方式。
- 在业务模块中监听 WeShop_Payment::credit_payment_requested。
- 监听器负责授信校验、额度占用、账期和应收单生成。

## 后台字段说明
required_fields: instructions
- instructions：展示给 B2B 客户的使用说明。
- 本支付方式不保存额度、账期或风控配置。

## Webhook/回调 URL
无外部 Webhook；事件口为 WeShop_Payment::credit_payment_requested。

## 签名/证书要求
无外部签名；监听器应在业务域内校验客户身份和授信权限。

## 测试卡/测试账号
使用测试 B2B 客户和监听器模拟 accepted/status/provider_reference。

## 上线检查清单
- 授信监听器已部署。
- 无监听器时不会扣减额度。
- 订单状态和应收账款由监听器闭环。
- 后台仅对 B2B 客户展示。

## 常见错误
- 未部署监听器导致订单保持 pending。
- 在支付模块内直接扣减额度，破坏业务边界。
- 监听器未做幂等处理。

## 官方文档链接
- https://example.com/weshop/events/credit-payment-requested
