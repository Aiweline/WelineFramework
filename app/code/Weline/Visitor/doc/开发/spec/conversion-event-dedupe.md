---
status: ready-for-plan
work_kind: feature
feature_slug: conversion-event-dedupe
module: Weline_Visitor
updated: 2026-09-16
fe_be_scope: FE（沙盒厂商 fanout 去重黄标）+ BE（PixelEventService track 账本）；Checkout/Payment 关键页显式 track
ui_skill_decision: skip
clarify_status: locked-from-prior
---

# 结账关键页转化事件去重（约定参数 → 沙盒丢弃 / 主事件流照常）

> REQ-VISITOR-CONVERSION-DEDUPE

## 澄清记录

| Q | A（已锁定） |
|---|---|
| 复杂度 | 关键页转化事件；前后端约定参数 |
| TTL | 默认 180 天；后台可配 |
| 前端存储 | `localStorage`（非 session） |
| 主事件流 | **不得**因去重提前 `return null`；监视「全量数据流」每次刷新都要能看到带 value/items 的 `checkout_success` |
| 沙盒丢弃 | **仅** `mode=sandbox` 厂商 fanout：TTL 内重复则跳过沙盒 iframe，并黄标 `hit_kind=dedupe` |
| 后端 | `track` 前查轻量账本；重复 `skipped=duplicate` 不入库（报表去重） |
| 无业务键 | 不去重（避免误伤 page_view） |

## 用户故事

As a 运营, I want 同一订单/交易的转化在刷新成功页时报表只计一次、且事件监视仍能看到完整转化参数, so that 沙盒不刷爆且数据流可诊断。

## EARS

- WHEN 转化去重开关开启且事件在白名单且能解析业务键 THEN 系统 SHALL 在前后端按同一键记录/判定。
- WHEN 前端主 `WelinePixel.track` 被调用 THEN 系统 SHALL 照常进入事件流 / 监视透传 / 后端上报路径（不去重拦截）。
- WHEN 沙盒厂商 fanout 且 localStorage 在 TTL 内已有该键 THEN 系统 SHALL 跳过该沙盒发送并以黄标记入监视流。
- WHEN 后端账本在 TTL 内已有 `(website_id, event, business_key)` THEN 系统 SHALL 返回 `skipped=true, reason=duplicate` 且不入库。
- WHEN 首次未见过 THEN 系统 SHALL 在主 track 成功后记键，并放行本轮沙盒发送。
- WHEN 开关关闭或事件不在白名单或无业务键 THEN 系统 SHALL 不去重。
- WHEN TTL 过期 THEN 系统 SHALL 允许再次计入沙盒与后端。

业务键优先级：`transaction_id` → `order_uuid` → `order_id` → `checkout_group_uuid`。

## 用例

### UC-1 成功页刷新（监视开着）

| 字段 | 内容 |
|------|------|
| 角色 | 买家 / 开发 |
| 前置 | `/checkout/success` 已带 `order_uuid`；去重开启；事件监视已开 |
| 主成功 | 每次刷新全量数据流都有 `checkout_success`（含 value/currency/items）；二次起沙盒厂商不重复发，可另见黄标「去重丢弃」 |
| 异常 | 清 localStorage 后沙盒可再发；后端仍可能 duplicate |

### UC-2 配置关闭

| 字段 | 内容 |
|------|------|
| 角色 | 运营 |
| 前置 | `conversion_dedupe_enabled=0` |
| 主成功 | 重复刷新可再次入库与沙盒发送（与改造前一致） |
