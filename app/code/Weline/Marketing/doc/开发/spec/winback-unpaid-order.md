# 规格：未付订单营销挽回 Phase 1

## 分类

- work_kind: feature
- FE/BE: BE（Cron/邮件）+ 后台管理薄 UI
- ui_skill_decision: skip（管理表单沿用既有 Backend 控件）

## EARS

- When 存在 `status=enabled` 且 `type=unpaid_order_reminder` 的挽回活动，系统 shall 按 Cron（约每 15 分钟）扫描候选未付订单。
- When 候选订单 `now < created_at + abandon_after_hours`，系统 shall 不发送、不写「未到遗弃」日志（下次再评）。
- When 发信前 `get_unpaid_order` 为空（已付/不可挽回），系统 shall 记 `skipped` 且 reason=`already_paid_or_ineligible`。
- When 仍未付且 `reachable` 且 `continue_pay_url` 非空，系统 shall 经 `smtp.send` 渠道 `Weline_Marketing::unpaid_order_reminder` 发信，并写 `WinbackSendLog`（campaign+order+step 唯一）。
- When 发信，系统 shall 使用订单信号 DTO 的 `locale`（来自下单 scope 快照）选择模板语言；仅当快照无有效 locale 时回退网站默认语言。
- When 仍未付但不可达（无 continue-pay URL），系统 shall 记 `skipped` 且 reason=`unreachable`。
- While Phase 1，系统 shall 默认 `max_steps=1`；Marketing shall 仅经 `order_signals` / SMTP 契约，禁止 use Order/Checkout 内部 Model。

## 用例

### UC1 启用活动后首次提醒

1. 运营创建并启用挽回活动（遗弃小时=24）。
2. Cron 拉取启用活动 → `list_unpaid_orders` → 过滤遗弃窗 → 再验 → 发信 → 写 sent 日志。

### UC2 已付跳过

1. 列表仍含订单，但再验已付。
2. 记 skipped，不发信。

### UC3 未到遗弃

1. 下单未满 abandon 小时。
2. 跳过且不写日志。

## 非目标

- 多步骤阶梯文案、短信/站内信、复杂受众规则。
- 复用促销 `weline_marketing_campaign` 表。
