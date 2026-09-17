# 规格：购物车门槛进度（REQ-MARKETING-0014）

## EARS

- When 调用方提供 cart totals 与门槛规则列表，`MarketingCartProgressService` shall 返回最近未达（或最高已达）门槛的 `{threshold,current,remaining,currency,label,progress,met}`。
- When 前台需要进度条，系统 shall 提供 Taglib `marketing-cart-progress` 与 widget `cart-progress`。
- When 需要新客礼入口，系统 shall 提供 widget `welcome-gift`。

## 非目标

- 自动扫描全部规则引擎写死门槛；由调用方注入已解析门槛。
