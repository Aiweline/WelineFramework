# REQ-MARKETING-0014 — 店面满额进度 / 新客礼入口

## EARS

- When 购物车部件配置阈值 `threshold>0`，the system shall 展示满额进度条与剩余金额文案。
- When `show_welcome` 启用，the system shall 展示新客礼入口链接。

## 实现

- Service：`MarketingCartProgressService`
- Widget：`cart-progress`；Taglib：`marketing-cart-progress`
- 欢迎入口亦可 `welcome-gift` 部件
