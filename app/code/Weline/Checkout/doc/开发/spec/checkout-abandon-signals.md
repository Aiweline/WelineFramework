# 结账遗弃信号（Checkout → Marketing）

## 边界

- Checkout 只发布 **quoted 会话事实**（邮箱、金额、`continue_checkout_url`、created_at）。
- **不**定义「遗弃」、**不**内置业务 TTL；工程上对有邮箱会话延长 `expires_at`（默认 7 天）以便挽回窗内 token 仍在。
- **不**发送营销邮件；触达节奏在 `Weline_Marketing` Winback。

## Query：`checkout_signals`

| 操作 | 说明 |
|------|------|
| `list_stale_quotes` | 未过期 `quoted` 列表；接受 `lookback_hours` / `created_after` / `created_before` / `website_id` / `limit` |
| `get_stale_quote` | 按 `quote_token` 再验仍为 quoted 且未过期 |

DTO：`quote_token`、`email`、`reachable`、`continue_checkout_url`（`/checkout?quote_token=`）、`grand_total_minor`、`locale`、`website_id` 等。无邮箱 → `reachable=false`。

## 相关类

- `CheckoutSignalsQueryProvider`
- `AbandonedCheckoutSignalService`
- `ContinueCheckoutUrlBuilder`
- `CheckoutSessionContact`
- `CheckoutSession::TTL_QUOTED_WITH_EMAIL_SECONDS`
