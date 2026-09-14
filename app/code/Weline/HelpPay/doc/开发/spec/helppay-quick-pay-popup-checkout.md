---
status: implementing
work_kind: feature
feature_slug: helppay-quick-pay-popup-checkout
module: Weline_HelpPay
updated: 2026-09-14
---

# 规格：快捷购买弹窗内地址+物流+支付闭环（隔离万能结账）

## 澄清记录

| # | 问题 | 结论 |
|---|---|---|
| 1 | 快捷购买是否整页跳 `/q/`？ | **否**。主路径留在 PDP 弹窗内完成支付；`/q/` 仅跨设备次要入口。 |
| 2 | 弹窗是否只要地址？ | **否**。必须有 **地址 → 物流 → 支付**。 |
| 3 | 是否与 Header/结账配送会话同源写入？ | **否**。`session_isolation=true`，不得改写万能结账车/地址/运费。 |
| 4 | 是否分享出链？ | **否**。本人结账；禁止「发给朋友」分享网格主界面。 |

## 非目标

- 不改「立即结账 / 分享给朋友 / 找朋友代付」
- 不重做完整万能结账页
- 本期不合并 Payment `product-express-pay` 槽

## EARS

1. When 用户在可售零售态点击「快捷购买」，the system shall 打开 HelpPay 弹窗并进入地址步（懒加载结账地址部件，PDP 不 SSR）。
2. When 地址完整确认，the system shall 进入物流步并展示 `listQuoteOptions` 可读名称（`label`/`service_name`），**不得**把 `service_code` 当主文案。
3. When 用户选定物流，the system shall 进入支付步，展示商品+运费应付，并允许在弹窗内发起支付（可开支付商/付款子窗）；**不得**主路径 `location.assign(/q/)`。
4. When 创建 quick_pay 链接，the system shall 锁定收货与 `service_code`（`shipping_locked=true`），金额含运费；meta 标记 `session_isolation`。
5. If 弹窗内选择/编辑地址，the system shall **不**调用会改写万能结账配送上下文的 `selectDeliveryAddress` / `saveDeliveryAddress`（隔离挂载）。
6. If 用户取消或关闭弹窗，the system shall 不污染万能结账已选地址/运费。

## 用例

### UC1 主成功

1. PDP → 快捷购买 → 地址步确认  
2. 物流步选可读运费方案  
3. 支付步确认支付（子窗可打开 `/q/`）→ 弹窗成功态  

### UC2 隔离

1. 万能结账已选地址 A / 运费 X  
2. 快捷购买选 B / Y 后关闭  
3. 结账页仍为 A / X  

## 验收

- 合约 UT：三步 DOM；无主路径 `location.assign`；物流优先 label  
- e2e：确认后进入物流/支付步，非分享网格  
- Browser 禁缓存 WB-OP  
