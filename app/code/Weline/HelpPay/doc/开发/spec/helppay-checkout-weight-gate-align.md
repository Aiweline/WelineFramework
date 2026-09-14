---
status: implemented
work_kind: feature
feature_slug: helppay-checkout-weight-gate-align
module: Weline_HelpPay
updated: 2026-09-14
plan_skip: true
plan_skip_rationale: 原则已确认（对齐传统结账门禁）；Plan 模式用户已拒；架构选定复用结账域真实重量 fail-closed，禁止壳层假重。
---

# 规格：快捷购买/快捷支付重量与报价对齐传统结账

## 澄清记录

| # | 问题 | 结论 |
|---|---|---|
| 1 | 三条入口能力标准？ | **传统结账**为权威：真实重量、缺重 fail-closed、同源报价。 |
| 2 | 弹窗/Express 能否另写重量？ | **否**。禁止 `weight_minor=500` 静默估价。 |
| 3 | session_isolation？ | **保留**（不写万能结账会话）；与门禁共用无关。 |
| 4 | 缺重文案？ | 与结账一致：说明缺重，不得仅「换地址」。 |

## 非目标

- 不合并三条 UI 为单页
- 不撤回 session_isolation
- 本期不改商品补重运营脚本

## EARS

1. When 快捷购买请求运费报价，the system shall 按商品目录/行快照真实重量计价（与结账同源），**不得**静默 0.5kg。
2. If 可运商品缺重，the system shall fail-closed：无配送选项，并提示缺重（对齐结账 `missing_weight`）。
3. When 创建 quick_pay（含运费），the system shall 服务端按真实重量重报价并校验 `service_code`/运费金额，**不得**盲信前端。
4. When 结账或 Express 行重为 0 且目录有 `weight_kg`，the system shall 用同一解析器回填单位重；仍为 0 则 fail-closed。

## 用例

### UC1 缺重商品

1. 商品无有效 `weight_kg` / 行重  
2. 快捷购买 → 物流步  
3. 期望：不可选国际运费 + 缺重提示；结账同结果  

### UC2 有重商品

1. 目录有重  
2. 快捷购买与结账报价同源车道/金额口径（允许币种展示差，禁止假重导致的可达性差）

## 验收

- 合约 UT：JS 无 `weight_minor: 500`；走 `listQuickShippingOptions`；createQuickPay 复核  
- Checkout/Express/HelpPay 共用 `CheckoutQuoteLineWeightResolver`  
- Browser：缺重样例快捷购买不可付；有重样例可报价  
