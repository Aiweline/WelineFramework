---
status: ready-for-plan
work_kind: feature
feature_slug: conversion-event-dedupe
module: Weline_Visitor
updated: 2026-09-18
fe_be_scope: FE（先面板，再去重，最后桥接）+ BE（purchase 族别名账本）；Checkout/Payment 关键页显式 track
ui_skill_decision: skip
clarify_status: locked-from-prior
---

# 结账关键页转化事件去重（先面板，再去重，最后桥接）

> REQ-VISITOR-CONVERSION-DEDUPE

## 澄清记录

| Q | A（已锁定） |
|---|---|
| 复杂度 | 关键页转化事件；前后端约定参数 |
| TTL | 默认 180 天；后台可配 |
| 前端存储 | `localStorage`（非 session） |
| 主事件流 | **不得**因去重提前 `return null`；监视「全量数据流」每次都要能看到带 value/items 的成功事件 |
| 顺序 | 先 `sandbox.emit` 进面板（桥接状态「待发送」），再去重占用，最后才 `gtag` / `dataLayer` / 厂商帧 |
| 桥接状态 | 同一 `event_id` 回写：未发送·去重 / 未发送·无单号 / 未发送·已过滤 / 已发送·GA4 / 已发送·GTM / 未接通。回写不再调用 `gtag` |
| 后端 | `purchase` 族（`checkout_success` / `payment_success` / `*_checkout_success`）按别名记账；任一单号命中则 `skipped=duplicate`；无单号 `skipped=missing_business_key` |
| 无业务键 | `purchase` 族不桥接、不记购买；其它事件仍不去重 |

## 用户故事

As a 运营, I want 同一订单/交易的转化在刷新成功页时报表只计一次、且事件监视仍能看到完整转化参数, so that 沙盒不刷爆且数据流可诊断。

## EARS

- WHEN 转化去重开关开启且事件在白名单 THEN 系统 SHALL 先把事件送进监视数据流，再判定去重，最后才桥接第三方。
- WHEN 前端主 `WelinePixel.track` 被调用 THEN 系统 SHALL 照常进入事件流，不得因去重提前 `return null`。
- WHEN `purchase` 族任一别名（`transaction_id` / `order_uuid` / `order_id` / `checkout_group_uuid` / `transaction_no`）在 TTL 内已占用 THEN 系统 SHALL 不调用 `gtag` / `dataLayer` / 厂商帧，面板同一行标「未发送·去重」，并黄标 `hit_kind=dedupe`。
- WHEN `purchase` 族没有任何单号 THEN 系统 SHALL 不桥接、不记购买入库，面板标「未发送·无单号」，不占键；别名只取事件载荷，不得从页面 DOM 渗入。
- WHEN 首次未见过 THEN 系统 SHALL 在桥接前同步占用全部别名，再桥接；面板回写「已发送·GA4」或「已发送·GTM」或「未接通」。
- WHEN 后端账本在 TTL 内已有同一 `purchase` 族任一别名 THEN 系统 SHALL 返回 `skipped=true, reason=duplicate` 且不入库。
- WHEN 开关关闭或事件不在白名单 THEN 系统 SHALL 不去重。
- WHEN TTL 过期 THEN 系统 SHALL 允许再次桥接与入库。
- `checkout_failure` 不进入 `purchase` 族。

## 用例

### UC-1 成功页刷新（监视开着）

| 字段 | 内容 |
|------|------|
| 角色 | 买家 / 开发 |
| 前置 | `/checkout/success` 已带 `order_uuid`；去重开启；事件监视已开 |
| 主成功 | 每次刷新全量数据流都有 `checkout_success`（含 value/currency/items）；首次桥接状态为已发送，二次起同一行「未发送·去重」且不再 `gtag` |
| 异常 | 清 localStorage 后沙盒可再发；后端仍可能 duplicate |

### UC-2 配置关闭

| 字段 | 内容 |
|------|------|
| 角色 | 运营 |
| 前置 | `conversion_dedupe_enabled=0` |
| 主成功 | 重复刷新可再次入库与沙盒发送（与改造前一致） |
