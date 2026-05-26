# 货到付款配置指南

## 适用国家/地区
country_tags: AE, SA, KW, QA, OM, BH, IN, ID, TH, VN, PH

## 支持币种
配送国家本币；以物流和财务可收款币种为准。

## 沙盒入口
https://example.com/weshop/cod/sandbox

## 正式入口
https://example.com/weshop/cod/live

## 商户后台开通步骤
- 确认物流商支持 COD。
- 在 WeShop 后台填写说明和手续费。
- 配送签收后由物流或财务回传收款状态。

## 后台字段说明
required_fields: instructions
- instructions：客户下单页展示说明。
- fee：货到付款手续费。

## Webhook/回调 URL
物流商如支持 COD 回调，将回调配置到 WeShop 支付回调 URL。

## 签名/证书要求
按物流商 Webhook 签名要求配置；无签名时只允许可信内网或后台人工确认。

## 测试卡/测试账号
使用测试物流面单和测试订单验证签收、拒收、已收款状态。

## 上线检查清单
- COD 覆盖国家和物流线路已确认。
- 手续费和退款规则已公示。
- 拒收和部分收款流程已定义。
- 后台确认权限已限制。

## 常见错误
- 对不支持 COD 的国家展示该方式。
- 物流回款周期未纳入财务对账。
- 拒收订单未及时取消库存占用。

## 官方文档链接
- https://www.upu.int/
