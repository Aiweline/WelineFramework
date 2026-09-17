# 规格：结账遗弃营销挽回 Phase 2

## 分类

- work_kind: feature
- FE/BE: BE（Cron/邮件）+ 后台管理薄 UI（类型选择）
- ui_skill_decision: skip（管理表单沿用既有 Backend 控件）

## EARS

- When 存在 `status=enabled` 且 `type=checkout_abandon_reminder` 的挽回活动，系统 shall 按 Cron（约每 15 分钟）扫描候选遗弃结账报价。
- When 候选报价 `now < created_at + abandon_after_hours`，系统 shall 不发送、不写「未到遗弃」日志（下次再评）。
- When 发信前 `get_stale_quote` 为空（已下单/不可挽回），系统 shall 记 `skipped` 且 reason=`already_converted_or_ineligible`。
- When 仍遗弃且 `reachable` 且 `continue_checkout_url` 非空，系统 shall 经 `smtp.send` 渠道 `Weline_Marketing::checkout_abandon_reminder` 发信，并写 `WinbackSendLog`（campaign+quote_token 存于 order_uuid 列+step 唯一）。
- When 发信，系统 shall 使用报价 DTO 的 `locale` 选择模板语言；仅当快照无有效 locale 时回退网站默认语言。
- When 发信且 DTO 含 `line_items`，系统 shall 注入 `items_html` 商品明细块（图/标题/规格/价格），模板 `{{var.items_html|raw}}`。
- When 仍遗弃但不可达（无 continue-checkout URL），系统 shall 记 `skipped` 且 reason=`unreachable`。
- While Phase 2，系统 shall 默认 `max_steps=1`；Marketing shall 仅经 `checkout_signals` / SMTP 契约，禁止 use Checkout/Order 内部 Model。

## 用例

### UC1 启用活动后首次提醒

1. 运营创建并启用挽回活动（类型=结账遗弃提醒，遗弃小时=24）。
2. Cron 拉取启用活动 → `list_stale_quotes` → 过滤遗弃窗 → 再验 → 发信 → 写 sent 日志。

### UC2 已转化跳过

1. 列表仍含报价，但再验已下单或不存在。
2. 记 skipped，不发信。

### UC3 未到遗弃

1. 进入结账未满 abandon 小时。
2. 跳过且不写日志。

### UC4 不可达

1. 再验无 `continue_checkout_url` 或 `reachable=false`。
2. 记 skipped reason=`unreachable`。

## 非目标

- 多步骤阶梯文案、短信/站内信、复杂受众规则。
- Checkout 模块写遗弃状态；Marketing 不直读 Checkout Model。

## 多步与激励（REQ-MARKETING-0011）

- 同未付：多步间隔 + step≥2 激励券。
- 新日志主体键 `qt:{quote_token}`。
- 可选 `segment_id`。
