---
status: ready-for-plan
work_kind: feature
feature_slug: backend-order-status-flow-top
module: Weline_Order
updated: 2026-09-14
plan_complexity: simple
plan_skip_rationale: 单页后台信息层级上移，复用订单头状态与已有退款案例，无新表/无新状态机/无跨模块支付耦合。
ui_skill_decision: participate
---

# 后台订单顶部状态流转

## 澄清

| 问题 | 答案 |
|------|------|
| 目标 | 打开管理订单/详情，不点 Tab、不展开「更多」，一眼看到订单状态轨；有退款/退货时支付方式与渠道退款号也在顶部 |
| 非目标 | 不改状态机规则；不把 PayPal API 写进 Order Controller；不替代退款 Tab 明细表 |
| 角色 | 后台运营看单 |
| 成功 | 首屏顶栏有 `order-status-flow`；退款单可见 paypal + 渠道退款号 |

## 范围

- FE：`edit.phtml` / `view.phtml` 标题下状态轨
- BE：`BackendOrderStatusFlowPresenter` 组装 DTO；Controller 只编排

## EARS

1. WHEN 运营打开已有订单的管理页或详情页，系统 SHALL 在页面标题下方、订单摘要/基本信息之前展示状态流转轨。
2. WHEN 订单状态为已退款或存在退款案例，系统 SHALL 在同一顶部区域展示支付方式、退款状态与渠道退款号。
3. WHEN 运营尚未展开「更多」，系统 SHALL 仍能看见状态轨（不依赖 `order-edit-history`）。
4. IF 订单无退款案例且支付状态非退款，系统 SHALL 仍展示订单状态轨，但不强制退款行。

## 用例

- UC1 主路径：打开已退款沙盒单 → 顶栏可见 已退款 + PayPal + 渠道号。
- UC2 备选：打开未退款单 → 顶栏可见当前状态节点，无退款行。
- UC3 异常：无历史记录 → 仍按订单头状态画出规范路径。

## 架构

- 扩展点：无新事件。Presenter 读取 Order 头 + history + `refundCases`（已有 CommandService）。
- 解耦：不引用 PayPal Provider；渠道号来自已持久化 PaymentRefund 字段。

## 原型

- 问题：顶栏信息层级如何一眼可读。
- 采纳 **A**：标题下横向状态徽章轨 + 退款时第二行 chips（非底部时间线、非摘要 KV 再埋一层）。
- 资产：`view/statics/prototype/order-status-flow.html`
