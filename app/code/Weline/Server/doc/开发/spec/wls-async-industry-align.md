---
status: ready-for-plan
work_kind: feature
feature_slug: wls-async-industry-align
module: Weline_Server
updated: 2026-10-11
session_path: ../../../../../../../dev/session/wls-async-industry-align.md
team_path: ../../../../../../../dev/team/wls-async-industry-align/
plan_ref: ~/.cursor/plans/wls异步对齐三波_264161f0.plan.md
---

# spec：WLS 对齐业界异步长驻模型（三波）

- 模块：`Weline_Server`（波 1/2 主归属；波 3 Phrase/MS 协作 `Weline_Framework` / I18n）
- 状态：`ready-for-plan`
- 关联：计划 `wls异步对齐三波`；`dev/team/wls-async-industry-align/contracts.md`；`doc/Dispatcher分流架构设计.md`（REQ-SERVER-0020）；既有 Fiber 准入 / planned recycle / Guard 循环层 headroom

## 0. 范围摘要

业界异步长驻完整模型分三波落地：

| 波次 | 内容 | 本规格可执行深度 |
|------|------|------------------|
| **波 1** | 内存感知准入 + 空闲退役 READY-before-DRAIN + `status_report` 标量 | **UC 可施工 / 可验收** |
| **波 2** | 受控自动伸缩（顾问 intent → Applier；汇合 memory_pressure；双拓扑；G 门闩） | 远期 UC + G 映射（**不施工**） |
| **波 3** | Phrase/大块 MS 分片外置（禁整 locale 帧） | 远期 UC + G 映射（**不施工**） |

## 1. 澄清记录

| # | 问题 | 用户/计划裁定 |
|---|------|---------------|
| C1 | 第一波是否上全自动扩容？ | **否**。波 1 只做入口背压与退役交接；不改 Desired / worker_count |
| C2 | 近顶时拒接策略？ | 余量不足先 `compactForNewRequestHeadroom`；仍不足则排队等待或 `X-WLS-Admission: memory_pressure` 503；**禁止**余量不足再 `start` 新请求 Fiber |
| C3 | Planned recycle 顺序？ | surge Worker **READY 后**才 DRAIN 旧槽；压力路径不得「先关监听再同槽复活」 |
| C4 | 扩容基线语义（波 2）？ | baseline = 用户配置 Worker 数；自动伸缩仅在 `[baseline, max_workers]`；卸压缩**回 baseline**（非任意地板） |
| C5 | 三批门槛？ | `worker_three_batch_min_count` 默认 7→**4**（N≥4 走三批）；与排水/recycle 共用 `WorkerRestartBatchPlanner`（波 2） |
| C6 | 默认拓扑？ | **保持 auto→Direct 优先**；禁止为伸缩把默认改回 Dispatcher；波 2 须双拓扑验收 |
| C7 | 整袋进 MS？ | **禁止**；波 3 仅分片/单词级；历史 `frame_too_large` |
| C8 | 波 1 与既有 Guard 关系？ | 循环层已有 headroom/shell trim；准入门闩复用同一绝对余量语义（默认对齐 `LOOP_HEADROOM_SHED_BYTES`=64MiB），**新增**「拒接/排队」而非只 compact |
| C9 | G1–G16？ | 规格必须逐条映射 UC 或 `na`+理由；波 1 至少落地 G11；其余可波 1=`na` 但须标明波 2/3 归属 |

只读探测锚点（澄清时磁盘现状，非实现处方）：

- `WorkerResponseMemoryGuard`：`LOOP_HEADROOM_SHED_BYTES`、`compactForNewRequestHeadroom`、`capacityStatusScalars`（已有 compact/drain/pressure；**尚无** `admission_memory_rejects` / `absolute_headroom_bytes`）
- `FiberAdmissionQueueContractTest`：队列/`X-WLS-Admission` 已契约；**未绑**内存余量；尚无 `memory_pressure` reason 断言
- `ServiceOrchestrator::requestPlannedWorkerRecycle` / `advancePlannedWorkerRecycleAfterReady`：surge READY→DRAIN 已存在；须契约锁防回归
- `memory_pressure_scale_down*`：Orchestrator 已能改 Desired——**波 2 必须汇合**，波 1 禁止再开平行 Desired 事务
- `WorkerRestartBatchPlanner::plan`：默认 `threeBatchMinCount=7`，入参 `<4` 钳回 7；波 2 改 4 须全路径回归

## 2. 用户故事

### US-W1-A（波 1 · 准入）

作为运行 WLS Worker 的站点运维/框架维护者，我希望单进程接近内存顶时少接新请求（排队或 503），而不是继续叠 Fiber，以便进程保持存活、避免 SHUTDOWN_FATAL，并为后续自动扩容提供可信压力标量。

### US-W1-B（波 1 · 退役交接）

作为运行 WLS 的运维，我希望空闲退役（ratchet / max_requests / planned）在新 Worker READY 并纳入流量面之后再 DRAIN 旧槽，以便退役期间不出现「无 accept / 黑洞」。

### US-W2（波 2 · 远期）

作为运维，我希望在 Worker 池繁忙且整机仍有余量时 Master 在硬上限内自动加副本，卸压后缩回我配置的 baseline；Dispatcher 与 Direct 拓扑都正确交接，且不与压力缩容互抢 Desired。

### US-W3（波 3 · 远期）

作为框架维护者，我希望 Phrase/大块权威状态以有界分片落在 MS，Worker 只留小 L1，以便外置失败不会整袋打爆进程。

## 3. EARS

### 3.1 波 1（可执行）

1. **WHEN** 新请求到达 Fiber 准入决策点，且 Worker 绝对内存余量（limit−zend_real）低于配置阈值（默认对齐 Guard 循环层 64MiB 语义）**THEN** 系统 SHALL 先尝试 compact 换取 headroom，**禁止**在仍不足时 `start` 新请求 Fiber。
2. **WHEN** compact 后余量仍不足且准入队列尚有空位与等待预算 **THEN** 系统 SHALL 将请求置于 admission 等待（既有排队心智），等待期内周期性复检余量；余量恢复则放行。
3. **WHEN** 余量持续不足且排队超时或队列满 **THEN** 系统 SHALL 返回 503，响应头 `X-WLS-Admission: memory_pressure`（及既有 Retry-After 合同），并递增可观测计数 `admission_memory_rejects`。
4. **WHEN** Worker 周期/即时 `status_report` **THEN** 载荷 SHALL 含 `admission_memory_rejects`、`absolute_headroom_bytes`，并保留既有 compact/drain/pressure 标量供 Master/顾问消费。
5. **WHEN** Orchestrator 执行 planned recycle（含 zend_mm_ratchet / max_requests / 显式 planned）且拓扑支持 new-first surge **THEN** 系统 SHALL 在 surge Worker 进入 READY（并完成拓扑侧流量纳入）之后才对旧槽发 DRAIN。
6. **IF** 压力/退役路径试图「先关监听再同槽复活」**THEN** 系统 SHALL 拒绝该路径（契约/运行时 fail-closed），不得作为常规回收。
7. **WHILE** 内存门闩生效 **THEN** 系统 SHALL 不在请求路径把 `exit`/自杀当作常规回收手段。

### 3.2 波 2（远期 · 规格冻结用）

8. **WHEN** 池级繁忙 ∧ 整机有余量 ∧ runtime Desired < max_workers ∧ 冷却结束 ∧ 无进行中分批交接 **THEN** Master SHALL 产出可执行 scale-up intent 并小步提高 runtime Desired（不写用户 baseline）。
9. **WHEN** 整机亦紧 **THEN** intent SHALL 为 `noop`（reason=`host_pressure`），由波 1 准入消化，禁止为「忙」而扩。
10. **WHEN** runtime Desired > baseline 且低压/低 active 持续 T_down ∧ 无深排队/拒接 ∧ 无分批交接 **THEN** 系统 SHALL 分批缩回 baseline（复用 `WorkerRestartBatchPlanner`，默认三批门槛=4）。
11. **WHEN** 生效拓扑为 Dispatcher **THEN** 每批摘/加路由 SHALL 经 `SET_ROUTE_TABLE` ACK 屏障；**WHEN** Direct **THEN** 走 Desired + planned-recycle/new-first，不经 Dispatcher 路由表。
12. **IF** sidecar/DB 预算不足以支撑 `max_active × 目标 Worker 数` **THEN** scale-up intent SHALL `noop`（`sidecar_budget` / `db_budget`）。

### 3.3 波 3（远期 · 规格冻结用）

13. **WHEN** Phrase/大块写入 MS **THEN** 系统 SHALL 仅使用模块 CSV 快照/单词级/有界 chunk，帧长遵守 SessionProtocol；**禁止**整 locale `generated/language/{locale}.php` 进 MS。
14. **WHEN** 外置 miss 或近顶 **THEN** Worker SHALL 只卸 L1 + 拒大帧，禁止 OOM 重试整袋。

## 4. 用例（UC）

> 波 1 UC 为对齐冻结后可施工项；波 2/3 UC 仅冻结验收意图，**本波不实现**。  
> 本功能以契约/chaos/运行时为主；Web UI 非主路径。闭环验收以 UT/契约 + 本机 chaos/运行时真机证据为准（`requirement_use_case_closed_loop_acceptance`：计划 `covers_use_cases` 须覆盖全部下列 UC-*）。

### 4.1 波 1 — 可执行

#### UC-W1-01 · 近顶拒接新 Fiber（内存门闩）

| 字段 | 内容 |
|------|------|
| id | `UC-W1-01` |
| 名称 | 绝对余量不足时禁止 start 新请求 Fiber |
| 角色 | Worker 运行时 / 测试席 |
| 前置 | Worker 内存预算已知（如 chaos 256M）；可构造 absolute headroom < 阈值 |
| 主成功步骤 | 1) 将进程置于低余量 2) 注入新请求至准入点 3) 观察先 compact 4) compact 后仍低则不增加 active 请求 Fiber |
| 备选/异常 | compact 后余量恢复 → 正常 start Fiber |
| 期望结果 | 无「余量不足仍 start」；无请求路径自杀式 exit |
| 映射 acceptance | `ACC-W1-ADMIT-MEMORY-GATE` |

#### UC-W1-02 · 排队等待与 memory_pressure 503

| 字段 | 内容 |
|------|------|
| id | `UC-W1-02` |
| 名称 | 排队耗尽后 503 + Admission 头 |
| 角色 | HTTP 客户端 / 契约测试 |
| 前置 | 内存门闩触发；队列深度/等待预算按既有 fiber admission 配置 |
| 主成功步骤 | 1) 门闩触发 2) 队列有空则等待并复检 3) 超时或满队 → 503 |
| 备选/异常 | 等待期内余量恢复 → 200/正常业务响应（非 503） |
| 期望结果 | 响应含 `X-WLS-Admission: memory_pressure`；`admission_memory_rejects` 递增 |
| 映射 acceptance | `ACC-W1-ADMIT-503-HEADER` |

#### UC-W1-03 · status_report 压力/准入标量

| 字段 | 内容 |
|------|------|
| id | `UC-W1-03` |
| 名称 | status_report 暴露准入与 headroom 标量 |
| 角色 | Master / 契约测试 |
| 前置 | Worker 已 READY 并能发 status_report |
| 主成功步骤 | 1) 触发至少一次内存门闩决策（含 reject 或放行） 2) 读取 status_report / capacity scalars |
| 备选/异常 | 无 |
| 期望结果 | 存在 `admission_memory_rejects`、`absolute_headroom_bytes`；既有 `compact_*` / `drain_request_count` / `last_pressure` 不回退 |
| 映射 acceptance | `ACC-W1-STATUS-SCALARS` |

#### UC-W1-04 · Planned recycle READY-before-DRAIN

| 字段 | 内容 |
|------|------|
| id | `UC-W1-04` |
| 名称 | surge READY 后才 DRAIN 旧槽 |
| 角色 | Orchestrator / 契约测试 |
| 前置 | 可触发 planned recycle；Direct new-first 或等价可观测路径 |
| 主成功步骤 | 1) 请求 planned recycle 2) 断言 surge 启动且进入 READY 3) 仅此后旧槽收到 DRAIN |
| 备选/异常 | surge 启动失败 → 不得对旧槽盲 DRAIN 致无 accept；须可观测失败/重试合同（架构钉细节） |
| 期望结果 | 时序：READY(surge) ≺ DRAIN(old)；无「先关监听再同槽复活」 |
| 映射 acceptance | `ACC-W1-RECYCLE-READY-DRAIN` |

#### UC-W1-05 · 本机 chaos：alive + 允许 admission 503

| 字段 | 内容 |
|------|------|
| id | `UC-W1-05` |
| 名称 | CHAOS_MEM=256M 并发冷切存活 |
| 角色 | 性能检查 / 测试席 |
| 前置 | 本机可跑既有 chaos/冷切路径；Worker memory=256M |
| 主成功步骤 | 1) 启动受限内存 Worker 2) 并发冷切加压 3) 采集存活与 Admission 响应 |
| 备选/异常 | 出现 admission 503 → **允许**；出现 SHUTDOWN_FATAL → **失败** |
| 期望结果 | 进程 alive；允许 `memory_pressure` 503；峰顶显著低于「无门闩硬顶」基线（性能席给对比口径） |
| 映射 acceptance | `ACC-W1-CHAOS-256M` |

### 4.2 波 2 — 远期（冻结意图，不施工）

| id | 名称 | 映射 acceptance | 要点 |
|----|------|-----------------|------|
| `UC-W2-01` | 忙+主机空闲 → scale-up | `ACC-W2-SCALE-UP` | baseline 例=2；小步 +N；不写 baseline 字段 |
| `UC-W2-02` | 卸压 → 缩回 baseline | `ACC-W2-SCALE-DOWN-BASELINE` | 回到用户配置数；快扩慢缩+冷却 |
| `UC-W2-03` | 忙+主机紧 → noop | `ACC-W2-HOST-PRESSURE-NOOP` | reason=`host_pressure` |
| `UC-W2-04` | Dispatcher 批摘/加路由 | `ACC-W2-TOPOLOGY-DISPATCHER` | `SET_ROUTE_TABLE` 屏障；G1 |
| `UC-W2-05` | Direct 扩缩交接 | `ACC-W2-TOPOLOGY-DIRECT` | new-first / upstream；G2–G3 |
| `UC-W2-06` | sidecar/DB 预算拒扩 | `ACC-W2-BUDGET-NOOP` | G6–G7 |
| `UC-W2-07` | baseline vs runtime Desired 分字段 | `ACC-W2-DESIRED-SPLIT` | G8；重启可恢复 |
| `UC-W2-08` | planner N=1/2/3/6/9 与门槛=4 | `ACC-W2-BATCH-PLANNER` | G14 |
| `UC-W2-09` | 维护/reload 互斥与多实例绑定 | `ACC-W2-OPS-MUTEX` | G9–G10、G16 |
| `UC-W2-10` | 面板展示 intent/baseline/Desired | `ACC-W2-PANEL` | G12 |
| `UC-W2-11` | 压力缩与顾问单一 Desired 事务 | `ACC-W2-PRESSURE-MERGE` | G13 |
| `UC-W2-12` | H2/H3/SSE 排水窗口 | `ACC-W2-DRAIN-PROTO` | G4–G5、G15 |

### 4.3 波 3 — 远期（冻结意图，不施工）

| id | 名称 | 映射 acceptance | 要点 |
|----|------|-----------------|------|
| `UC-W3-01` | 有界 chunk 写入 MS | `ACC-W3-MS-CHUNK` | 禁整 locale 帧；SessionProtocol 上限 |
| `UC-W3-02` | miss/近顶只卸 L1 | `ACC-W3-L1-SHED` | 禁止 OOM 重试整袋；依赖波 1 admission 已 PASS |

## 5. 波 1 非目标（硬）

- **不改** runtime/`env` Desired worker_count，不做自动扩容/缩容（波 2）。
- **不**整袋写 Shared Memory / 整 locale 进 MS（波 3）。
- **不**把默认拓扑改回 Dispatcher；不把派遣器当推荐默认。
- **不**在请求路径用 `exit`/自杀当常规回收。
- **不**另写第二套分批算法；不改 `WorkerRestartBatchPlanner` 默认三批门槛（留给波 2 + G14）。
- **不**汇合/重写 Orchestrator `memory_pressure_scale_down` Desired 事务（波 2 / G13）；波 1 仅锁 recycle 交接与准入。
- **不**改 Nginx upstream 自动刷新逻辑、H3 drain-route、面板 capacity 曲线（波 2）。

## 6. G1–G16 全表映射

| ID | 主题 | 归属波 | UC / na | 说明 |
|----|------|--------|---------|------|
| G1 | Dispatcher 拓扑 | **wave2** | `UC-W2-04` | 波 1：`na`——本波不改路由表/不施工伸缩；默认仍 Direct 优先 |
| G2 | Nginx / Gateway 边缘 | **wave2** | `UC-W2-05` | 波 1：`na`——不改 Desired 则无需刷新 upstream |
| G3 | listener reuseport/shared_fd/worker_ports | **wave2** | `UC-W2-05` | 波 1：planned recycle 复用既有 new-first（`UC-W1-04` 锁交接）；扩缩 listener 合同属波 2 |
| G4 | H2 GOAWAY / 排水地板 | **wave2** | `UC-W2-12` | 波 1：`na`——不缩短排水；既有 ratchet≥120s 保持；快缩对齐属波 2 |
| G5 | HTTP/3 / QUIC drain-route | **wave2** | `UC-W2-12` | 波 1：`na`——本波无缩容批；无 H3 变更 |
| G6 | Session / Memory sidecar 预算 | **wave2** | `UC-W2-06` | 波 1：`na`——不增 Worker 数；sidecar 仍单例 |
| G7 | DB 连接预算 | **wave2** | `UC-W2-06` | 波 1：`na`——不改池×Worker 乘积 |
| G8 | Desired 持久化分字段 | **wave2** | `UC-W2-07` | 波 1：`na`——禁止改 Desired |
| G9 | 维护模式 / serving manifest | **wave2** | `UC-W2-09` | 波 1：`na`——无自动灌池 |
| G10 | FileWatcher / code reload 互斥 | **wave2** | `UC-W2-09` | 波 1：`na`——无 Applier；recycle 与 reload 既有互斥保持 |
| G11 | Fiber 准入 × Worker 数 | **wave1** | `UC-W1-01`,`UC-W1-02`,`UC-W1-03` | **本波落地**：单进程内存门闩；池级「忙」聚合留给波 2 顾问 |
| G12 | WLS 面板 / capacity 曲线 | **wave2** | `UC-W2-10` | 波 1：`na`——仅先钉 status_report 标量（`UC-W1-03`）供后续面板 |
| G13 | memory_pressure 双轨汇合 | **wave2** | `UC-W2-11` | 波 1：`na`——禁止本波再开平行 Desired 写入 |
| G14 | 三批默认 7→4 | **wave2** | `UC-W2-08` | 波 1：`na`——不改 planner 默认；避免滚动重载爆炸半径 |
| G15 | SSE / 长连接排水 | **wave2** | `UC-W2-12` | 波 1：`na`——准入不把 SSE 当短请求硬掐；缩容排水窗口属波 2 |
| G16 | 多实例 / 多项目绑定 | **wave2** | `UC-W2-09` | 波 1：`na`——无跨实例 Applier；Worker 门闩仅本进程 |
| （波3）帧长/CAS/多实例键空间 | **wave3** | `UC-W3-01`,`UC-W3-02` | 与 G6（sidecar 单例）/G16（键隔离）交叉；开工前门禁：波 1 admission PASS |

## 7. Acceptance 索引（供计划 `covers_use_cases`）

| acceptance_id | covers_use_cases | 建议 type | 波 |
|---------------|------------------|-----------|----|
| `ACC-W1-ADMIT-MEMORY-GATE` | `UC-W1-01` | unit/contract | 1 |
| `ACC-W1-ADMIT-503-HEADER` | `UC-W1-02` | unit/contract | 1 |
| `ACC-W1-STATUS-SCALARS` | `UC-W1-03` | unit/contract | 1 |
| `ACC-W1-RECYCLE-READY-DRAIN` | `UC-W1-04` | unit/contract | 1 |
| `ACC-W1-CHAOS-256M` | `UC-W1-05` | chaos/runtime | 1 |
| `ACC-W2-SCALE-UP` | `UC-W2-01` | unit+runtime | 2 |
| `ACC-W2-SCALE-DOWN-BASELINE` | `UC-W2-02` | unit+runtime | 2 |
| `ACC-W2-HOST-PRESSURE-NOOP` | `UC-W2-03` | unit | 2 |
| `ACC-W2-TOPOLOGY-DISPATCHER` | `UC-W2-04` | contract/integration | 2 |
| `ACC-W2-TOPOLOGY-DIRECT` | `UC-W2-05` | runtime | 2 |
| `ACC-W2-BUDGET-NOOP` | `UC-W2-06` | unit | 2 |
| `ACC-W2-DESIRED-SPLIT` | `UC-W2-07` | unit+runtime | 2 |
| `ACC-W2-BATCH-PLANNER` | `UC-W2-08` | unit | 2 |
| `ACC-W2-OPS-MUTEX` | `UC-W2-09` | unit/contract | 2 |
| `ACC-W2-PANEL` | `UC-W2-10` | manual/WB 可选 | 2 |
| `ACC-W2-PRESSURE-MERGE` | `UC-W2-11` | unit/contract | 2 |
| `ACC-W2-DRAIN-PROTO` | `UC-W2-12` | contract/runtime | 2 |
| `ACC-W3-MS-CHUNK` | `UC-W3-01` | unit/contract | 3 |
| `ACC-W3-L1-SHED` | `UC-W3-02` | unit/chaos | 3 |

## 8. 隐形需求摘要

- 既有 Fiber 排队/`X-WLS-Admission` 心智保持；新增 reason=`memory_pressure`，不推翻 timeout/queue_full。
- Guard 循环 compact 与准入门闩共用绝对余量语义，避免两套阈值漂移。
- Planned recycle 已实现 READY→DRAIN；本需求是**契约锁与压力路径对齐**，非从零发明。
- 波 2 必须正视存量 `memory_pressure_scale_down` Desired 写入，禁止顾问自动扩与压力缩双轨互抢。
- 默认拓扑与 auto-direct fallback（既有 spec）不得被本需求回退。

## 9. fe_be_scope

- `frontend`: none（店面无新 UI；波 2 面板为运维面可选）
- `backend`: WLS Master/Worker/Orchestrator/Guard/admission（波 1）
- `ops`: 本机 chaos / status_report 观测

## 10. 就绪检查

- [x] `status` = `ready-for-plan`
- [x] ≥1 用户故事 + 每故事可追溯 EARS
- [x] 波 1 ≥1 主成功 UC（实际 5 条）+ 映射 acceptance
- [x] G1–G16 全表：归属波 + UC 或 `na`+理由
- [x] 波 1 非目标明确（不改 Desired、不整袋 MS、不改默认 Dispatcher）
- [x] 波 2/3 UC 仅冻结意图，标注不施工
- [x] 计划期须令 `acceptance[].covers_use_cases` 覆盖全部 UC-*；收口前全 UC `passed`+证据
- [x] 未把类级补丁步骤写成唯一真源（how 交架构席）

## 11. 下一步（给项目经理 / 架构）

1. 对齐冻结会：钉本规格 UC + 更新 `contracts.md` 验收指针。  
2. 架构席：mechanism / owning_module / not_to_do（Guard API 暴露、admission 挂钩点、recycle 契约落点）。  
3. 性能席：热路径审查 + chaos 对比口径。  
4. 冻结后后端施工 `wave1-admit` ∥ `wave1-recycle`；**波 1 PASS 前禁止波 2**。
