---
status: ready-for-plan
work_kind: feature
feature_slug: express-pay-event-chain
module: Weline_Checkout
updated: 2026-09-18
---

# 快捷支付成功事件链

## 背景

快捷支付（Express）已有漏斗骨架 `checkout_express_pay`（`express_pay` → `express_pay_started` → `checkout_success` → 闭环 `express_pay_checkout_success`），但确认页（`/checkout/express-review`）与扣款交易未入链，点击时也未明确提示当前支付方式。需补齐「选方式 → 拉起 → 确认方式 → 交易 → 成功」闭环。

## 澄清记录

| # | 问题 | 结论（本回合默认） |
|---|------|-------------------|
| 1 | 「提示支付方式」是 UI 还是仅像素载荷？ | 两者：点击时 Toast 展示方式名，且 `express_pay` 载荷含 `payment_method` |
| 2 | 「确认」指 express-review 点「确认并付款」？ | 是；发 `express_pay_confirmed`，载荷含同一 `payment_method` |
| 3 | 「交易」事件时机？ | `confirmExpressCheckout` 成功、跳转成功页前发 `express_pay_transaction` |
| 4 | 是否保留既有 `express_pay_started`？ | 保留，位于点击与确认之间（拉起支付商） |
| 5 | 结账页快捷支付是否同链？ | 是；结账区点击也须 `express_pay` / `express_pay_started` |

## 用户故事

作为运营/分析，我希望用户走完快捷支付后，像素沙盒能看到完整有序事件链并自动上报 `express_pay_checkout_success`，以便与普通结账成功区分。

## EARS

1. When 用户在商品页或结账页点击某快捷支付方式，系统 shall Toast 提示该支付方式，并 track `express_pay`（含 `payment_method`）。
2. When 快捷支付已拉起支付商（redirect / 弹窗 URL 就绪），系统 shall track `express_pay_started`（含同一 `payment_method`）。
3. When 用户进入 express-review 且 review 数据含支付方式，系统 shall 在页面展示该支付方式。
4. When 用户在 express-review 点击「确认并付款」，系统 shall 在请求前 track `express_pay_confirmed`（含 `payment_method` / `transaction_no`）。
5. When `confirmExpressCheckout` 成功，系统 shall 在跳转前 track `express_pay_transaction`（含 `payment_method` / `transaction_no`）。
6. When 成功页 track `checkout_success` 且前序步骤已按序凑齐，系统 shall 由事件链 runtime 自动上报 `express_pay_checkout_success`（带 `__event_chain_complete`）。
7. If 用户未走快捷支付漏斗，系统 shall 不得把普通 `checkout_success` 误当成 `express_pay_checkout_success` 闭环（complete_event 禁止与 `checkout_success` 同名）。

## 非目标

- 不改支付商 capture 协议本身。
- 不把 `express_pay_checkout_success` 设为可直接手 track 的普通转化（仍须链完成标记）。
- 不重构代付/分享/快捷购买另外三条链的步骤数。

## GA4 挂钩（2026-09-18）

- 站内过程名保留；`ga4_event`：`express_pay`→`add_payment_info`，`express_pay_started`→`begin_checkout`，`express_pay_confirmed`→`add_shipping_info`，闭环→`purchase`；`express_pay_transaction` 不进 GA4。
- 权威：`Weline_Visitor` `ga4-recommended-event-parity` 规格。

## UC

### UC1 主成功路径（商品页 PayPal 快捷支付）

1. PDP 点 PayPal 快捷支付 → Toast「PayPal」类提示 + `express_pay`
2. 加购 + startExpress → 打开支付商 → `express_pay_started`
3. 支付商回跳 `/checkout/express-review?transaction_no=…` → 页上可见支付方式
4. 确认地址/配送后点「确认并付款」→ `express_pay_confirmed` → API 成功 → `express_pay_transaction` → 跳转成功页
5. 成功页 `checkout_success` → 链凑齐 → `express_pay_checkout_success`

### UC2 结账页快捷支付

同 UC1，起点为结账页 express 部件；须同样发 `express_pay` / `express_pay_started`。

### UC3 异常

- 确认失败：不发 `express_pay_transaction`；可保留已发的 `express_pay_confirmed`。
- 已支付再进 review：不重复推进交易步。

## 验收

- 字典含 `express_pay_confirmed`、`express_pay_transaction`。
- `RegisterCheckoutEventChainsObserver` 链步骤含上述顺序，末步仍为 `checkout_success`，闭环 `express_pay_checkout_success`。
- UT：Observer + 字典契约；Browser/e2e：沙盒可见链进度或契约断言 JS 埋点存在。
