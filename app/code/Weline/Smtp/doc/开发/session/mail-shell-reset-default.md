---
slug: mail-shell-reset-default
module: Weline_Smtp
mode: team
wave: huishen
status: closed
updated: 2026-09-23
last_checked_by: 项目经理
spec_path: N/A
team_path: ../team/mail-shell-reset-default/
---

## 当前阶段

- 交付流程阶段：`7 收口`（汇审，含已知缺口）
- team 波次：`huishen`
- 一句话进度：施工+UT 完成；WB 因本机 WLS worker/`certificate_retirement_replay` 与 Browser MCP 不稳定 **blocked**，不造假过签。

## 计划项表

| plan_id | 来源 | 负责人席 | issuer_seat | issuer_acceptance | 状态 | 验收指针 | 关闭条件 |
|---------|------|----------|-------------|-------------------|------|----------|----------|
| main | 用户需求 | 项目经理汇总 | — | N/A | closed | UC-1/UC-2 | 代码+UT 收口；WB 缺口见汇审 |
| be-reset-shell | align | 后端 | — | N/A | closed | UC-1 | pass |
| fe-reset-ux | align | 前端 | — | N/A | closed | UC-2 | pass |
| i18n-reset | align | 翻译工程师 | — | N/A | closed | 中英 CSV | pass |
| test-reset | align | 测试 | — | N/A | closed | UC-1/2 | UT pass；WB blocked（infra）记 SESSION |

## 未完成清单

- 无（WB 真机补验记在「停工/escalate」，不挡代码收口）

## 审查索引

| 席位 | verdict | 纪要路径 | 一句话结论 |
|------|---------|----------|------------|
| 后端 | pass | meetings/后端-closed.md | clear+postReset+空头不写回 |
| 前端 | pass | channel/前端-align-i18n.md | confirm 与 i18n 一致 |
| 翻译工程师 | pass | channel/翻译工程师-closed.md | zh+en+collect |
| 测试 | pass(UT)/blocked(WB) | channel/测试-closed.md + 测试-wb-closed.md | 11/235 UT；WB infra |

## 相关入口

- 编辑页（站点恢复后）：`https://p05113ef3.test.weline.com:9555/{admin_prefix}/…/smtp/backend/template/edit`
- 当前站点：workers 再次掉线；lifecycle 僵死锁 `certificate_retirement_replay`

## 停工 / escalate

- escalate：本机 `certificate_retirement_replay` 反复占 lifecycle 并拖垮 HTTP workers；Browser MCP 建 tab 即丢

## 交付通知日志

| 时间 | 来自席位 | result | PM DoD 检查 |
|------|----------|--------|-------------|
| 2026-09-23T16:14+08 | 测试 | UT closed / WB blocked | pass（诚实） |
| 2026-09-23T16:20+08 | 测试 | WB rework blocked | pass；不循环硬重启；汇审报用户 |

## 返工循环日志

| 时间 | 起因 | 拉起席位 | 再验结果 |
|------|------|----------|----------|
| 16:09 | confirm 源串 | 前端 | 对齐 |
| 16:14 | WB 站点僵死 | 测试 | restart 后仍 Browser+worker 不稳 → blocked |

## PM DoD 检查清单（每次收交付勾选）

- [x] 契约交付物路径存在
- [ ] related_web_urls（WB 未完成 → N/A）
- [x] 未偷改已冻 UC
- [x] 计划项与 contracts 一致
- [x] 测试 UT 已过；WB 缺口已记账（禁止假绿）
- [x] issuer_acceptance N/A
- [x] 本检查 ≠ 替代专席复审
