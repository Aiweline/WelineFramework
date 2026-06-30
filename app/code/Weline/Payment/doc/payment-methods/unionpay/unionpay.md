# 银联配置指南

## 适用国家/地区
country_tags: CN, HK, MO, SG, MY, TH, KR, JP

## 支持币种
CNY, HKD, USD；以银联商务或收单机构开通币种为准。

## 沙盒入口
https://open.unionpay.com/

## 正式入口
https://open.unionpay.com/

## 商户后台开通步骤
- 通过银联商务或合作收单机构开通在线支付。
- 申请商户号、证书和产品权限。
- 获取前台/后台交易接口地址。
- 在 WeShop 后台填写 API URL、API Key、merchant_id、notify_url。

## 后台字段说明
required_fields: api_url, api_key, merchant_id, return_url, notify_url
- sandbox_api_url / live_api_url：银联交易接口。
- sandbox_api_key / live_api_key：证书密码或签名密钥。
- merchant_id：银联商户号。
- return_url / notify_url：前台返回和后台通知地址。

## Webhook/回调 URL
在交易请求中传入 backUrl/notify_url，并确保公网可访问。

## 签名/证书要求
使用银联签名证书和验签证书；证书不得提交到代码库。

## 测试卡/测试账号
使用银联开放平台测试卡和测试证书。

## 上线检查清单
- 正式证书已安装到安全路径。
- 商户号与产品权限匹配。
- 前台和后台回调都已验签。
- 退款、撤销流程已测试。

## 常见错误
- 签名失败：证书路径或密码错误。
- 商户无此交易权限：产品未开通。
- 通知重复：未按订单号做幂等。

## 官方文档链接
- https://open.unionpay.com/
