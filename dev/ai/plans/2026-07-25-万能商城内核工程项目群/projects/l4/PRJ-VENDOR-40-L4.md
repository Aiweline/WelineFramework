# PRJ-VENDOR-40 L4 执行卡

## 1. 判定

| 项 | 值 |
|---|---|
| 状态 | **DONE** |
| 上游 | **`PG-6` / `GATE-P3 = GO`** |
| 当前 | **`GATE-P4A = GO`** |
| 硬约束 | 独立 owning module；sandbox\|live；快照不可变；MIG 隔离 clone |

## 2. TASK

| TASK | 状态 | 证据 |
|---|---|---|
| `TASK-P4A-001` | **ACCEPTED** | `2026-07-25-0003-slug-task-p4a-001-vendor-identity-acl` |
| `TASK-P4A-002` | **ACCEPTED** | `2026-07-25-0008-slug-task-p4a-002-vendor-split-payout` |
| **`GATE-P4A-BUILD`** | **GO** | 001+002 |
| `TASK-MIG-P4A` | **ACCEPTED** | `2026-07-25-0012-slug-task-mig-p4a-vendor-cutover` |
| **`GATE-P4A`** | **GO** | MIG + CLI `commerce:migrate-p4a-vendor` |

## 3. 解锁

下游 Subscription/B2B/Asset 均已 DONE；`GATE-P4=GO` / `PG-7=GO`。无本卡未完成 TASK。生产 apply 见 `RES-MIG-PROD-CUTOVER`。
> **程序群已收官（2026-07-25 11:35）：** `PG-8 = GO`，`DEF-AUDIT-01`…`DEF-AUDIT-06` 全部 FIXED（`00-项目群主计划.md` §6.2）。生产 apply 仍属残余 `RES-MIG-PROD-CUTOVER`，须另立部署授权任务。

