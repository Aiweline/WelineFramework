# 商城内核 Program 执行台账

## 当前快照

| 字段 | 值 |
|---|---|
| Program | `PRG-COMMERCE-KERNEL` |
| 目标 | 完成计划并测试 |
| 技术计划 | R4.1 SHA-256 `021186844ba138eae8874be94e46e6a569f369c9a57fccb942d1d3fdd557ab22` |
| 仓库 | `/Users/weline/Project/Official/框架` |
| 当前 Gate | **`PG-8 = PASS (GO)`；`PROGRAM_DONE`（R4.3）**。历史 `GATE-P1/P2/P3/P4 = GO` 与旧 160 条证据原样保留 |
| 当前可执行 | 程序实现与 R4.3 验收已完成；后续仅限经独立授权的生产面 `RES-MIG-PROD-CUTOVER` |
| 当前不可执行 | 无授权共享库 MIG apply、production `on`、真实支付、不可逆删除 |
| 测试产物授权 | 无（除非用户明示） |
| Git/部署授权 | 无 |
| `PG-7` 证据 | `dev/ai/codex/tasks/2026-07-25/2026-07-25-0204-pg-7-final-acceptance/` |
| `PG-8` 整改 | `dev/ai/codex/tasks/2026-07-25/2026-07-25-1117-audit-remediation-pg8/`（含可复跑扫描 `audit-evidence-scan.py`，**EXIT=0**） |
| `PG-8` current-source 终验 | `dev/ai/codex/tasks/2026-07-28/2026-07-28-2211-pg-8-current-source-final-audit/`（PostgreSQL schema `96/96`；P1–P4 选定 E2E `7/7`；Browser console `0`；资源清理） |
| `PG-8` R4.3 WebUI 终验 | `dev/ai/codex/tasks/2026-08-03/2026-08-03-commerce-r43-final-acceptance/`（121 入口真实点击；`169/169`；0 skip/flaky/unexpected；PG 残留 0；独立 QA PASS） |

## 项目状态（对齐 `01-项目与任务台账.md` 第 2 节）

| Project | 状态 | 备注 |
|---|---|---|
| `PRJ-GOV-00` | ACCEPTED | |
| `PRJ-MIG-00` | ACCEPTED | M00-E 按能力收口（`DEF-MIG-M00E-01`）；生产 cutover 见残余 |
| `PRJ-SCOPE-10` … `PRJ-SEC-13` | ACCEPTED / GO | `GATE-P1=GO` |
| `PRJ-CATALOG-20` … `PRJ-PAYMENT-25` | ACCEPTED | `GATE-P2=GO` |
| `PRJ-WAREHOUSE-30` … `PRJ-SEARCH-32` | DONE | `PG-6=GO` |
| `PRJ-VENDOR-40` … `PRJ-ASSET-43` | DONE | `GATE-P4=GO` / `PG-7=GO` |

> 上表状态为代码与门禁脚本层结论，且已通过 `PG-8` 审计收口（`DEF-AUDIT-01`…`06` FIXED）：P2 薄证据已补 `result.md`，`PG-4` 已独立复跑（PHPUnit + Playwright cart/checkout）。生产/共享库持久化 `mode=on` 仍属残余 `RES-MIG-PROD-CUTOVER`（生产面 `AUTH_REQUIRED`）。

## 计划缺陷

- 已 FIXED（收官时轮）：见 [`00-项目群主计划.md`](../00-项目群主计划.md) §6.1 —— `DEF-PG7-DOC-01/02/03`、`DEF-MIG-M00E-01`、`DEF-MIG-CLONE-PIPE`、`DEF-MIG-I18N-NS`。
- **FIXED（审计收口）**：§6.2 全部 `DEF-AUDIT-01`…`06`；另修 `DEF-MIG-I18N-ORDER`。证据：`2026-07-25-1117-audit-remediation-pg8`（扫描 EXIT=0）。


## 残余

| ID | 内容 | 状态 |
|---|---|---|
| `RES-MIG-PROD-CUTOVER` | 真实库 apply / allowlist / on | 隔离 clone 契约面 **ON_GO**；生产面 **AUTH_REQUIRED**（须明示环境/能力/token/Owner，见 `00-项目群主计划.md` §6.0.1） |

## 下一判定点

1. 2026-07-28 的 `PG-8 = GO / PROGRAM_DONE` 继续冻结为旧基线历史证据。
2. R4.3 `R43-010..120` 已全部通过，当前签署 `PG-8 = PASS (GO) / PROGRAM_DONE`。
3. `RES-MIG-PROD-CUTOVER` 契约面 **ON_GO**（隔离 clone）；生产/共享库持久化 on 须另立部署任务。
4. 「继续」单独**不等于**生产部署授权。
