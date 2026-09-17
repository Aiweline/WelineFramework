# REQ-MARKETING-0013 — 薄分群

## EARS

- When 活动配置 `segment_id`，Runner shall 仅对命中分群的客户发信。
- When `segment_id=0`，the system shall 不过滤分群。
- The system shall 经 `customer_signals.get_customer_facts`（及 order_signals 已购摘要）求值，Marketing **不**直读 Order/Customer Model 做分群。

## 种类

- `new_customer` / `returning` / `idle_days`（config.idle_days）
