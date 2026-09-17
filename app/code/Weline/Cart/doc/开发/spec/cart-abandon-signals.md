---
status: ready-for-plan
work_kind: feature
feature_slug: cart-abandon-signals
module: Weline_Cart
updated: 2026-09-15
plan_skip: true
plan_skip_rationale: >-
  Chapter 1 事实面镜像既有 checkout_signals / order_signals：只读 QueryProvider + DTO，
  无店面 UI、无 schema 变更、无架构分叉；实现路径已由结账遗弃信号定型。
---

# 规格：购物车遗弃信号（Cart → Marketing）

## 澄清记录

| # | 问题 | 结论（来自路线图 Ch1 / Checkout 镜像） |
|---|------|----------------------------------------|
| Q1 | Cart 是否定义业务「遗弃」TTL？ | 否。仅尊重工程 TTL（guest `expires_at`；customer 永久）。遗弃窗在 Marketing。 |
| Q2 | 无邮箱购物车是否出现在列表？ | 是。`has_email=false` 仍列出；`reachable=false`。 |
| Q3 | 空车 / 过期车？ | 默认排除空车与已过期车；可用参数控制。 |
| Q4 | 跨模块读法？ | 仅 `w_query('cart_signals', …)`；Marketing 禁止 `use` Cart Model。 |
| Q5 | UI？ | 本阶段无店面/后台新页；仅后台 Query 契约。 |

## 分类

- work_kind: feature（BE 事实面）
- FE/BE: BE only
- ui_skill_decision: skip（无 Web 表面）

## 用户故事

As a Marketing winback runner, I want read-only non-empty cart facts (email, lines, continue URL), so that cart-abandon campaigns can decide reachability without coupling to Cart internals.

## EARS

- WHEN `list_stale_carts` 被调用 THEN 系统 SHALL 返回未过期且（默认）非空的购物车 DTO 列表，**不因无邮箱而过滤**。
- WHEN 购物车 `owner_kind=customer` THEN 系统 SHALL 从 Customer 解析邮箱；若 `payload_json` 另有邮箱字段亦纳入解析（Customer 优先）。
- WHEN 邮箱非空 THEN `has_email` SHALL 为 true；否则 false。
- WHEN `has_email` 且能拼出绝对店面 `/cart` URL THEN `reachable` SHALL 为 true 且 `continue_cart_url` 非空；否则 `reachable=false` 且 URL 为空。
- WHEN `get_stale_cart` 按 `cart_key`（持久化键 / `customer:{id}` / `guest:{token}` / `cart_id`）再验 THEN 系统 SHALL 仅在仍未过期且（默认）非空时返回同一 DTO 形状。
- WHILE 本事实面，Cart SHALL **不**发送营销邮件、**不**写入遗弃状态；触达归属 Marketing。

## 用例

### UC-1 列出可挽回候选（含无邮箱）

| 字段 | 内容 |
|------|------|
| 角色 | Marketing Cron / 后台运维 |
| 前置 | 存在若干未过期非空车（含无邮箱客户车） |
| 主成功步骤 | 1. 调 `w_query('cart_signals','list_stale_carts',…)` 2. 断言 items 含 `has_email=false` 项 3. 有邮箱项 `reachable` 与 `continue_cart_url` 一致 |
| 备选/异常 | 空库 → items=[] |
| 期望结果 | meta.count 与 items 长度一致；无触达副作用 |
| 映射 | UT `AbandonedCartSignalContractTest` |

### UC-2 发信前再验

| 字段 | 内容 |
|------|------|
| 角色 | Marketing Runner |
| 前置 | 列表曾返回某 `cart_key` |
| 主成功步骤 | 1. `get_stale_cart` 同键 2. 仍非空未过期则返回 DTO |
| 备选/异常 | 已清空 / 已过期 → item=null |
| 期望结果 | 与 list DTO 字段同构 |
| 映射 | UT provider/service 契约 |

## Query：`cart_signals`

| 操作 | 说明 |
|------|------|
| `list_stale_carts` | 未过期非空列表；`lookback_hours` / `updated_after` / `updated_before` / `website_id` / `limit` / `exclude_empty` |
| `get_stale_cart` | 按 `cart_key` 再验 |

DTO：`cart_key`、`owner_kind`、`customer_id`、`email`、`has_email`、`reachable`、`continue_cart_url`、`line_items`、`currency`、`totals`、`website_id`、`store_id`、`locale`、`updated_at`、`expires_at`、`created_at`。

## 相关类

- `CartSignalsQueryProvider`
- `AbandonedCartSignalService`
- `ContinueCartUrlBuilder`

## 非目标

- 遗弃业务 TTL、发信、Winback 活动配置（属 Marketing 后续章）。
- 改动店面 `/cart` 页或加购链路。
