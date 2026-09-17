---
status: implementing
work_kind: feature
feature_slug: tax-duty-estimate-checkout
module: Weline_Tax
updated: 2026-09-15
fe_be_scope: BE 主（Tax DutyEstimate + CheckoutTaxAdvisor + enrich 事件）；FE 轻（结账摘要税行）
ui_skill_decision: participate
---

# 规格：结账 DDU 关税预估入小计

## 需求纠偏

- Shipping Incoterm **只**发 `duty_notice`，**不改** `amount_minor`（ch4 已锁）。
- Tax 监听万能结账 `shipping_methods::enrich` / `freeze quoteTax(shippingContext)`，算出关税+进口税并入 `tax_amount_minor`。
- 店面摘要展示「关税与税费（预估）」；浏览器禁止自造税率。

## EARS

- WHEN 配送行 `duty_notice=duties_taxes_not_included_in_shipping` 且收货国≠发货国 THEN Tax SHALL 按目的国表估算并写入方法行 `tax_amount_minor`。
- WHEN 同国（国内）THEN Tax SHALL 计 0（即使副文仍写「不含关税」）。
- WHEN DAP/DDP THEN Tax SHALL NOT 在结账向买家加收关税额。
- WHEN 销售税 rollout=off THEN duty 估算 SHALL 仍可计额（与 P3B 销售税解耦）。
- WHEN 结账摘要选中含预估的配送方式 THEN 应付合计 SHALL 含该预估。

## UC

| scenario | Given | Then |
|---|---|---|
| ddu_us | CN→US，goods 210.00，DDU | duty 16.80 入摘要 |
| ddu_cn | CN→CN，DDU | charged=0 |
| dap_de | CN→DE，DAP | charged=0 |
