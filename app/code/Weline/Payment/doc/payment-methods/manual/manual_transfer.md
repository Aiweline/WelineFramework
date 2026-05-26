# 银行转账配置指南

## 适用国家/地区
country_tags: US, GB, DE, SG, AE

## 支持币种
不限；以商户银行账户支持币种为准。

## 沙盒入口
https://example.com/weshop/manual-transfer/sandbox

## 正式入口
https://example.com/weshop/manual-transfer/live

## 商户后台开通步骤
- 准备收款账户名称、开户银行、账号、SWIFT/BIC 或 IBAN。
- 在 WeShop 后台填写付款说明。
- 下单后由财务人工核对到账并改订单状态。

## 后台字段说明
required_fields: instructions
- instructions：展示给客户的付款说明。
- account_name：银行账户名称。
- bank_name：开户银行。
- account_number：银行账号。
- swift_code / iban：跨境收款信息。
- reference_note：付款备注要求。

## Webhook/回调 URL
人工转账没有外部 Webhook；如果银行有到账通知，可配置到 WeShop 支付回调 URL。

## 签名/证书要求
人工转账不需要 API 签名；银行通知接入时按银行证书规则处理。

## 测试卡/测试账号
使用测试订单号和内部转账凭证截图验证财务流程。

## 上线检查清单
- 账户信息已由财务复核。
- 付款备注要求清晰。
- 后台订单人工确认权限已限制。
- 多币种账户已分开说明。

## 常见错误
- 客户未填写订单号导致对账困难。
- IBAN/SWIFT 错误导致退汇。
- 财务确认权限过宽。

## 官方文档链接
- https://www.swift.com/standards
- https://www.iso20022.org/
