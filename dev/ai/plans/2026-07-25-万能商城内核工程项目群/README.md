# 万能商城内核工程项目群

本目录把《万能商城内核 R4》从单一超大技术计划重组为可治理、可分批开工、可独立验收的工程项目群。它不重新设计 R4 已关闭的架构决策；技术契约、MOD、TEST、ACC 和回滚规则仍以 R4 为准。

## 权威边界

- 技术与架构事实源：[`万能商城内核_f0b923cd.plan.md`](../万能商城内核_f0b923cd.plan.md)
- 工程组织与调度事实源：[`00-项目群主计划.md`](00-项目群主计划.md)
- 项目及任务唯一归属：[`01-项目与任务台账.md`](01-项目与任务台账.md)
- 实施治理：[`02-工程治理与执行节奏.md`](02-工程治理与执行节奏.md)
- 质量、迁移与发布门禁：[`03-质量门禁与发布策略.md`](03-质量门禁与发布策略.md)

发生冲突时，安全边界和技术契约以 R4 为准；项目归属、WIP、状态、Gate 和证据包以本目录为准。任何会改变公共接口、数据所有权、权限、安全、支付、迁移或回滚语义的偏差，必须先更新 R4 的 DEC/MOD/TRACE，禁止执行者临场选择。

## 项目分册

- [`projects/P0-P1-基础平台.md`](projects/P0-P1-基础平台.md)：工程准备、迁移底座、Scope、异步、配置与安全
- [`projects/P2-交易内核.md`](projects/P2-交易内核.md)：目录、库存、Provider、订单、结账、支付退款
- [`projects/P3-高级能力.md`](projects/P3-高级能力.md)：多仓、完整税务、搜索
- [`projects/P4-商业扩展.md`](projects/P4-商业扩展.md)：Vendor、订阅、B2B、客户资产
- [`projects/l4/PRJ-MIG-00-L4.md`](projects/l4/PRJ-MIG-00-L4.md)：迁移底座的文件/符号级执行卡
- [`projects/l4/PRJ-SCOPE-10-L4.md`](projects/l4/PRJ-SCOPE-10-L4.md)：第一阶段 Scope 的真实验收与修复卡
- [`execution/PROGRAM-LEDGER.md`](execution/PROGRAM-LEDGER.md)：实时 Gate、dirty、授权与阻断事实源

## 执行模板

- [`templates/项目章程模板.md`](templates/项目章程模板.md)
- [`templates/任务执行与证据模板.md`](templates/任务执行与证据模板.md)
- [`templates/变更与阻断报告模板.md`](templates/变更与阻断报告模板.md)

## 开工规则

1. 本 R4 程序群已由 2026-07-28 current-source 最终总验收签署
   `GATE-P1/P2/P3/P4=GO` / `PG-8=GO`；不得回写已关闭的 76 TASK 范围。
2. 发现账面/证据滞后必须写回缺陷表（`00-项目群主计划.md` §6.1/§6.2）并修正，禁止事后静默改状态。
3. 生产 cutover（`RES-MIG-PROD-CUTOVER`）必须另立任务并获独立授权；禁止在共享 `weline` 上 apply。
4. 未取得明确授权，不新增测试产物、不提交/推送/部署、不运行生产写入或真实支付。

## 当前状态

- 项目群状态：`PROGRAM_DONE` / `GATE-P1/P2/P3/P4 = GO` / `PG-8 = GO`
- 可执行：计划缺陷修正；另立部署任务（生产持久化 on，须明示授权）
- 不可执行：无部署授权的共享库 MIG apply、production 持久化 `on`、真实支付
- 「继续」单独不等于生产部署授权
- 计划基线：R4.1 SHA-256 `021186844ba138eae8874be94e46e6a569f369c9a57fccb942d1d3fdd557ab22`
- `PG-7` 证据：`dev/ai/codex/tasks/2026-07-25/2026-07-25-0204-pg-7-final-acceptance/`
- 残余证据：`dev/ai/codex/tasks/2026-07-25/2026-07-25-1028-res-mig-prod-cutover-auth-gate/`
- `PG-8` 审计收口：`dev/ai/codex/tasks/2026-07-25/2026-07-25-1117-audit-remediation-pg8/`（扫描 EXIT=0）
- `PG-8` current-source 最终总验收：
  `dev/ai/codex/tasks/2026-07-28/2026-07-28-2211-pg-8-current-source-final-audit/`
  （PostgreSQL schema 96/96、P1–P4 选定 E2E 7/7、内置 Browser console 0、
  rollout/fixture/clone/WLS 清理）
- 归档：`dev/ai/plans/2026-07-25-万能商城内核工程项目群/`
