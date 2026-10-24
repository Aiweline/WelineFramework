---
status: acceptance_passed
work_kind: performance
feature_slug: storefront-mix30-capacity-warm
module: Weline_Server
updated: 2026-10-11
session_path: ../../../../../../../dev/session/storefront-mix30-capacity-warm.md
team_path: ../../../../../../../dev/team/storefront-mix30-capacity-warm/
evidence: ../../../../../../../dev/tmp/chaos-local-mix30-cold-20261011102106-findings.md
acceptance_dig: ../../../../../../../dev/tmp/chaos-local-mix30-cold-20261011112103/
issuer_perf: ../../../../../../../dev/team/storefront-mix30-capacity-warm/channel/issuer-perf.md
issuer_review: ../../../../../../../dev/team/storefront-mix30-capacity-warm/meetings/性能检查-review.md
server_version: '2.0.173'
framework_version: '2.5.341'
related_specs:
  - ../../Product/doc/开发/spec/storefront-xscope-hotcache-wave.md
  - ../../Theme/doc/开发/spec/chrome-warmer-l2-shelf-locale-warm.md
  - ../../Theme/doc/开发/spec/storefront-chrome-locale-hotcache-prime.md
covers_use_cases:
  - UC-mix30-admission
  - UC-mix30-storm-p95
  - UC-quiet-hold
  - UC-clear-warm-order
  - UC-no-shell-strip
  - UC-evidence-split
  - UC-bags-quiet-only
mcp_note: docs seat — prepare_project / cognition_rules MCP timed out; ensure ready + host Read AI硬规则索引.md; did not pretend MCP prepare/cognition succeeded.
---

# Spec: 店面混发并发 30 · 容量与 Clear→warm

> **status: `acceptance_passed`（验收通过指针）**  
> 采信 dig：`dev/tmp/chaos-local-mix30-cold-20261011112103/` · issuer：`channel/issuer-perf.md` / `meetings/性能检查-review.md`（`issuer_acceptance=pass`）。  
> 实现版本：Server `2.0.173`（`--align-baseline`）· Framework `2.5.341`（Clear→warm 可观测）。  
> 基线 findings 仍见 §1；dig 全文与风暴表留在 `dev/tmp/`，**不**复制进 module doc。

## 1. 背景（证据锚定）

| 项 | 值 |
|----|-----|
| Host | `https://p05113ef3.test.weline.com` |
| Findings | `dev/tmp/chaos-local-mix30-cold-20261011102106-findings.md` |
| Dig | `dev/tmp/chaos-local-mix30-cold-20261011102106/`（`results.tsv` · `stats.json` · `quiet.tsv` · `php_error.slice.txt`） |
| 协议 | UA + `_wb_nc` + `wls_tpl_perf=1`；随机 locale×currency×表面（home/search/plp/blog） |
| 并发 | **conc=30** |
| 前置 | `cache:clear --pools-only -f` 后 **故意未 warm**（裸冷混发）；Worker desired=2（Current 曾到 3 / Ready 2） |
| 领域探查 | `dev/team/storefront-mix30-capacity-warm/meetings/domain-explore.md`（E1–E5） |

### 1.1 风暴窗（capacity / admission / 叠建附录）

| 指标 | 基线值（本 dig） |
|------|------------------|
| 样本 | 30 |
| HTTP | 200×16 · 503×10 · 301×4 |
| 墙钟 p50 / p95 / max | **21.47s / 40.53s / 43.67s** |
| ≥2.5s | 26/30 |
| 503 头 | **全部** `x-wls-admission: memory_pressure`（10） |
| RouterPerf FPC | `fpc=disabled`×11（search 为主）· `fpc=build_lock`×7（home/plp） |
| TemplatePerf | `after_ms > template_ms` = 12/18（chrome/phrase 争用放大） |

### 1.2 安静窗（同批 URL · 冷 leaf 主表）

风暴后串行同 URL（`quiet.tsv`）：

| path | 墙钟 s | FPC |
|------|-------:|-----|
| `/de_DE/search?q=hanfu&currency=CNY` | 1.09 | MISS |
| `/en_US/products?currency=AUD` | 1.21 | MISS |
| `/hi_IN/?currency=EUR` | 1.65 | MISS |
| `/pt_BR/blog?currency=AUD` | 2.09 | MISS |
| `/bn_BD/search?q=silk&currency=GBP` | 2.06 | MISS |

**硬结论**：风暴 20–40s **主因是叠建 + `build_lock` + `memory_pressure` 排队/拒入**，不是「单请求真冷就 40s leaf」。单请求安静冷仍在 **~1.1–2.1s**（与上波 tip 优化后量级一致）。

### 1.3 与上波 xscope 关系

上波 `storefront-xscope-hotcache-wave`（`delivered`）已把安静窗跨范围冷税与 Clear warmers 接线。本波 **不重开** xscope UC 当主验收；KPI 是 **混发容量 / admission / Clear→warm 编排 / 风暴附录尖峰**，并 **hold** 安静窗冷 hit1。

## 2. 证据口径（硬钉 · OPS-S5 继承）

| 窗 | 用途 | 合法指标 | **禁止** |
|----|------|----------|----------|
| **风暴窗**（conc=30 同脚本） | 容量 / admission / 叠建 / FPC `build_lock` **附录** | `memory_pressure` 503 计数；HTTP 分布；墙钟 p50/p95（附录）；RouterPerf `fpc=*` 计数；是否抬 Ready | 把 storm 墙钟 / after_ms / template_ms **冒充冷 leaf 预算**；把 503 当「页面慢 SQL」去拆壳 |
| **安静窗**（风暴后或独立串行 · 同批 URL） | **冷 leaf 主表** | cold hit1 墙钟；`wls_tpl_perf` 独占 builder；FPC MISS/HIT | 用 storm 40s 驱动袋扩面；用 sticky hit2 当冷修主 PASS |

分账规则：

1. **主证据（leaf）** = 安静窗 cold hit1（可附 tpl_perf）。  
2. **附录证据（capacity）** = 风暴窗 `memory_pressure` 计数 + storm p95。  
3. **禁拆壳**：header/footer/必装 widget / featured 货架部件不得为优化手段。  
4. **禁全 q 预热**；**禁仅粘 HIT 作 pass**。  
5. 报告若写「冷修 PASS」却只引用 storm 数字 → **FAIL 口径**（UC-evidence-split）。

## 3. 目标 / 非目标

### 目标

1. 同脚本 conc=30 下，`memory_pressure` 503 **显著下降或消失**（容量/admission）。  
2. 风暴墙钟 p95 相对本 dig 基线（≈40.5s）**下降**（附录 KPI；不得冒充 leaf）。  
3. 同批 URL 安静窗冷 hit1 **不回退**（默认仍 ≤≈2.5s）。  
4. `cache:clear` 后可复现 **正式 warm 编排**，再混发对照「裸冷混发」。  
5. 全程禁拆壳；审核放行前禁改业务 PHP。

### 非目标（硬）

- 拆 chrome / 搜索壳 / 必装 widget / featured 货架。  
- 全 q 预热；仅 sticky hit2 当主 pass。  
- 把风暴 20–40s 当单请求冷 leaf 预算或袋扩面理由。  
- 重开上波 xscope 已 delivered UC 当本波主验收。  
- 未审核放行前改业务码 / Runtime 硬编码业务路径或 slug。  
- 绕过 `CapacityScaleApplier` 手改 Desired；边缘盲重试风暴。

## 4. 澄清记录

| # | 问题 | 结论（本席冻结） | 来源 |
|---|------|------------------|------|
| C1 | 主因是 leaf 还是容量？ | **容量/叠建/admission**；quiet≈1–2s 已证非 40s leaf | findings §3 |
| C2 | 主证据窗？ | leaf→安静窗 cold hit1；capacity→风暴附录 | OPS-S5 + kickoff |
| C3 | 503 性质？ | 全为 `x-wls-admission: memory_pressure`，非业务 5xx 页 | results 头 |
| C4 | 施工门禁？ | **审核放行前禁施工** | 用户 + SESSION |
| C5 | 袋何时动？ | **仅**安静窗仍 ≥2.5s 的单请求才开 `P-bags-quiet-only` | findings M3/M5 + plan |
| C6 | FPC build_lock？ | 技术方案会勾选；默认先抬工人+Clear→warm 后再定是否本波动锁 | domain-explore E4 |
| C7 | Clear 编排归属？ | Framework `warmStorefrontFpcAfterClear` + 各模块 `cache.warmer.*` provides；chrome 子进程 reseed | domain-explore E3 |
| C8 | Desired 写入？ | 唯一经 Capacity Advisor intent → `CapacityScaleApplier` | domain-explore E1 |

### 可选拍板（≤5，不阻塞 `ready-for-plan`；默认已选）

| # | 项 | 默认（已冻结进 UC） | 若否需用户/冻结会改 |
|---|-----|---------------------|---------------------|
| Q1 | admission 通过线 | 同脚本复跑：`memory_pressure` 503 **≤2**（目标优先 **0**） | 更严「必须 0」或放宽 |
| Q2 | storm p95 通过线 | 相对基线 40.53s **下降 ≥40%**（附录；issuer 可签发更严） | 改百分比 |
| Q3 | quiet hold | 同批 5 URL cold hit1 **各自 ≤2.5s** 且相对本 dig 不显著回退 | 改阈值 |
| Q4 | P-fpc-build-lock | **默认本波可选**：抬工人+先 warm 后若 `build_lock` 仍主导 storm 附录才勾选施工 | 强制本波必做 |
| Q5 | P-bags-quiet-only | **默认待命**：仅 quiet≥2.5s leaf 才开；禁用 storm 触发 | 强制扩 tip |

## 5. 用户故事

### US-1 容量 / admission

As a **本机店面性能验收操作者**,  
I want **conc=30 混发时 Worker 容量与 memory admission 足以避免成片拒入**,  
so that **访客不被 `memory_pressure` 503 成批踢出，风暴尖峰可解释为叠建而非机器拒入**.

### US-2 Clear→warm 编排

As a **清袋后的验收操作者**,  
I want **`cache:clear` 后正式 warm 编排可复现再混发**,  
so that **「裸冷混发」与「暖后混发」可对照，不再把未暖当常态 KPI**.

### US-3 安静窗 hold

As a **匿名店面访客（单请求冷首触）**,  
I want **上波已压下的安静冷 hit1 不因本波容量改动回退**,  
so that **稳态冷税仍保持 ~1–2s 量级，而非用风暴数字掩盖 leaf 回退**.

## 6. EARS（验收标准）

### Server / 容量（P-server-capacity-admission）

- **E-A1** WHEN 同协议同 Host 以 conc=30 复跑混发脚本（记录 Ready/desired）THEN 系统 SHALL 使带 `x-wls-admission: memory_pressure` 的 503 **满足 Q1**（默认 ≤2，目标 0）。  
- **E-A2** WHEN 出现 503 THEN 验收 SHALL **只**按 admission 头归类；禁止把业务 5xx 页与 admission 503 混报。  
- **E-A3** IF 抬容量走 Desired THEN 写入 SHALL 经 Advisor intent → `CapacityScaleApplier`（或冻结会批准的显式 ops 抬档并记账）；禁止 Runtime 硬编码业务路径。

### 风暴附录（P-server / 可选 P-fpc-build-lock）

- **E-S1** WHEN 同脚本风暴窗统计 THEN 墙钟 p95 SHALL 相对基线 40.53s **满足 Q2**（附录 KPI）。  
- **E-S2** WHEN 报告 E-S1 THEN 文档 SHALL 标明「风暴附录」，**不得**宣称「冷 leaf 已达 p95」。  
- **E-S3** IF 技术方案会勾选 `P-fpc-build-lock` THEN RouterPerf `fpc=build_lock` 尖峰计数相对本 dig（×7）应下降或锁等待可分账；未勾选则本条 N/A。

### Clear→warm（P-clear-warm-orchestration）

- **E-W1** WHEN 执行 `cache:clear`（含 `--pools-only`）成功后 THEN 系统 SHALL 走既有 `warmStorefrontFpcAfterClear` / Registry warm（含 chrome 子进程），并留下可回查日志。  
- **E-W2** WHEN Clear→warm 完成后再跑同脚本混发 THEN 验收 SHALL 对比「裸冷混发」基线（本 dig），至少在 `memory_pressure` 与/或 storm p95 上可解释改善；禁止跳过 warm 把裸冷当唯一成功标准。  
- **E-W3** IF WLS Worker 清袋未完成 THEN 复跑 SHALL 先确认 IPC clear 成功（findings 已注明风险），不得空转 warm 冒充 PASS。

### 安静窗 hold / 袋（P-bags-quiet-only）

- **E-Q1** WHEN 对同批 quiet URL 串行 cold hit1 THEN 墙钟 SHALL 满足 Q3（≤2.5s 且不显著回退）。  
- **E-Q2** IF 某 URL 安静窗 cold hit1 **仍 ≥2.5s** THEN 才允许开启袋深挖（query_result / filter_panel / chrome）；**禁止**用风暴 40s 触发。  
- **E-Q3** WHEN 袋预热 THEN SHALL 保持有界 tip / plp_light；禁止全 q；禁止仅 sticky HIT 作 pass。

### 横切

- **E-X1** WHILE 本需求验收进行中 THEN 系统 SHALL 保持完整 storefront chrome 壳（禁拆壳）。  
- **E-X2** WHEN 汇报冷修/leaf 结果 THEN 证据 SHALL 以安静窗为主；storm 仅附录（UC-evidence-split）。  
- **E-X3** WHILE 审核波未放行 THEN 工程 SHALL **不**改业务 PHP（本规格澄清席已遵守）。

## 7. 用例（UC）

### UC-mix30-admission

| 字段 | 内容 |
|------|------|
| id | UC-mix30-admission |
| 名称 | conc=30 下 memory_pressure 503 下降 |
| 角色 | 性能验收 / Server 运维 |
| 前置 | Host 同上；记录 Worker Ready/desired；审核已放行后的施工完成（或对照实验配置） |
| 主成功步骤 | 1) 按冻结编排准备容量（抬 Ready / 内存上限等）2) 同协议 conc=30 混发 3) 统计 `x-wls-admission: memory_pressure` 次数 4) 对照基线 10 |
| 备选/异常 | Applier cooldown/ops_mutex 挡住扩容 → 记账手动抬档；禁止把 503 当 SQL 慢去拆壳 |
| 期望结果 | E-A1、E-A2、E-A3 |
| 映射 acceptance | `acc-mix30-admission`（runtime dig / results 头统计） |
| plan_id | P-server-capacity-admission |

### UC-mix30-storm-p95

| 字段 | 内容 |
|------|------|
| id | UC-mix30-storm-p95 |
| 名称 | 风暴 p95 墙钟下降（附录） |
| 角色 | 性能验收 |
| 前置 | 同 UC-mix30-admission 复跑产物 `stats.json` |
| 主成功步骤 | 1) 读 `time_p95_s` 2) 对照基线 40.53s 3) 报告标明「风暴附录」 |
| 备选/异常 | p95 未达标但 admission=0 且 quiet hold → issuer 可裁决是否仍开 P-fpc-build-lock |
| 期望结果 | E-S1、E-S2；（可选）E-S3 |
| 映射 acceptance | `acc-mix30-storm-p95`（附录） |
| plan_id | P-server-capacity-admission（主）· P-fpc-build-lock（可选） |

### UC-quiet-hold

| 字段 | 内容 |
|------|------|
| id | UC-quiet-hold |
| 名称 | 同批 URL 安静窗冷 hit1 不回退 |
| 角色 | 性能验收 / 匿名访客 |
| 前置 | 风暴复跑后或独立串行；同 dig 五条 path |
| 主成功步骤 | 1) 串行请求五条 URL + `_wb_nc` + `wls_tpl_perf=1` 2) 记录墙钟与 FPC 3) 断言 Q3 |
| 备选/异常 | Worker 仍忙 → 等待安静后再采；不得用 storm 样本代替 |
| 期望结果 | E-Q1 |
| 映射 acceptance | `acc-quiet-hold`（quiet.tsv / 探针） |
| plan_id | main（横切 hold） |

### UC-clear-warm-order

| 字段 | 内容 |
|------|------|
| id | UC-clear-warm-order |
| 名称 | Clear → 正式 warm → 再混发可复现 |
| 角色 | 性能验收 / Framework Cache |
| 前置 | 可执行 `cache:clear`；WLS soft-clear IPC 可用 |
| 主成功步骤 | 1) `cache:clear`（记录是否 pools-only）2) 确认 warm 编排日志（fpc/website/product/search/chrome 子进程等）3) 再跑同脚本混发 4) 对比裸冷基线 |
| 备选/异常 | Worker 清袋未完成 → 修复 IPC 后重跑；禁止 CLI 进程内 chrome warm 冒充 Worker 侧 HIT |
| 期望结果 | E-W1、E-W2、E-W3 |
| 映射 acceptance | `acc-clear-warm-order` |
| plan_id | P-clear-warm-orchestration |

### UC-no-shell-strip

| 字段 | 内容 |
|------|------|
| id | UC-no-shell-strip |
| 名称 | 禁拆壳 |
| 角色 | 主题完整性 / 验收横切 |
| 前置 | 任意本波验收样本页 |
| 主成功步骤 | 1) 抽检 home/search/plp DOM 或布局：header/footer/必装 widget 仍在 2) 计划/diff 无「删壳降税」 |
| 备选/异常 | 他席要求拆壳 → refuse + escalate PM/主题席 |
| 期望结果 | E-X1 |
| 映射 acceptance | `acc-no-shell-strip` |
| plan_id | main |

### UC-evidence-split

| 字段 | 内容 |
|------|------|
| id | UC-evidence-split |
| 名称 | 风暴附录 vs 安静窗主表分账 |
| 角色 | 性能检查工程师（issuer）/ 文档 |
| 前置 | 任意收口报告 |
| 主成功步骤 | 1) 检查报告是否分表：quiet 主表 / storm 附录 2) 断言无「以 storm 墙钟宣称冷 leaf PASS」 |
| 备选/异常 | 混报 → 验收 FAIL，退回重写证据 |
| 期望结果 | E-X2；§2 证据口径 |
| 映射 acceptance | `acc-evidence-split` |
| plan_id | main |

### UC-bags-quiet-only

| 字段 | 内容 |
|------|------|
| id | UC-bags-quiet-only |
| 名称 | 仅安静窗仍慢才动袋 |
| 角色 | Search/Product（条件施工） |
| 前置 | UC-quiet-hold 已跑；存在 quiet≥2.5s 的 URL；技术方案会勾选本项 |
| 主成功步骤 | 1) 锁定慢 URL 的 tpl_perf builder 2) 有界 tip / plp_light 调整 3) 安静窗复测 leaf 4) 证明未用 storm 触发 |
| 备选/异常 | 无 quiet≥2.5s → **本 UC = N/A（不开工）** |
| 期望结果 | E-Q2、E-Q3 |
| 映射 acceptance | `acc-bags-quiet-only`（条件） |
| plan_id | P-bags-quiet-only |

## 8. 归属 plan（审核对象）

| plan_id | mechanism（what） | owning_module | not_to_do | 默认本波 |
|---------|-------------------|---------------|-----------|----------|
| P-server-capacity-admission | 抬 Ready/workers·内存上限；admission `memory_pressure` 阈值/软回收；Desired 经 Advisor→Applier | `Weline_Server` | 503 当 SQL 慢；Runtime 硬编码业务；绕过 Applier | **必做（放行后）** |
| P-clear-warm-orchestration | Clear 后 `warmStorefrontFpcAfterClear` 可复现；chrome 子进程 + product/search/theme provides | `Weline_Framework` Cache ± Theme/Search/Product warmers | 裸冷当唯一成功；soft-pull 业务 FQCN；CLI 冒充 Worker HIT | **必做（放行后）** |
| P-fpc-build-lock | singleFlight / wait / stale-serve 分账；降低 home/plp `build_lock` 风暴尖峰 | `Weline_Framework` FPC/Router | storm 锁等待当 leaf；诊断 query 冒充冷隔离；拆壳 | **可选（会勾选）** |
| P-bags-quiet-only | 有界 tip / plp_light；仅 quiet≥2.5s | Search / Product | 全 q；storm 触发；仅粘 HIT | **待命** |
| main | 审核门禁 + 证据分账 + 禁拆壳 + quiet hold | 项目经理汇总；issuer=性能检查工程师 | 未放行施工；storm 冒充冷 leaf | 横切 |

## 9. 框架映射（mechanism · 复用，不发明）

| 触点 | 既有锚点（探查 E1–E5） | 本波用法 |
|------|----------------------|----------|
| Desired / 扩缩 | `CapacityAdvisor` → `CapacityScaleApplier`；`ServiceOrchestrator` | 混发前抬档 / 顾问 intent；唯一 Desired 写入器 |
| Admission | `WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate`；`wlsFiberAdmissionUnavailableResponse` | 503 头契约；调 headroom/工人数，不关业务页 |
| Clear warm | `Cache\Clear::warmStorefrontFpcAfterClear`；`CacheWarmerRegistry` | 正式编排对照裸冷 |
| FPC lock | `FullPageCacheCoordinator`；`Router\Core` `fpc=build_lock` | 可选调超时/stale；账本分列 |
| 袋 warmer | Theme chrome · Search tip · Product plp_light / shelf | 复用上波；仅 quiet 触发扩面 |
| 测法 | OPS-S5（xscope 已用） | storm 附录 / quiet 主表 |

**framework_first**：不发明平行进程内袋、不平行 Admission 栈、不在编排层写死 locale/slug 业务名单。

## 10. 建议落地顺序（放行后 · 不改壳）

1. **容量**：抬 storefront Worker Ready / 内存相关旋钮 → 复跑同脚本看 `memory_pressure`。  
2. **Clear→warm→混发**：对照裸冷基线。  
3. **账本**：RouterPerf `fpc` + TemplatePerf `template_ms`/`after_ms`；503 只查 admission 头。  
4. **可选锁 / 袋**：会勾选后；袋仅 quiet≥2.5s。

## 11. ready-for-plan 检查清单

- [x] 背景锚定 dig / findings / Host  
- [x] 风暴 vs 安静窗证据口径硬钉  
- [x] UC + EARS + acceptance id  
- [x] 非目标含禁拆壳 / 禁 storm 冒充冷 leaf / 禁未放行施工  
- [x] plan 归属表 + 框架映射  
- [x] 与上波 xscope 边界  
- [x] `status: ready-for-plan`  
- [x] 对齐冻结会冻 Q1–Q5 数字签发（性能 issuer）  
- [x] 技术方案会勾选：`P-fpc-build-lock` / `P-bags-quiet-only` 默认不开（issuer 复审维持）  
- [x] 审核放行后 construction + issuer 独占复跑  

---

## 12. 验收收口指针（耐久摘要 · 禁 dig 全文）

| 项 | 指针 |
|----|------|
| status | `acceptance_passed` |
| issuer_acceptance | `pass`（`channel/issuer-perf.md` · `meetings/性能检查-review.md`） |
| 采信 dig | `dev/tmp/chaos-local-mix30-cold-20261011112103/`（`stats.json` · `results.tsv` · `quiet.tsv`） |
| 基线 dig | `dev/tmp/chaos-local-mix30-cold-20261011102106/` |
| G-mix30-admission | 基线 `memory_pressure` 503×10 → issuer ×0 |
| G-quiet-main | quiet 串行 max 1.99s（&lt;2.5s） |
| storm p95 | 附录 28.44s（不作 leaf） |
| Server | `2.0.173` · `doc/开发日志.md` |
| Framework | `2.5.341` · `Framework/doc/开发日志.md` |
| 文档复审 | `dev/team/storefront-mix30-capacity-warm/channel/docs-acceptance.md` |

**当前 `status: acceptance_passed`。**  
路径：`app/code/Weline/Server/doc/开发/spec/storefront-mix30-capacity-warm.md`
