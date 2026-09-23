# 需求会话总控（SESSION）模板

> 复制到归属模块 `doc/开发/session/{feature-slug}.md`。  
> **仅项目经理（或监工兼任）维护本文**；专席禁止改 SESSION。  
> 明细仍在 `doc/开发/spec/{slug}.md` 与 `doc/开发/team/{slug}/`；本文只做总控索引 + 计划项生命周期。  
> 权威：`dev/ai-command/ai/工程团队.md`；硬规则 `requirement_session_dashboard` / `pm_plan_lifecycle` / `requirement_issuer_owns_acceptance`。

---
slug: {feature-slug}
module: Weline_Example
mode: team # team | 监工
wave: clarifying # clarifying|align_freeze|tech_scheme|construction|specialty_review|ui_prototype_gate|test_exec|pm_recheck|huishen|delivered
status: open # open | blocked | closed
updated: YYYY-MM-DD
last_checked_by: 项目经理
spec_path: ../spec/{feature-slug}.md
team_path: ../team/{feature-slug}/ # 监工可写 N/A
---

## 当前阶段

- 交付流程阶段：`1b 澄清` / `3 计划` / `3b 团队` / `4 实现` / `5 复审` / `6 测试` / `7 收口`（择一写清）
- team 波次：与 YAML `wave` 一致
- 一句话进度：

## 计划项表

| plan_id | 来源 | 负责人席 | issuer_seat | issuer_acceptance | 状态 | 验收指针 | 关闭条件 |
|---------|------|----------|-------------|-------------------|------|----------|----------|
| main | 用户需求 | 项目经理汇总 | — | N/A | open | UC-1 / e2e-… | 全部子项 closed + 测试过 + PM 复检 + 汇审 |
| <!-- 例：perf-hot-list | 性能检查工程师 escalate | 后端 | 性能检查工程师 | pending | open | UC-perf-1 | 施工+测试+PM DoD + issuer_acceptance=pass | --> |

状态枚举：`open` | `assigned` | `in_progress` | `awaiting_test` | `pm_recheck` | `rework` | `closed`

`issuer_seat`：对本 plan_id 发出 escalate/`dev_ask` 的席位；用户主需求可写 `—`。  
`issuer_acceptance`：`pending` | `pass` | `fail` | `N/A`（无发起席时）。finding 衍生项默认 `pending`。

**关项硬条件**：施工到位 + 测试席对相关 UC 验完且无问题 + PM 复检通过 +（有 `issuer_seat` 时）**`issuer_acceptance=pass`**。禁止专席自报完成就关；禁止测试未过就关；禁止发起方未签收就关。

## 未完成清单

- （列出所有非 `closed` 的 plan_id + 其它 gaps；无则写「无」）

## 审查索引

| 席位 | verdict | 纪要路径 | 一句话结论 |
|------|---------|----------|------------|
| | pass/fail/pending | ../team/{slug}/meetings/… | |

## 相关入口

- （累积 `related_web_urls`；收口交付地址以此为准）

## 停工 / escalate

- 无 / 见 `../team/{slug}/stop-work.md` 或 channel 链接

## 交付通知日志

| 时间 | 来自席位 | result | PM DoD 检查 |
|------|----------|--------|-------------|
| ISO8601 | 后端 | closed | pass / fail / 待补 |

## 返工循环日志

| 时间 | 起因 | 拉起席位 | 再验结果 |
|------|------|----------|----------|
| | | | |

## PM DoD 检查清单（每次收交付勾选）

- [ ] 契约交付物路径存在
- [ ] 该有时 `related_web_urls` / 证据指针非空
- [ ] 未偷改已冻 UC 意图
- [ ] 计划项负责人与 `contracts.md` 一致
- [ ] 测试未到 → 不得将该 plan_id 标 `closed`
- [ ] 有 `issuer_seat` 时：已写进度汇报并 resume 发起席；`issuer_acceptance=pass` 才可关项（`requirement_issuer_owns_acceptance`）
- [ ] 本检查 ≠ 替代架构/专席合规/代码级复审（那些仍由对应席）
