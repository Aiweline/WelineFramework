---
status: ready-for-plan
work_kind: feature
feature_slug: backend-order-refund-outcome-chips
module: Weline_Order
updated: 2026-09-14
plan_complexity: simple
plan_skip_rationale: 单页结果态展示映射，无新表/无状态机变更/无支付 Provider 耦合。
ui_skill_decision: participate
---

# 退款成功态绿勾一眼可读

## 澄清

| 问题 | 答案 |
|------|------|
| 目标 | 退款「状态/顾客视图」与顶栏退款行不再裸写 `succeeded`，改绿色勾 +「成功」 |
| 非目标 | 不改渠道落库字段；不改退款业务编排 |

## EARS

1. WHEN 退款案例状态为 succeeded，系统 SHALL 在退款历史「状态」列展示绿色成功芯片（勾 +「成功」），不得展示英文 `succeeded`。
2. WHEN 顾客视图为 succeeded，系统 SHALL 同样用绿色成功芯片。
3. WHEN 顶栏展示退款渠道结果，系统 SHALL 用同一成功芯片，不用 warning 色英文 code。
