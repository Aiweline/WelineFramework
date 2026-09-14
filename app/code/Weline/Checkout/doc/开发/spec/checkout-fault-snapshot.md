---
status: ready-for-plan
work_kind: feature
feature_slug: checkout-fault-snapshot
module: Weline_Checkout
updated: 2026-09-14
fe_be_scope: BE 主（CheckoutSession 错误列 + getData/freeze）；FE 轻（sessionStorage 回传 quote_token）；后台现有会话页筛选/详情
ui_skill_decision: skip
clarify_status: locked-from-prior
---

# 结账异常 Session 快照（覆盖、不累计）

> REQ-CHECKOUT-0007  
> 审图分流：店面 `web_ui` / `error_shot`（缺重空态）本需求**不改**店面空态样式。  
> HelpPay 本迭代不接。

## 澄清记录

| Q | A（已锁定） |
|---|---|
| 独立故障表？ | 否。仍用 `weline_checkout_session` |
| 次数累计 / 历史数组？ | 否。同一 session 出错**覆盖**一份快照 |
| 新后台菜单？ | 否。现有「结账会话」加「仅异常」筛选 |
| 正常路径？ | 不写快照；修好后**清空** error 列 |
| 空车 / 未选国家？ | 不写 error 快照 |

## 用户故事

As a 运营, I want 结账严重错误（缺重/缺汇率/地址已齐无运力）落在同一条结账会话上, so that 后台能筛到最新一份快照，而不会因 getData 刷出多行历史。

## EARS

- WHEN 进入结账 `getData` 且已有购物车身份 THEN 系统 SHALL 复用或创建**一条** `weline_checkout_session`（`quote_token`），并在响应里带回 token。
- WHEN 同一浏览器后续 `getData` / `freezeQuote` 带上该 token THEN 系统 SHALL **更新同一行**，不得因出错再插入新 session。
- WHEN 命中严重错误门禁 THEN 系统 SHALL **覆盖**该行的 error 快照字段（最新一份；无历史数组、无 `occurrence_count`）。
- WHEN 本次 getData 正常（有运力或未达严重门禁） THEN 系统 SHALL **清空**该行 error 快照。
- WHEN 空车或未选 ISO 国家 THEN 系统 SHALL 不写 error 快照。
- WHEN `loadShippingMethods` 调用 `listQuoteOptions` THEN 系统 SHALL 使用**本次响应**的 `quote_diagnostics`，SHALL NOT 读全局 `getLastQuoteDiagnostics()`。
- WHEN freeze 已带浏览 session token 且该行未提交 THEN 系统 SHALL 更新该行，SHALL NOT 无条件新开 `qt_`。
- WHEN Express 空运力且诊断为严重码且已有冻结 token THEN 系统 SHALL 覆盖**同一** session 的 error 快照。
- WHEN 快照写入失败 THEN 系统 SHALL 不影响结账响应。

严重码：`missing_weight`、`fx_skipped`、`shipping_unavailable`（地址已齐）、`checkout_blocked`、`freeze_failed`、`submit_failed`。

快照内容：国省市邮编、行 sku/product_id/weight_minor/qty、本次 quote_diagnostics、空态文案。无支付、无完整电话/街道。

## 用例

### UC-1 缺重覆盖同一 token

| 字段 | 内容 |
|------|------|
| 角色 | 买家 / 运营 |
| 前置 | 购物车有需配送商品且 `weight_minor=0`；已选 US |
| 主成功 | getData 返回 `quote_token`；第二次缺重仍同一 token；error_code=`missing_weight` |
| 备选 | 修好重量后再 getData：error 列为空 |
| 异常 | 空车或仅国家未齐：不写 error |

### UC-2 freeze 复用浏览 session

| 字段 | 内容 |
|------|------|
| 角色 | 买家 |
| 前置 | getData 已发 token |
| 主成功 | freezeQuote 带同一 token，表中仍一行 |
| 异常 | 已 submitted 则新开 token，不覆盖已提交行 |

### UC-3 后台仅异常

| 字段 | 内容 |
|------|------|
| 角色 | 运营 |
| 前置 | 存在带 `missing_weight` 的会话 |
| 主成功 | `?error=1` 列表可见该 token；详情含当前快照 |
| 非目标 | 不新增菜单、不新表 |
