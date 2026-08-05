# PRJ-SEARCH-32 L4 执行卡

## 1. 判定

| 项 | 值 |
|---|---|
| 状态 | **DONE** |
| 上游 | `GATE-P3A = GO`；`GATE-P3B = GO` |
| 当前 | **`GATE-P3C = GO`**（BUILD + MIG） |
| 硬约束 | Product 仍是唯一目录事实源；Search 故障必须 degraded 直读，禁止空成功 |

## 2. TASK

| TASK | 状态 | 证据 |
|---|---|---|
| `TASK-P3C-001` | **ACCEPTED** | `2026-07-24-2335-task-p3c-001-search-index` |
| `TASK-P3C-002` | **ACCEPTED** | `2026-07-24-2346-task-p3c-002-search-query-degraded` |
| **`GATE-P3C-BUILD`** | **GO** | 同上 |
| `TASK-MIG-P3C` | **ACCEPTED** | `2026-07-24-2354-slug-task-mig-p3c-search-cutover` |
| **`GATE-P3C`** | **GO** | MIG + CLI `commerce:migrate-p3c-search` + unit OK |

## 3. 下一步

P3 Search 关闭。程序下一闸：`GATE-P3A + GATE-P3B + GATE-P3C` → **PG-6 / GATE-P3**（Vendor 等 RT-4）。
