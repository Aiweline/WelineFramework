---
status: ready-for-plan
work_kind: feature
feature_slug: ga4-recommended-event-parity
module: Weline_Visitor
updated: 2026-09-18
---

# GA4 推荐事件同名齐全与参数对齐

## 背景

站内像素需覆盖 Google Analytics 4 **推荐电商/常用推荐**事件；优先 `weline_event === ga4_event` 直发。仅当必须保留形态区分名（如 `express_pay_checkout_success`）时才映射到 GA4 推荐名。**载荷参数须对齐 GA4 推荐参数名与语义**（系统事件按 Google 参数做）。

## 澄清

| # | 问题 | 结论 |
|---|------|------|
| 1 | 是否改站内事件链过程名为 Google 名？ | 否。快捷支付等过程名保留（漏斗隔离）；`ga4_event` 映射到推荐名。 |
| 2 | 哪些必须映射？ | `*_checkout_success` / `checkout_success` / `payment_success` → `purchase`；`express_pay`→`add_payment_info`；`express_pay_started`→`begin_checkout`；`express_pay_confirmed`→`add_shipping_info`；`search_submit`→`search`；`register`→`sign_up`；`lead_submit`→`generate_lead`；`selection_share`→`share`；`search_suggestion_click`→`select_content`。 |
| 3 | 无 Google 等价？ | `express_pay_transaction` 等：`skip_gtm_push` 或不进 GA4。 |
| 4 | 游戏/虚拟货币推荐事件？ | 非目标（电商站）。 |
| 5 | 参数对齐？ | 是。站内可保留别名，但发往 GA4 时必须带推荐参数名（见下表）。 |

## GA4 电商参数（权威）

| GA4 事件 | 核心参数 |
|----------|----------|
| `add_payment_info` | `currency`, `value`, `items`, `payment_type`（可选 coupon） |
| `add_shipping_info` | `currency`, `value`, `items`, `shipping_tier`（可选 coupon） |
| `begin_checkout` | `currency`, `value`, `items`（可选 coupon） |
| `purchase` | `transaction_id`, `currency`, `value`, `items`（可选 tax/shipping/coupon） |

别名归一（发往 GA4 前）：`payment_method`→`payment_type`；`shipping_method`/`service_code`→`shipping_tier`；`transaction_no`→`transaction_id`；`grand_total`/`total`→`value`。

## EARS

1. When 字典加载，系统 shall 为每条 GA4 电商推荐事件提供同名 `weline_event` 且 `ga4_event` 等于该名。
2. When track 同名推荐事件，系统 shall 直发 GA4（无需再挂钩）。
3. When track 形态闭环或已文档化的站内别名，系统 shall 按字典 `ga4_event` 映射。
4. If 事件无 GA4 等价，系统 shall 不伪造推荐事件名。
5. When 向 GA4/GTM 转发电商推荐事件，系统 shall 使用上表推荐参数名（经别名归一后）。
6. When 快捷支付过程埋点，系统 shall 尽量带齐 `currency`/`value`/`items` 及 `payment_type` 或 `shipping_tier`。

## UC

- UC1 运营开字典 → 见 `purchase`/`add_payment_info`/… 同名条目
- UC2 快捷支付链凑齐 → 站内仍见 `express_pay_*`；GA4 侧为 `add_payment_info`→`begin_checkout`→`add_shipping_info`→`purchase`，且参数名为 Google 推荐名
