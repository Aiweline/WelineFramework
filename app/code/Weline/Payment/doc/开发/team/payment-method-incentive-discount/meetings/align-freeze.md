# 对齐冻结会汇总 — payment-method-incentive-discount

| 项 | 值 |
|---|---|
| 主持 | Team:测试: |
| 日期 | 2026-09-22 |
| 通道 | `channel/align-freeze.md` |
| **frozen** | **true** |
| notify_pm | true |

## 1. 会目标与输入

- 钉死可执行 UC + `contracts.md` + `deps.md` + `surfaces.md`。
- 权威输入：规格、顾问、探查、需求分析、PM msg-1、各席 stance。
- **本波只冻结文档，不写生产 PHP；禁止改 SESSION。**

## 2. 各席 stance 汇总（终态）

| 席位 | stance | 备注 |
|---|---|---|
| 测试（主持） | **同意** → **升格 frozen=true** | UC 可执行步骤已钉；payload 冲突已裁决 |
| 项目经理 | handoff（msg-1） | 开场 |
| 架构师 | **closed / 同意冻结** | surfaces 已有；机制+命名（payload 后由测试与前端对齐） |
| 扩展点 | **同意** | Event/Query/SPI/capability/SystemConfig/Hook；禁跨模块直调 |
| 支付开发工程师 | **同意附条件** | SystemConfig `incentive_*`；PayPal breakdown 守恒；无能力只扣净额不伪造；fake echo；部分退比例；Ledger `TYPE_DISCOUNT` — **已吸收进 contracts** |
| 前端 | **同意** | `incentive_savings_minor` + `incentive_display` 等；禁平行 REST — **已升为正式 payload** |
| 原型 | **同意附条件** | 贴壳 `payment-method-card` + totals；禁脱离结账壳 SaaS 稿 |
| UI | **同意** | 禁划线价；L0 应付真相；层级门禁 |
| 主题开发工程师 | **同意** | Theme Token + `w-*` 门禁；须声明 work_mode |
| 翻译工程师 | **同意** | 模块 CSV 仅 zh+en；其它默认站 locale→词典（纪要 `meetings/翻译-align.md`） |
| 电商顾问 | **同意冻结复核通过** | 先前「待 contracts 复核」→ msg-N 已核对 AC 未弱化 |

**否决**：无。

## 3. 已冻结内容

1. 顾问 AC-1…7 + 叠加矩阵（税前；积分/W币不双重）。
2. **payload 正式名**（测试钉死冲突）：列表 `incentive_savings_minor`（≥0）+ `incentive_display` + `incentive_available` + `incentive_type`/`incentive_percent`；快照 `discount_lines[].source_type=payment_method_incentive`（`amount_minor` 负向）；禁未写入 contracts 的别名验收。
3. 支付席 PAY-1…7 完整吸收。
4. OQ-1…4 全部决议（部分退比例；PayPal 聚合+守恒；Ledger TYPE_DISCOUNT；payload 扁字段）。
5. UC-1…4 可执行步骤 + HARD 门禁（真通路；禁骗绿；抹自动化标志；UI+原型过签；fake≠PayPal）。
6. deps：壳激励+discount_lines → PayPal breakdown → 结账 UI ∥ 翻译 → 测试。

## 4. frozen 判定

| 检查项 | 结果 |
|---|---|
| 顾问条件全部写入 contracts | ✅ |
| 顾问 contracts 复核通过 | ✅（msg-N） |
| 可执行 UC 步骤落盘 | ✅ |
| surfaces 已有 | ✅ |
| 关键席无否决 stance | ✅ |
| payload 冲突钉死 | ✅ |
| 支付附条件吸收 | ✅ |

**结论：`frozen=true`。**

## 5. 交付路径

- `contracts.md`（frozen=true）
- `surfaces.md`（payload 与 contracts 对齐）
- `deps.md`（施工序）
- `meetings/align-freeze.md`（本文）
- `channel/align-freeze.md`（冻结结论 msg）

## 6. 回报

- `result`: **frozen**
- `frozen?`: **true**
- `notify_pm`: **true**
- `@项目经理：对齐冻结已升格 frozen=true；请更新 SESSION 并开支付施工波（deps [1]→[2]）；前端/主题等 UI+原型过签后并行；测试等真通路后再跑 UC。`
