# 微信支付配置指南

## 适用国家/地区
country_tags: CN, HK, MO, SG, MY

## 支持币种
CNY, HKD；以微信支付商户号开通范围为准。

## 沙盒入口
https://pay.weixin.qq.com/wiki/doc/apiv3/open/pay/chapter2_1.shtml

## 正式入口
https://pay.weixin.qq.com/

## 商户后台开通步骤
- 注册微信支付商户号。
- 绑定 AppID、公众号或小程序。
- 配置 API v3 密钥和商户证书。
- 在产品中心开通 H5、JSAPI、Native 或 App 支付。
- 在 WeShop 后台填写 app_id、mch_id、api_v3_key、notify_url。

## 后台字段说明
required_fields: app_id, mch_id, api_v3_key
- app_id：微信应用、公众号或小程序 AppID。
- mch_id：微信支付商户号。
- api_v3_key：API v3 密钥。
- merchant_cert_path：商户证书路径。
- notify_url：支付结果通知 URL。
- trade_type：MWEB、JSAPI、NATIVE、APP。

## Webhook/回调 URL
在微信支付商户平台配置支付结果通知 URL，或由下单接口传入 notify_url。

## 签名/证书要求
API v3 使用商户私钥签名和平台证书验签；通知需用 api_v3_key 解密 resource。

## 测试卡/测试账号
使用微信支付沙箱或小额真实订单验证。

## 上线检查清单
- 商户号已完成实名和产品开通。
- API v3 密钥已妥善保存。
- 证书路径只允许服务端读取。
- notify_url 使用 HTTPS。

## 常见错误
- 商户号与 AppID 未绑定。
- 签名错误：证书序列号或私钥错误。
- H5 支付提示商家参数格式有误：scene_info 或域名未配置。

## 官方文档链接
- https://pay.weixin.qq.com/wiki/doc/apiv3/index.shtml
- https://pay.weixin.qq.com/
