---
status: acceptance_passed
work_kind: feature
feature_slug: wls-resident-pitfalls-fix
module: Weline_Server
updated: 2026-10-11
session_path: ../../../../../../../dev/session/wls-resident-pitfalls-fix.md
team_path: ../../../../../../../dev/team/wls-resident-pitfalls-fix/
seed: ../../../../../../../dev/tmp/wls-resident-pitfalls-seed-20261011.md
acceptance_dig: ../../../../../../../dev/tmp/chaos-local-mix30-cold-20261011134227/
issuer_perf: ../../../../../../../dev/team/wls-resident-pitfalls-fix/channel/issuer-perf.md
test_acceptance: ../../../../../../../dev/team/wls-resident-pitfalls-fix/channel/test-acceptance.md
server_version: '2.0.175'
framework_version: '2.5.343'
related_specs:
  - ./storefront-mix30-capacity-warm.md
  - ../../Framework/doc/开发/spec/storefront-mix30-wallclock.md
  - ./wls-async-industry-align.md
covers_use_cases:
  - UC-OBJ-01
  - UC-UTIL-01
  - UC-ORCH-01
  - UC-WARM-01
  - UC-FPC-01
  - UC-LEDGER-01
  - UC-NO-SHELL
  - UC-NO-BLIND-UNPIN
  - UC-HOLD-QUIET
  - UC-U4-OBS
  - UC-U5-OBS
mcp_note: docs seat — prepare_project / aoci_rules MCP timed out; ensure ready + host Read AI硬规则索引.md + 文档工程师.md; did not pretend MCP prepare/aoci succeeded.
---

# Spec：WLS 常驻内存业界坑修复（利用率 / 编排 / FPC / compact / 多 Host）

> **status: `acceptance_passed`（验收通过指针）**  
> 采信 dig：`dev/tmp/chaos-local-mix30-cold-20261011134227/`（权威 `134227`）· issuer：`channel/issuer-perf.md`（三 plan+main `pass`）· 测试：`channel/test-acceptance.md`（**28/131**）。  
> 实现版本：Server `2.0.175`（Scale status Fiber·pin Known gap）· Framework `2.5.343`（DiagTrack + Clear `fpc_warm_ok`）。  
> dig 全文与风暴表留在 `dev/tmp/` / team channel，**不**复制进 module doc。

- 模块主归属：`Weline_Server`（Fiber / Desired·Applier / Ready / Guard）
- 协作：`Weline_Framework`（FPC Coordinator / Clear→warm / FileAdapter / RouterPerf）
- 状态：`acceptance_passed`
- wave：`wls-resident-pitfalls-fix`
- Host（证据）：`https://p05113ef3.test.weline.com`
- dig（采信）：`dev/tmp/chaos-local-mix30-cold-20261011134227/`（权威 `134227`）；对照 `…133050` / 基线 `…121453`
- 探查：`dev/team/wls-resident-pitfalls-fix/meetings/domain-explore.md`（E1–E6）
- 性能设计：`dev/team/wls-resident-pitfalls-fix/meetings/性能检查-design.md`（勾选 3 plan · `survive_first`）

## 0. 范围摘要

团队修复 WLS 常驻内存业界坑清单（种子 U1–U5）。admission `memory_pressure` 前波已关；本波主税转为 **Fiber `queue_wait` / admission `timeout`**，次税 **FPC `build_lock`+after**，结构性风险含 **Ready≠暖**、**Desired/baseline**、**FileAdapter miss→rebuild**、**多 Host 共池**。

| 种子 | 主题 | 本波默认 |
|------|------|----------|
| **U1** | pin6 + 深队列 → queue_wait 主税；利用率目标错位 | **勾选** `P-util-objective-ready` |
| **U2** | Desired/baseline、Ready 假绿、暖拓扑 | **勾选** `P-orch-warm-honesty`（+ util 抬档 ops） |
| **U3** | FPC 键碎 / L1-shared / after 持锁 | **勾选轻量** `P-fpc-ledger-diag`（dogpile 大改 deferred） |
| **U4** | compact/recycle、FileAdapter miss→rebuild | **观察占位** `UC-U4-OBS` |
| **U5** | 多 Host 共 Worker 抢池 | **观察占位** `UC-U5-OBS`（本机未复现） |

**硬约束（贯穿全部 UC）**：禁拆壳；禁盲目 unpin；禁 storm/`_wb_nc` 冒充 leaf 或 HIT；验收分账。

## 1. 澄清记录

| # | 问题 | 结论 | 来源 |
|---|------|------|------|
| C1 | 本波主因是 leaf SQL 还是常驻容量/排队？ | **queue_wait + 叠建/after**；quiet 冷 hit1≈1–2.7s，非 28–46s leaf | dig 11112103/121453 · 探查 |
| C2 | 503 性质？ | `121453`：`x-wls-admission: **timeout**`（排队等满）；**非** `memory_pressure` 主因 | ledger/hdrs |
| C3 | pin 下顾问为何不抬工人？ | `AdaptiveFiberMaxActive::wantsWorkerScale` 在 pin 下 effective=ceiling → `fiber_admission_saturated` **永不点亮** | 性能 design §4 U1 |
| C4 | 目标函数默认？ | 默认 **`survive_first`**；可选书面切 `wallclock_first`（须双表护栏） | 性能 design §7 · 本规格 §2 |
| C5 | 禁盲 unpin？ | **硬禁**；降 queue 靠 ops 抬 Ready + `--align-baseline`，不靠 unpin 换 502 | 种子 · 2.0.170→171 教训 |
| C6 | Desired 写入？ | 唯一经 `CapacityScaleApplier`；抬档须 ops + **`--align-baseline`**，否则 pressure_recover 可打回 | 探查 E2 · Server 2.0.173 |
| C7 | Ready 绿 = 已暖？ | **否**；验收须 clean URL HIT / `fpc_warm_ok`；`fpc:warmed=0` ≠ 成功 | 探查 E3 · 前波 W6 |
| C8 | `_wb_nc` storm 轨？ | 仅 build-through **附录**；HIT KPI 用 **干净 URL** | ledger note · wallclock |
| C9 | 本波可施工子集？ | 默认勾选 **3** plan（下表）；U4/U5/dogpile/unpin **占位或 deferred** | 性能 design §5 |
| C10 | 施工门禁？ | **审核放行前禁施工**；架构级须 mechanism + owning_module + not_to_do | kickoff · `architecture_grade_change_only` |
| C11 | Web UI？ | 非主路径；闭环以契约 + 本机 chaos/运行时真机证据为主（计划须 `covers_use_cases`） | 模板 · 前波惯例 |

### 1.1 可选拍板（不阻塞 `ready-for-plan`；默认已冻结）

| # | 项 | 默认 | 若改须技术方案会书面 |
|---|-----|------|----------------------|
| Q1 | 目标函数 | `survive_first` | 切 `wallclock_first` |
| Q2 | 抬 Ready 对照水位 | **4**（×768M；会审可改） | 其它 N |
| Q3 | pin6 | **保留** | 解 pin + 双表 |
| Q4 | P-fpc dogpile 大改 | **本波不开**（仅分账+测法分轨） | 加勾选 |
| Q5 | U4/U5 | 观察 UC，不施工 | 生产复现再开 |

## 2. 目标函数选项（硬 · 写入计划须二选一主目标）

禁止默认「又要 pin6 零 timeout 又要 storm p95≈quiet」。

### 2.1 默认：`objective=survive_first`（本波冻结）

| 角色 | KPI |
|------|-----|
| **主** | Worker alive；502≈0；`memory_pressure` 不回归主因；软压可排队 |
| **次** | storm **分账** `queue_wait` / `timeout` 计数（允许非零）；Ready 利用率可观测 |
| **附录** | 原始 storm 墙钟 p95 |
| **允许** | ops 抬 Ready + `--align-baseline`；暖诚实；分账/测法；**保持 pin** |
| **禁止** | 盲 unpin；拆壳；关 FileAdapter 近顶 |

### 2.2 可选：`objective=wallclock_first`（须书面切换）

| 角色 | KPI |
|------|-----|
| **主** | quiet 冷；storm **queue_wait p95**（分账）；clean HIT |
| **护栏** | 502 / `memory_pressure` 双表不过 → **整波 fail** |
| **允许** | 解 pin / 开 adaptive / 缩 wait / 更多 Ready——须同波双表 |
| **禁止** | 只报墙钟不报 502；用「更短 wait→更多 timeout」假绿 |

## 3. 本波可勾选子集占位（plan_id）

> 性能席建议勾选 3；技术方案会可改勾，但须更新本表与 SESSION（PM）。未勾选项不得施工。

| plan_id | 覆盖 | 默认 | 验收钉（摘要） | 状态占位 |
|---------|------|------|----------------|----------|
| **P-util-objective-ready** | U1 + U2 抬档语义 | ✅ 勾选 | queue_wait↓；503 头分型；502≈0；requested/Desired/Ready 三元组；禁盲 unpin | `acceptance_passed` |
| **P-orch-warm-honesty** | U2 E3 | ✅ 勾选 | Clear 后 clean HIT；`fpc_warm_ok`；Ready≠HIT；`fpc:0`≠成功 | `acceptance_passed` |
| **P-fpc-ledger-diag** | U3 轻量 | ✅ 勾选（有条件） | ledger `ok=true`；`_wb_nc` 与 HIT 分轨；after 不归因 SQL；禁拆壳 | `acceptance_passed` |
| P-fpc-dogpile | U3 重 | ☐ deferred | build_lock/after 机制大改 | `placeholder` |
| P-fileadapter-near-limit | U4 | ☐ 观察 | 拒读/rebuild 分账；502 复现后再开 | `placeholder` |
| P-multi-host-pool | U5 | ☐ 观察 | 多站同实例复现后再开 | `placeholder` |
| P-unpin-adaptive | U1 激进 | ☐ 禁默认 | 仅 `wallclock_first` + 双表 | `placeholder` |

## 4. 用户故事

### US-1 利用率 / 目标函数

As a **运行 WLS 的站点运维**,  
I want **明确「存活优先」还是「墙钟优先」，并在 pin 下仍能通过 ops 抬 Ready 降低排队税**,  
so that **不会在假「利用率已满」或盲 unpin 之间二选一翻车**.

### US-2 编排诚实性

As a **容量与暖机操作者**,  
I want **baseline/Desired/Ready 与 Clear→warm 可观测且可对齐**,  
so that **Ready 绿或 CLI「已暖」不会掩盖空 FPC 池与 build_lock dogpile**.

### US-3 FPC 分账与测法诚实

As a **性能验收操作者**,  
I want **storm 墙钟按 queue_wait/action/template/after/fpc 分账，且 HIT 用干净 URL**,  
so that **不会把诊断打穿或叠建税当成 leaf SQL / HIT 失败**.

### US-4 安全边界（非目标护栏）

As a **框架维护者**,  
I want **修复路径禁止拆 chrome/必装部件、禁止盲 unpin、禁止关近顶拒读换「少 miss」**,  
so that **优化不会用 OOM→502 或掏空壳换分**.

## 5. EARS

### 5.1 目标函数与利用率（U1）

1. **WHEN** 本波进入计划冻结 **THEN** 系统规格/计划 SHALL 显式声明主目标为 `survive_first` 或 `wallclock_first`（默认前者）。  
2. **WHILE** `objective=survive_first` ∧ `pin_max_active=true` **THEN** 修复手段 SHALL **优先** ops 抬 Ready + `--align-baseline` + 暖诚实 + 分账可观测，**禁止**默认解 pin。  
3. **WHEN** Fiber 准入排队触发 **THEN** 响应/探针 SHALL 可区分 `X-WLS-Admission: timeout` vs `memory_pressure` vs `queue_full`。  
4. **WHEN** 报告利用率 **THEN** SHALL 使用可观测量（如 `active/(Ready×max_active)` + waiters + queue_wait 分账），**禁止**仅用 Ready 计数宣称「已优化」。  
5. **IF** pin 导致 `fiber_admission_saturated` 永不点亮 **THEN** 文档/规格 SHALL 诚实记载「pin=须手动/ops 抬档」，不得假装顾问会自动加 Worker。

### 5.2 编排 / Desired / Ready / 暖（U2）

6. **WHEN** ops 抬 runtime Desired **THEN** 若需对抗 `pressure_recover` 打回 **THEN** 系统 SHALL 支持/要求 `--align-baseline`（或等价）同步 baseline/`worker_count_requested`/startupExplicit。  
7. **WHEN** 任何路径 mutate Desired **THEN** SHALL 经 `CapacityScaleApplier`（或冻结会批准的显式 ops 并记账），禁止手改 Desired 文件。  
8. **WHEN** Worker 进入 READY **THEN** 验收 SHALL **不**将 READY 等同于店面 FPC HIT / 袋已暖。  
9. **WHEN** Clear→warm 编排结束且调用方宣称「暖后混发」**THEN** 系统 SHALL 暴露可观测成功条件（如 `fpc_warm_ok` / `fpc:warmed>0` 或等价）；`fpc:warmed=0` SHALL 不得标成功。  
10. **WHEN** 验收 HIT **THEN** SHALL 使用干净 URL 轨（无 `_wb_nc` 打穿），另附 storm build-through 附录。

### 5.3 FPC / 分账（U3）

11. **WHEN** 跑 storm 混发验收 **THEN** 系统/脚本 SHALL 产出分账列：`queue_wait` · `action` · `template` · `after` · `fpc`（ledger `ok=true`）。  
12. **WHEN** 优化宣称压低墙钟 **THEN** 报告 SHALL 点名压的是哪一分账列，**禁止**未分账 storm p95 冒充 leaf SQL。  
13. **WHILE** 本波未勾选 dogpile 大改 **THEN** build_lock/after 仅作回归门禁与观测，不得以拆壳降 after。  
14. **IF** 诊断 query（含 `_wb_nc`）进入 FPC/测法键 **THEN** HIT KPI SHALL 分轨，禁止将该轨 MISS/disabled 计为公网 HIT 失败。

### 5.4 内存 / 多 Host（U4/U5 · 观察）

15. **WHILE** U4 未勾选施工 **THEN** FileAdapter 近顶拒读护栏 SHALL 保持；不得为少 miss 关闭近顶。  
16. **WHILE** U5 本机未复现 **THEN** 多 Host 共池仅作观察/文档占位；不得假定 Ready 按站隔离。

### 5.5 全局禁令

17. **IF** 任何优化建议要求拆除 header/footer/必装 widget/featured 货架 **THEN** 系统/席位 SHALL **拒绝**（`UC-NO-SHELL`）。  
18. **IF** 任何优化在未切 `wallclock_first` 且未过 502/`memory_pressure` 双表时解 pin 或大幅抬 `max_active` **THEN** 验收 SHALL **FAIL**（`UC-NO-BLIND-UNPIN`）。

## 6. 用例（UC）

> 闭环硬规则：计划 `acceptance[].covers_use_cases` 须覆盖下列全部 UC-*；收口前全部 `passed`+真机/契约 evidence。  
> Web UI 非主路径；主闭环 = 本机 chaos/运行时 + 契约 UT。

### UC-OBJ-01 · 目标函数显式冻结

| 字段 | 内容 |
|------|------|
| id | `UC-OBJ-01` |
| 名称 | 计划冻结主目标 survive_first 或 wallclock_first |
| 角色 | 项目经理 / 架构师 / 性能 |
| 前置 | 本规格 `ready-for-plan` |
| 主成功步骤 | 1) 技术方案会书面钉 `objective` 2) 写入计划与 contracts 3) 默认 survive_first |
| 备选/异常 | 切 wallclock_first → 同步双表护栏与解 pin 条件 |
| 期望结果 | 计划无「双主目标」含糊句 |
| 映射 acceptance | `ACC-OBJ-FREEZE` |

### UC-UTIL-01 · 抬 Ready + align 降 queue_wait（P-util）

| 字段 | 内容 |
|------|------|
| id | `UC-UTIL-01` |
| 名称 | ops 抬 Ready（建议 4）并对齐 baseline 后排队税下降 |
| 角色 | 运维 / 测试 / 性能 |
| 前置 | Ready=Desired=2×768M；pin6；conc=30 可复跑 dig 脚本 |
| 主成功步骤 | 1) `server:scale --workers=N --align-baseline`（N 会审定，默认 4）2) 核三元组 requested/Desired/Ready 3) 复跑 storm 4) 读 ledger `queue_wait` 与 503 头分型 |
| 备选/异常 | 无 align → Desired 被 pressure_recover 打回 → **FAIL**；盲 unpin → **FAIL** |
| 期望结果 | `timeout`/queue_wait 相对 `121453` 基线下降；502≈0；`memory_pressure` 不回归主因；**pin 仍为 true**（默认目标） |
| 映射 acceptance | `ACC-UTIL-READY-ALIGN` |

### UC-ORCH-01 · Desired 唯一事务 / baseline 诚实

| 字段 | 内容 |
|------|------|
| id | `UC-ORCH-01` |
| 名称 | Desired 经 Applier；align 后 baseline 不被 recover 打回 |
| 角色 | 后端契约 / 运维 |
| 前置 | 可触发 scale 与 pressure_recover 路径（契约或受控运行时） |
| 主成功步骤 | 1) 经批准路径写 Desired 2) align baseline 3) 触发 recover 条件 4) 断言 requested/baseline 不被静默打回错误水位 |
| 备选/异常 | 手改 Desired 文件 → 禁止/FAIL |
| 期望结果 | Applier 为唯一 mutate；三元组可观测 |
| 映射 acceptance | `ACC-ORCH-APPLIER-ALIGN` |

### UC-WARM-01 · Clear→warm 诚实（P-orch-warm）

| 字段 | 内容 |
|------|------|
| id | `UC-WARM-01` |
| 名称 | Clear 后暖成功可观测；禁止 Ready/`fpc:0` 假绿 |
| 角色 | 运维 / 测试 |
| 前置 | 可 `cache:clear` + 正式 warm 编排 |
| 主成功步骤 | 1) clear 2) warm 3) 断言 `fpc_warm_ok`/`fpc:warmed>0`（或等价）4) 干净 URL 探 HIT 5) Ready 计数单独记录不作为 HIT 证据 |
| 备选/异常 | `fpc:warmed=0` 却标暖成功 → **FAIL** |
| 期望结果 | clean HIT；暖失败 fail-closed 阻断「暖后混发」宣称 |
| 映射 acceptance | `ACC-WARM-HONESTY` |

### UC-FPC-01 · 诊断轨与 HIT 轨分账（P-fpc-ledger）

| 字段 | 内容 |
|------|------|
| id | `UC-FPC-01` |
| 名称 | `_wb_nc` storm 轨不计入 HIT KPI |
| 角色 | 性能 / 测试 |
| 前置 | 同脚本 storm + clean 附录 |
| 主成功步骤 | 1) storm 带 `_wb_nc`+tpl_perf 2) 产出 ledger 3) clean URL HIT 附录 4) 报告分轨 |
| 备选/异常 | 用 storm MISS/disabled 宣称 HIT 失败 → **FAIL** |
| 期望结果 | 两轨分离；公网键路径 HIT 可证 |
| 映射 acceptance | `ACC-FPC-DIAG-SPLIT` |

### UC-LEDGER-01 · 风暴分账契约

| 字段 | 内容 |
|------|------|
| id | `UC-LEDGER-01` |
| 名称 | storm 必出 queue_wait/action/template/after/fpc |
| 角色 | 性能 / 测试 |
| 前置 | dig 脚本 / ledger 工具可用 |
| 主成功步骤 | 1) 跑 conc=30 2) 读 `ledger.txt`/`ledger.json` 3) `required_columns` 齐全且 `ok=true` |
| 备选/异常 | 缺列 → FAIL |
| 期望结果 | 优化报告能点名列；附录可保留原始 p95 |
| 映射 acceptance | `ACC-LEDGER-SPLIT` |

### UC-NO-SHELL · 禁拆壳

| 字段 | 内容 |
|------|------|
| id | `UC-NO-SHELL` |
| 名称 | 优化 diff 不含拆 chrome/必装 |
| 角色 | 主题/性能复审 / 测试 |
| 前置 | 有施工 diff |
| 主成功步骤 | 1) 审 diff 与计划 2) 确认无拆除 header/footer/必装 widget/featured 货架作为手段 |
| 备选/异常 | 出现拆壳药方 → escalate + FAIL |
| 期望结果 | 壳完整 |
| 映射 acceptance | `ACC-NO-SHELL-STRIP` |

### UC-NO-BLIND-UNPIN · 禁盲目 unpin

| 字段 | 内容 |
|------|------|
| id | `UC-NO-BLIND-UNPIN` |
| 名称 | 默认目标下 pin 保持；解 pin 须书面+双表 |
| 角色 | 性能 / 架构 / 测试 |
| 前置 | env `pin_max_active` 基线已知 |
| 主成功步骤 | 1) 对照目标函数 2) 若 unpin：须 `wallclock_first` + 502/`memory_pressure` 双表同过 3) 否则 pin 仍 true |
| 备选/异常 | 静默 unpin → FAIL |
| 期望结果 | 无盲 unpin |
| 映射 acceptance | `ACC-NO-BLIND-UNPIN` |

### UC-HOLD-QUIET · 安静窗冷 hold

| 字段 | 内容 |
|------|------|
| id | `UC-HOLD-QUIET` |
| 名称 | quiet 串行冷 hit1 不回退 |
| 角色 | 性能 / 测试 |
| 前置 | 同批 URL 可串行 |
| 主成功步骤 | 1) 风暴后或独立 quiet 2) 各 URL 墙钟 3) max≤2.5s 且相对 dig 不显著回退 |
| 备选/异常 | 用 storm 数字冒充 quiet → FAIL |
| 期望结果 | quiet HOLD |
| 映射 acceptance | `ACC-QUIET-HOLD` |

### UC-U4-OBS · FileAdapter/compact 观察占位

| 字段 | 内容 |
|------|------|
| id | `UC-U4-OBS` |
| 名称 | U4 本波观察：502/拒读不主修 |
| 角色 | 性能 |
| 前置 | dig 可复跑 |
| 主成功步骤 | 1) 记录 502 计数与近顶相关日志（若有）2) 确认近顶拒读未关闭 3) 若连续复现拒读风暴 → escalate 开 P-fileadapter |
| 备选/异常 | 为少 miss 关拒读 → FAIL |
| 期望结果 | 观察记录进收口；默认不施工 |
| 映射 acceptance | `ACC-U4-OBSERVE` |

### UC-U5-OBS · 多 Host 共池观察占位

| 字段 | 内容 |
|------|------|
| id | `UC-U5-OBS` |
| 名称 | U5 本机未复现；文档/观察 |
| 角色 | 架构 / 运维 |
| 前置 | 单 Host dig |
| 主成功步骤 | 1) 确认本波 dig 单 Host 2) 记录共池架构风险（serving_manifest）3) 无生产复现不开施工 |
| 备选/异常 | 假定 Ready 按站隔离 → 文档纠偏 |
| 期望结果 | 占位闭环（观察 passed）；施工子项仅复现后开 |
| 映射 acceptance | `ACC-U5-OBSERVE` |

## 7. 非目标（硬）

- 重开 admission `memory_pressure` 为主 KPI（前波已关）。  
- 盲 unpin / 无双表抬 `max_active` / 关 FileAdapter 近顶。  
- 拆 chrome / 必装 widget / featured 货架 / 全 q 预热。  
- 用 storm/`_wb_nc` 墙钟或 MISS 冒充 leaf SQL 或 HIT。  
- 手改 Desired 文件；绕过 `CapacityScaleApplier`。  
- Ready 绿冒充已暖；`fpc:warmed=0` 标暖成功。  
- 在 `WlsRuntime`/编排层硬编码业务路径/slug（`runtime_orchestrator_no_business_hardcode`）。  
- 本波默认施工 U4 阈值大改、U5 拓扑大改、FPC dogpile 大改（除非会审加勾）。  
- 审核放行前改业务码；本席改 SESSION。

## 8. 框架映射（只读锚点 · 非实现处方）

| 域 | 锚点（磁盘） | 本波关系 |
|----|--------------|----------|
| Fiber 准入 / pin / adaptive | `AdaptiveFiberMaxActive`；`wlsAwaitFiberAdmissionSlot`；`FiberAdmissionQueueContractTest`；`app/etc/env.php` `wls.fiber.*` | U1 · P-util |
| Desired / Applier / align | `CapacityScaleApplier`；`CapacityAdvisor`；`Scale` CLI `--align-baseline`；`ServiceOrchestrator`；G8 baseline vs runtime | U2 · UC-ORCH/UTIL |
| Ready / homepage proof | `WorkerReadinessState`；`WlsRuntime` homepage_fpc warm/hot；status_report | U2 · UC-WARM |
| Clear→warm | Framework `warmStorefrontFpcAfterClear`；模块 `cache.warmer.*`；chrome 子进程 reseed | U2 · UC-WARM |
| FPC 单飞 / after | `FullPageCacheCoordinator`；`Router\Core` `fpc=build_lock\|wait_miss\|…`；锁键含 variant | U3 · UC-FPC/LEDGER |
| FileAdapter 近顶 | `Cache\Adapter\FileAdapter`；`FileAdapterNearLimitContractTest` | U4 观察 |
| Guard compact | `WorkerResponseMemoryGuard::compactForNewRequestHeadroom` | U4 观察 |
| 多 Host / 主机协调 | serving_manifest 共池；`HostMemoryPressureCoordinator`；`WLS实例隔离机制.md` | U5 观察 |
| 边缘 | `ManagedNginxConfigWriter` reuseport 注释 | U5 观察 |
| 前波继承 | `storefront-mix30-capacity-warm`；`storefront-mix30-wallclock`；`wls-async-industry-align` | 不重开已关 KPI |

**归属原则**：Server 主改 Fiber/编排/Ready/ops；Framework 协作 FPC/Clear/FileAdapter；Theme 仅袋复用协作且 **禁拆壳**；业务路径不进 Runtime。

## 9. 验收意图（计划期须落地 acceptance id）

| acceptance id | type 意图 | covers |
|---------------|-----------|--------|
| `ACC-OBJ-FREEZE` | plan/contracts 检查 | UC-OBJ-01 |
| `ACC-UTIL-READY-ALIGN` | runtime/chaos | UC-UTIL-01 |
| `ACC-ORCH-APPLIER-ALIGN` | contract/runtime | UC-ORCH-01 |
| `ACC-WARM-HONESTY` | runtime | UC-WARM-01 |
| `ACC-FPC-DIAG-SPLIT` | chaos + clean HIT | UC-FPC-01 |
| `ACC-LEDGER-SPLIT` | ledger 契约 | UC-LEDGER-01 |
| `ACC-NO-SHELL-STRIP` | review/diff | UC-NO-SHELL |
| `ACC-NO-BLIND-UNPIN` | env/config 检查 | UC-NO-BLIND-UNPIN |
| `ACC-QUIET-HOLD` | quiet 串行 | UC-HOLD-QUIET |
| `ACC-U4-OBSERVE` | 观察记录 | UC-U4-OBS |
| `ACC-U5-OBSERVE` | 观察记录 | UC-U5-OBS |

`fe_be_scope`：backend/runtime（Server+Framework）；frontend UI **N/A**（除非会审加面板）。

## 10. 就绪检查

- [x] `status` = `acceptance_passed`（原 `ready-for-plan` 已闭环）
- [x] ≥1 用户故事 + 每故事可映射 EARS
- [x] ≥1 用例含主成功路径；含异常/禁令 UC
- [x] 每条 UC 已填映射 acceptance id
- [x] 非目标明确（禁拆壳 / 禁盲 unpin / 禁 storm 冒充 leaf）
- [x] 目标函数选项 + 本波可勾选子集占位已写
- [x] 分账验收口径已写
- [x] 框架映射已写（what/锚点，非 how 补丁步骤）
- [x] feature 验收意图含 runtime/chaos/contract（计划期 `covers_use_cases` 对齐）
- [x] 未把具体类补丁步骤写成唯一实现处方
- [x] 收口：issuer 三 plan+main pass · 测试 28/131 · 文档指针齐（见 §12）

## 11. 下一步

1. ~~澄清 / 技术方案会 / 审核放行 / 施工~~（已完成）。  
2. ~~issuer=`性能检查工程师` 复跑~~（`channel/issuer-perf.md` · dig`134227`）。  
3. ~~测试契约签收~~（`channel/test-acceptance.md` · 28/131）。  
4. **项目经理**汇审（本席不改 SESSION）；文档复审见 `meetings/文档-review.md`。

## 12. 验收收口指针（耐久摘要 · 禁 dig 全文）

| 项 | 指针 |
|----|------|
| status | `acceptance_passed` |
| issuer_acceptance | 三 plan+main `pass`（`channel/issuer-perf.md`） |
| 采信 dig | `dev/tmp/chaos-local-mix30-cold-20261011134227/`（权威 `134227`；禁全文） |
| 对照 dig | 施工 Server `…133050` · 基线 `…121453` · Framework `run-20261011132900` |
| 测试契约 | **28 tests / 131 assertions**（`channel/test-acceptance.md`） |
| Survive 摘要 | Ready 4/4/4 · pin6 · 503×0 · 502×0 · quiet≤1.57s · `fpc:warmed=8` · clean HIT×4 |
| Server | `2.0.175` · `doc/开发日志.md`（Scale Fiber·pin Known gap） |
| Framework | `2.5.343` · `Framework/doc/开发日志.md`（DiagTrack · Clear `fpc_warm_ok`） |
| 文档复审 | `dev/team/wls-resident-pitfalls-fix/channel/docs-acceptance.md` · `meetings/文档-review.md` |

**当前 `status: acceptance_passed`。**  
路径：`app/code/Weline/Server/doc/开发/spec/wls-resident-pitfalls-fix.md`
