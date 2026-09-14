# 结账入口归因（checkout_entry）

## 背景

万能结账 / 快捷支付 / 快捷购买 / 代付共用结账域能力，运营需在订单与结账会话上快速区分入口并筛选统计。

## EARS

1. When 万能结账 `freezeQuote` 成功，系统 shall 将会话与后续提交订单的 `checkout_entry` 记为 `checkout`。
2. When 快捷支付 Express `start` 冻结报价，系统 shall 记为 `express`。
3. When 结账会话落库，系统 shall 将 `checkout_entry` 写入独立列（可索引筛选），并保留在 payload。
4. When OrderFacade 经结账 submit 建单，系统 shall 将同会话 `checkout_entry` 写入订单列。
5. When 后台结账会话 / 订单列表传入 `checkout_entry` 筛选，系统 shall 按列精确过滤。
6. If 历史行无值，系统 shall 展示「未标记」，不得伪造入口。

## 非目标

- 不复用 `source_app`（应用身份）/ `order_type`（toc|tob）。
- HelpPay 支付链若不经 Checkout 会话，本规格不强制会话行；订单侧可后续扩展。

## UC

- UC1 运营打开结账会话 → 看来源列 → 筛「万能结账」
- UC2 运营打开订单列表 → 筛「快捷支付」→ 统计该入口单量
