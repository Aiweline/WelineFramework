# 规格：购物车遗弃营销挽回（第 1 章）

## 分类

- work_kind: feature
- FE/BE: BE（Cron/邮件）+ 后台管理薄 UI（类型选择）
- ui_skill_decision: skip（管理表单沿用既有 Backend 控件）
- REQ: `REQ-MARKETING-0010`

## EARS

- When 存在 `status=enabled` 且 `type=cart_abandon_reminder` 的挽回活动，系统 shall 按 Cron（约每 15 分钟）扫描 `cart_signals.list_stale_carts`。
- When 候选车 `now < updated_at + abandon_after_hours`，系统 shall 不发送、不写「未到遗弃」日志。
- When `list_stale_carts` 返回行，系统 shall **不因** `has_email=false` 从信号池剔除该行。
- When 发信前 `get_stale_cart` 为空，系统 shall 记 `skipped` 且 reason=`already_converted_or_ineligible`。
- When `checkout_signals.has_quoted_session` 为真，系统 shall 记 `skipped` 且 reason=`active_checkout`（Marketing 去重，Cart 不依赖 Checkout）。
- When `has_email` 且 `reachable` 且 `continue_cart_url` 非空，系统 shall 经 `smtp.send` 渠道 `Weline_Marketing::cart_abandon_reminder` 发信，并写 `WinbackSendLog`（`order_uuid=cart:{cart_key}`，step 唯一）。
- When 无邮箱或不可达，系统 shall 记 `skipped` reason=`unreachable`，主体键仍写入日志。
- When 发信且 DTO 含 `line_items`，系统 shall 注入 `items_html`。
- While 本章，Marketing shall 仅经 `cart_signals` / `checkout_signals` / SMTP，禁止 use Cart/Checkout Model。

## 用例

### UC1 登录客有邮箱

1. 启用购物车遗弃活动。
2. Cron → list → 遗弃窗 → 再验 → 无 quoted → 发信 → sent。

### UC2 无邮箱不丢弃

1. list 含 `has_email=false` 行。
2. skip unreachable；信号仍可列出该车。

### UC3 结账中去重

1. 同客存在 quoted 会话。
2. skip `active_checkout`，不发车信。

## 非目标

- 游客邮箱捕获 UI。
- 短信/站内信。

## 多步与激励（Ch2b）

- 同未付：多步间隔 + step≥2 激励券；主体键仍 `cart:{cart_key}`。
