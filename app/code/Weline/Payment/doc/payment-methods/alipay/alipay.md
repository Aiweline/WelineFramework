# 支付宝配置指南

## 适用国家/地区
country_tags: CN, HK, MO, SG, MY

## 支持币种
CNY, HKD, USD；以支付宝商户产品开通范围为准。

## 沙盒入口
https://open.alipay.com/develop/sandbox/app

## 正式入口
https://open.alipay.com/

## 商户后台开通步骤
- 创建支付宝开放平台应用。
- 开通电脑网站支付、手机网站支付或跨境支付产品。
- 生成应用 RSA2 私钥并上传公钥。
- 获取 App ID、商户 ID、支付宝公钥。
- 在 WeShop 后台填写密钥、notify_url、return_url。

## 后台字段说明
required_fields: app_id, merchant_id, public_key, private_key
- app_id：支付宝应用 ID。
- merchant_id：商户 UID/PID。
- public_key：支付宝公钥。
- private_key：应用私钥。
- notify_url / return_url：异步通知和同步返回 URL。
- product_code：支付产品码。

## Webhook/回调 URL
在开放平台应用或产品配置中填写 notify_url。

## 签名/证书要求
使用 RSA2 签名；生产环境建议使用应用私钥和支付宝公钥严格验签。

## 测试卡/测试账号
使用支付宝沙盒买家账号和沙盒钱包。

## 上线检查清单
- 应用已上线且产品已签约。
- 私钥未上传到公开目录。
- notify_url 可公网访问。
- 支付宝公钥与环境匹配。

## 常见错误
- ISV权限不足：产品未签约或应用未上线。
- 验签失败：公钥或 charset/sign_type 错误。
- notify_url 无法访问：证书或路由配置错误。

## 官方文档链接
- https://global.alipay.com/docs/ac
- https://open.alipay.com/
