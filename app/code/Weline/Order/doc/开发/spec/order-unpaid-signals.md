# 规格：Order 未付订单信号（Marketing 只读事实面）

## 分类

- work_kind: feature
- FE/BE: BE（Query Provider + URL 能力）
- ui_skill_decision: skip

## EARS

- When Marketing 通过 `w_query('order_signals','list_unpaid_orders')` 拉取候选，系统 shall 仅返回 `payment_status in (pending, partial)`、`status != cancelled`、邮箱非空、且 `order_type` 非 `tob`/`hang` 的订单事实。
- When 调用方传入 `lookback_hours` 或 `created_after`/`created_before`，系统 shall 应用时间窗，并强制最大 lookback 不超过 90 天。
- When 发信前调用 `get_unpaid_order`，系统 shall 再验该订单仍未付；已付/取消/ tob·hang 返回空。
- While 组装 DTO，系统 shall 提供 `continue_pay_url` / `reachable`：有 submitted `checkout_token` 时拼成功页能力 URL；登录用户无 token 可链账户订单；Guest 无 token 则 `reachable=false` 且 URL 为空。
- While 组装 DTO，系统 shall 从 `scope_snapshot_json` 投影结账时语言 `locale`（别名 `language`；空/`default` 则空串），供 Marketing 发信选用。
- The 信号面 shall 不包含遗弃 TTL、不发送邮件、不加 CTA 到 diagnostics。

## 用例

### UC1 列表未付事实

1. Marketing Runner 传入 lookback。
2. Provider 返回 DTO 列表（含 `grand_total_minor`、`checkout_entry` 等）。

### UC2 发信前再验

1. Runner 对候选调用 `get_unpaid_order`。
2. 若已付则跳过触达。

## 非目标

- 不在 Order 内实现挽回活动、Cron、邮件模板。
- 不让 Marketing use Order/Checkout 内部 Model。
