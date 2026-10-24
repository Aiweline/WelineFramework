---
status: acceptance_passed
work_kind: performance
feature_slug: storefront-mix30-wallclock
module: Weline_Framework
updated: 2026-10-11
session_path: ../../../../../../../dev/session/storefront-mix30-wallclock.md
team_path: ../../../../../../../dev/team/storefront-mix30-wallclock/
evidence: ../../../../../../../dev/tmp/chaos-local-mix30-wallclock-20261011112103-findings.md
acceptance_dig: ../../../../../../../dev/tmp/chaos-local-mix30-cold-20261011121453/
quiet_resign: ../../../../../../../dev/tmp/storefront-mix30-wallclock-quiet-resign-20261011122340/
issuer_perf: ../../../../../../../dev/team/storefront-mix30-wallclock/channel/issuer-perf.md
issuer_review: ../../../../../../../dev/team/storefront-mix30-wallclock/meetings/性能检查-review.md
framework_version: '2.5.342'
theme_version: '2.2.975'
related_specs:
  - ../../Server/doc/开发/spec/storefront-mix30-capacity-warm.md
  - ../../Product/doc/开发/spec/storefront-xscope-hotcache-wave.md
covers_use_cases:
  - UC-quiet-main
  - UC-storm-split-ledger
  - UC-falsify-w1-w7
  - UC-fpc-build-after
  - UC-clear-warm-honesty
  - UC-fiber-config-boundary
  - UC-fileadapter-near-limit
  - UC-measure-fpc-contract
  - UC-no-shell-strip
  - UC-no-storm-p95-only
mcp_note: >-
  文档席 prepare_project / cognition_rules MCP timeout/connection closed；
  ensure ready + 宿主 Read AI硬规则索引.md；未假装 MCP/Cognition 成功。
---

# Spec: 店面混发并发 30 · 墙钟 / 叠建 / 不当优化

> **status: `acceptance_passed`（验收通过指针）**  
> 采信 dig：`dev/tmp/chaos-local-mix30-cold-20261011121453/` · quiet-resign：`dev/tmp/storefront-mix30-wallclock-quiet-resign-20261011122340/` · issuer：`channel/issuer-perf.md`（`issuer_acceptance=pass_with_notes`）。  
> 实现版本：Framework `2.5.342`（`fpc_warm_ok`）· Theme `2.2.975`（`StorefrontFpcWarmer` `public_origin`）。  
> 上波 `storefront-mix30-capacity-warm` 已关 admission 主验收；本波不重开 capacity/admission KPI。  
> dig 全文留在 `dev/tmp/`，**不**复制进 module doc。

## 1. 背景（证据锚定）

| 项 | 值 |
|----|-----|
| Host | `https://p05113ef3.test.weline.com` |
| 种子 findings | `dev/tmp/chaos-local-mix30-wallclock-20261011112103-findings.md` |
| 澄清基线 dig | `dev/tmp/chaos-local-mix30-cold-20261011112103/`（Clear→warm 后 · Ready 2×768M · conc=30 · 503×0） |
| **验收 dig** | `dev/tmp/chaos-local-mix30-cold-20261011121453/`（施工后 · LEDGER/HIT/W6） |
| quiet-resign | `dev/tmp/storefront-mix30-wallclock-quiet-resign-20261011122340/`（G-quiet max 1.616s） |
| 对照 | capacity-warm 基线 dig `…102106`（admission 开）；澄清基线 `…112103` 已 **503×0** |
| 协议 | UA + `_wb_nc` + `wls_tpl_perf=1`；随机 locale×currency×表面 |
| 前置 | capacity-warm 放行后的 Clear→warm；Worker Ready≈2 |

### 1.1 风暴窗（墙钟 / 叠建附录 · 非 leaf）

| 指标 | 值（澄清基线 dig `…112103`；验收对照见 §12 dig `…121453`） |
|------|-------------|
| HTTP | 200×19 · 301×11 · **503×0** |
| 墙钟 p50 / p95 / max | **18.05 / 28.44 / 29.48 s** |
| RouterPerf n=19 | `fpc=disabled`×10（search 为主）· `fpc=build_lock`×9（home/plp/blog） |
| action_execute_ms | p50 / p95 / max ≈ **6.9 / 21.1 / 21.1 s** |
| TemplatePerf | `after_ms > template_ms` = **11/19**；after p95≈**21.0s** |
| 代表 | home `it_IT/?EUR`：template≈2ms · **after≈21s** · `fpc=build_lock` |

**硬结论**：墙钟尖峰 **不是** admission 拒入；差额（墙钟 18–29s vs action 7–21s）含 Fiber 准入排队（`max_active=6` · wait≤30s）+ 边缘/并发启动。**禁止**把整段墙钟当 leaf SQL。

### 1.2 安静窗（冷 leaf 主表）

同 dig 串行 quiet（节选；max **1.99s**）：

| path（摘要） | 墙钟 s |
|--------------|-------:|
| `/ar_SA/search?q=silk…` | 0.73 |
| `/pt_BR/search?q=robe…` | 0.74 |
| `/de_DE/search?q=robe…` | **1.99** |
| `/en_US/blog…` | 0.96 |

→ quiet 冷 hit1 仍 ~1–2s；风暴 18–29s 是 **叠建/锁/排队/测法**，不是「单请求真冷就 28s leaf」。

### 1.3 可疑不当优化 / 流程候选（W1–W7 · 待证伪）

| ID | 候选 | 归属猜想 |
|----|------|----------|
| **W1** | `_wb_nc` 永远打穿 FPC → storm 永远量叠建而非 HIT | 测法 + FPC 契约 |
| **W2** | `build_lock` 下仍全量 after/chrome — 持锁者与等待者双烧 | Framework FPC + Theme after |
| **W3** | `wait_miss`：等发布超时再 SSR → 空等后双倍工 | `FullPageCacheCoordinator` |
| **W4** | Fiber 钉 `max_active=6` + 深队列：存活换墙钟 | Server fiber 配置语义 |
| **W5** | FileAdapter 近顶拒读 → miss 重建风暴 | Framework Cache Adapter |
| **W6** | Clear→warm 摘要 `fpc:warmed=0` 却宣称暖完 → 必走 dogpile | Clear 编排诚实性 |
| **W7** | search 无 FPC + tip 有界：随机 q×locale×currency 永远冷体 | Search 袋边界（非全 q） |

## 2. 证据口径（硬钉 · 本波硬化）

| 表 | 用途 | 合法指标 | **禁止** |
|----|------|----------|----------|
| **主表（quiet）** | 冷 leaf / 稳态税 | 串行 cold hit1 墙钟；`wls_tpl_perf` builder；FPC MISS/HIT（可对非 `_wb_nc` 对照） | 用 storm 墙钟宣称 leaf PASS |
| **附录 A（storm 分账）** | 叠建 / 锁 / 排队可解释性 | **分列**：`queue_wait`（墙钟−action 或等价）· `action_execute_ms` · `template_ms` · `after_ms` · `fpc=` 态计数 · HTTP | **仅报 storm p95** 作唯一 KPI；把 503 当慢页 |
| **附录 B（storm 原始）** | 趋势对照 | 墙钟 p50/p95/max（须标明附录） | 冒充冷 leaf 预算或袋扩面理由 |

分账规则：

1. **主证据** = quiet 冷 hit1（hold 上波 ~1–2s / ≤2.5s）。  
2. **墙钟优化 PASS** = 附录 A 分账改善可解释 +（可选）附录 B 下降；**缺分账 → FAIL**。  
3. **禁拆壳**；**禁仅 storm p95**；**禁全 q 预热**；**禁仅粘 HIT**。  
4. 报告若写「冷修 / leaf PASS」却只引用 storm p95 → **FAIL**（UC-no-storm-p95-only / UC-storm-split-ledger）。

## 3. 目标 / 非目标

### 目标

1. **证伪或确认** W1–W7；确认项才允许进技术方案勾选施工（mechanism + owning_module）。  
2. 硬化验收：**quiet 主表 + storm 分账附录**；禁止仅 storm p95。  
3. 针对确认的不当优化 / 流程问题，压低可解释的叠建税（build_lock after、wait_miss 空等、Clear 假暖、近顶拒读风暴、fiber 排队边界语义），且 **quiet 不回退**。  
4. 全程禁拆壳；审核放行前禁改业务 PHP。  
5. 慢慢做：不追求一次 PR 吃光全部 W*。

### 非目标（硬）

- 重开 capacity-warm 的 admission 主 KPI（已 503×0）。  
- 拆 chrome / 搜索壳 / 必装 widget / featured 货架。  
- 把 storm 18–29s / p95 当单请求冷 leaf 或袋扩面理由。  
- **仅以 storm 墙钟 p95 下降宣称本波 PASS**。  
- 全 q 预热；仅 sticky HIT 作 pass。  
- 未审核放行前改业务码；Runtime / 编排层硬编码业务路径或 slug。  
- 绕过既有 FPC / Clear / Fiber 机制另起平行进程内袋或平行 Admission 栈。

## 4. 澄清记录

| # | 问题 | 结论（本席冻结） | 来源 |
|---|------|------------------|------|
| C1 | 本波主因是 admission？ | **否**；本 dig 503×0；尖峰是叠建/锁/排队/测法 | findings §1–2 |
| C2 | 主证据窗？ | **quiet 主表**；storm 必须 **分账附录**，原始 p95 仅附录 B | 用户意图 + findings §4 |
| C3 | 能否只看 storm p95？ | **禁止**作唯一 leaf/主 KPI | HARD |
| C4 | 能否拆壳换分？ | **禁止** | HARD + theme_seat_integrity |
| C5 | 与 capacity-warm 边界？ | 不重开 admission UC 主验收；继承 Clear→warm / 禁拆壳 / 证据分账精神并硬化分账列 | related_specs |
| C6 | 施工门禁？ | **审核放行前禁施工**；澄清席只写规格 | kickoff |
| C7 | W1–W7 是否全做？ | 先证伪；**确认**才勾选 plan；未确认不得开工 | findings §3 + escalate |
| C8 | 归属？ | 主落 `Weline_Framework`（FPC/Clear/FileAdapter）；fiber 钉档边界属 `Weline_Server`（配置语义，不重开 capacity） | 用户归属建议 |
| C9 | SESSION？ | 本席 **禁改 SESSION**；由 PM 检查后更新 | 用户 HARD |
| C10 | MCP？ | prepare/cognition/get_skill 超时 → 宿主 Read 硬规则索引继续；不假装已遵守 MCP 下发 | mcp_note |

### 可选拍板（≤5，不阻塞 `ready-for-plan`；默认已选）

| # | 项 | 默认（已冻结进 UC） | 若否需冻结会改 |
|---|-----|---------------------|----------------|
| Q1 | quiet hold | 同协议串行 cold hit1 **各自 ≤2.5s** 且相对本 dig 不显著回退（max≈1.99s） | 更严阈值 |
| Q2 | storm 分账 PASS | 收口报告含附录 A 五列（queue_wait / action / after / template / fpc 态）；缺任一列 → FAIL | 列名微调 |
| Q3 | 附录 B p95 | **可选趋势**：相对 28.44s 下降；**单独达标不足以 PASS** | issuer 签发更严 |
| Q4 | W* 施工勾选 | 默认全待命；技术方案会仅勾选 **已确认** 项 | 强制先做某 W |
| Q5 | 测法对照 | 验收须含「带 `_wb_nc`」与「无 cache-buster」各一档对照（证 W1）；禁止只跑打穿档 | 改对照设计 |

## 5. 用户故事

### US-1 墙钟 / 叠建可解释

As a **本机店面性能验收操作者**,  
I want **conc=30 混发墙钟尖峰能按 queue / action / after / template / fpc 态分账**,  
so that **优化对准真正叠建与不当优化，而不是把排队或测法打穿当成 leaf SQL**.

### US-2 框架不当优化纠偏

As a **框架维护者**,  
I want **对 W1–W7 先证伪再改 mechanism**,  
so that **不会在「防 OOM / 钉 fiber / 暖池」名义下继续放大混发 rebuild 或假暖**.

### US-3 安静窗 hold

As a **匿名店面访客（单请求冷首触）**,  
I want **上波已压下的安静冷 hit1 不因本波墙钟优化回退**,  
so that **稳态冷税仍保持 ~1–2s，而非用风暴数字掩盖 leaf 回退**.

## 6. EARS（验收标准）

### 证据分账（横切）

- **E-E1** WHEN 汇报本波墙钟/叠建结果 THEN 文档 SHALL 同时给出 **quiet 主表** 与 **storm 附录 A 分账**；禁止仅附录 B p95。  
- **E-E2** IF 报告宣称「冷 leaf / 冷修 PASS」THEN 依据 SHALL 来自 quiet 主表；引用 storm 墙钟作 leaf → FAIL。  
- **E-E3** WHEN 统计风暴墙钟 THEN SHALL 标明附录，并分列 queue_wait（或等价）/ action / after / template / fpc 态。

### 证伪门禁

- **E-F1** WHEN 技术方案会勾选任一 W* 施工 THEN 前置 SHALL 有性能检查/领域探查的 **确认或证伪记录**（`false` / `confirmed` / `partial`）；未记录不得开工。  
- **E-F2** WHILE 审核未放行 THEN 工程 SHALL **不**改业务 PHP（本澄清席已遵守）。

### FPC / after / wait_miss（确认后施工）

- **E-P1** IF W2 确认 THEN WHEN 同 key 出现 `fpc=build_lock` THEN 系统 SHALL 使等待者可观测路径避免与持锁者同等全量 after 双烧（具体 mechanism 留计划；本规格只钉可观察：等待者 after 尖峰下降或分账可解释）。  
- **E-P2** IF W3 确认 THEN WHEN 锁未拿到/等待超时 THEN 系统 SHALL **不**再走「空等满窗 + 全量 SSR」双倍工（继承既有 wait_miss 护栏精神；回归契约 + RouterPerf 无异常 wait_miss 尖峰）。  
- **E-P3** IF W1 相关测法合同确认 THEN WHEN 验收对照无 `_wb_nc` 档 THEN SHALL 能观察到合法 FPC HIT/STALE 路径；打穿档仅用于叠建附录，不得冒充稳态 HIT KPI。

### Clear / FileAdapter / Fiber 边界

- **E-C1** IF W6 确认 THEN WHEN `warmStorefrontFpcAfterClear` 结束 THEN 日志/摘要 SHALL **诚实**反映 FPC（及关键池）warmed 计数；禁止 `fpc:0` 却暗示暖完可抗混发。  
- **E-A1** IF W5 确认 THEN WHEN FileAdapter 近顶拒读触发 THEN 行为 SHALL 可分账（拒读计数 / 重建风暴），且修复不得用拆壳或平行袋；quiet 主表不回退。  
- **E-S1** IF W4 确认 THEN WHEN 调整 fiber `pin_max_active` / `max_active` / 排队窗 THEN 文档 SHALL 写清「存活 vs 墙钟」权衡；验收用附录 A 的 queue_wait，不得只报 503=0。

### 安静窗 / 禁拆壳

- **E-Q1** WHEN 对同批或等价 quiet URL 串行 cold hit1 THEN 墙钟 SHALL 满足 Q1。  
- **E-X1** WHILE 本需求验收进行中 THEN 系统 SHALL 保持完整 storefront chrome 壳（禁拆壳）。  
- **E-X2** WHEN 袋/搜索相关调整（仅当 W7 且 quiet 仍慢）THEN SHALL 保持有界 tip；禁止全 q；禁止仅粘 HIT。

## 7. 用例（UC）

### UC-quiet-main

| 字段 | 内容 |
|------|------|
| id | UC-quiet-main |
| 名称 | 安静窗冷 hit1 主表 hold |
| 角色 | 性能验收 / 匿名访客 |
| 前置 | 本 dig 协议；Worker 不在风暴高峰 |
| 主成功步骤 | 1) 串行请求 quiet URL + `_wb_nc` + `wls_tpl_perf=1` 2) 记录墙钟 3) 断言 Q1 |
| 备选/异常 | Worker 忙 → 等待后再采；不得用 storm 样本代替 |
| 期望结果 | E-Q1 |
| 映射 acceptance | `acc-quiet-main` |
| plan_id | main |

### UC-storm-split-ledger

| 字段 | 内容 |
|------|------|
| id | UC-storm-split-ledger |
| 名称 | 风暴墙钟分账附录（非仅 p95） |
| 角色 | 性能检查工程师（issuer） |
| 前置 | 同脚本 conc=30 dig；RouterPerf/TemplatePerf 可切片 |
| 主成功步骤 | 1) 统计 HTTP（须 503 归类，本波预期≈0）2) 建附录 A：queue_wait / action / after / template / fpc 态 3) 附录 B 可附 p50/p95 4) 断言报告非「仅 p95」 |
| 备选/异常 | 缺分账列 → FAIL 退回 |
| 期望结果 | E-E1、E-E3 |
| 映射 acceptance | `acc-storm-split-ledger` |
| plan_id | main |

### UC-no-storm-p95-only

| 字段 | 内容 |
|------|------|
| id | UC-no-storm-p95-only |
| 名称 | 禁仅 storm p95 作 PASS |
| 角色 | issuer / 汇审 |
| 前置 | 任意收口报告 |
| 主成功步骤 | 1) 检查是否存在 quiet 主表 2) 检查是否存在附录 A 分账 3) 若只有 storm p95 → FAIL |
| 备选/异常 | 无 |
| 期望结果 | E-E1、E-E2 |
| 映射 acceptance | `acc-no-storm-p95-only` |
| plan_id | main |

### UC-falsify-w1-w7

| 字段 | 内容 |
|------|------|
| id | UC-falsify-w1-w7 |
| 名称 | W1–W7 证伪表落盘后再勾选施工 |
| 角色 | 性能检查 + 领域探查 |
| 前置 | 种子 findings；只读探针许可 |
| 主成功步骤 | 1) 对 W1–W7 逐条 `false`/`confirmed`/`partial` + 证据路径 2) 写入 team meetings/channel 3) 技术方案会只勾选 confirmed |
| 备选/异常 | 证据不足 → `partial` 待命，不得猜施工 |
| 期望结果 | E-F1 |
| 映射 acceptance | `acc-falsify-w1-w7` |
| plan_id | P-falsify-gate |

### UC-measure-fpc-contract

| 字段 | 内容 |
|------|------|
| id | UC-measure-fpc-contract |
| 名称 | `_wb_nc` 打穿档 vs 无 buster 对照（W1） |
| 角色 | 性能验收 |
| 前置 | W1 进入确认或 partial；Host 同上 |
| 主成功步骤 | 1) 同 URL 带 `_wb_nc` 采一档 2) 无 cache-buster 再采一档（注意别污染对照）3) 对比 FPC 头 / RouterPerf fpc 态 4) 报告写明哪档只可作叠建附录 |
| 备选/异常 | 无 HIT 可能路径 → 记 partial，不得把打穿档当稳态 KPI |
| 期望结果 | E-P3、Q5 |
| 映射 acceptance | `acc-measure-fpc-contract` |
| plan_id | P-measure-fpc（W1） |

### UC-fpc-build-after

| 字段 | 内容 |
|------|------|
| id | UC-fpc-build-after |
| 名称 | build_lock 路径 after 双烧缓解（W2） |
| 角色 | Framework FPC / Theme（确认后） |
| 前置 | W2=`confirmed`；审核放行；技术方案勾选 |
| 主成功步骤 | 1) 复现同 key build_lock 2) 分账持锁者 vs 等待者 after 3) 施工后附录 A after 尖峰下降或可解释 4) quiet hold |
| 备选/异常 | W2=false → 本 UC=N/A |
| 期望结果 | E-P1、E-Q1 |
| 映射 acceptance | `acc-fpc-build-after`（条件） |
| plan_id | P-fpc-build-after |

### UC-clear-warm-honesty

| 字段 | 内容 |
|------|------|
| id | UC-clear-warm-honesty |
| 名称 | Clear→warm 摘要诚实（W6） |
| 角色 | Framework Cache |
| 前置 | W6=`confirmed` 或 partial；可执行 `cache:clear` |
| 主成功步骤 | 1) Clear→warm 2) 读 warmed 摘要（fpc/chrome/…）3) 断言摘要与可观测 FPC 池一致 4) 混发对照时不得把 `fpc:0` 当暖成功 |
| 备选/异常 | W6=false → N/A；IPC 失败须 warning 非假绿 |
| 期望结果 | E-C1 |
| 映射 acceptance | `acc-clear-warm-honesty`（条件） |
| plan_id | P-clear-warm-honesty |

### UC-fiber-config-boundary

| 字段 | 内容 |
|------|------|
| id | UC-fiber-config-boundary |
| 名称 | Fiber 钉档 / 排队 vs 墙钟权衡（W4） |
| 角色 | Server 配置边界（非 admission 重开） |
| 前置 | W4=`confirmed`；审核放行 |
| 主成功步骤 | 1) 记录 pin_max_active / max_active / wait 窗 2) 风暴附录 A 标 queue_wait 3) 任何调参后 quiet hold + 503 归类仍诚实 |
| 备选/异常 | W4=false → N/A；禁止为墙钟关掉内存护栏却不记账 |
| 期望结果 | E-S1、E-Q1 |
| 映射 acceptance | `acc-fiber-config-boundary`（条件） |
| plan_id | P-fiber-config-boundary |

### UC-fileadapter-near-limit

| 字段 | 内容 |
|------|------|
| id | UC-fileadapter-near-limit |
| 名称 | 近顶拒读不放大 rebuild（W5） |
| 角色 | Framework Cache Adapter |
| 前置 | W5=`confirmed`；审核放行 |
| 主成功步骤 | 1) 复现或契约锁定近顶拒读路径 2) 风暴下观察 miss/rebuild 是否被放大 3) 修复后附录 A template/after 尖峰可解释下降 4) quiet hold |
| 备选/异常 | W5=false → N/A |
| 期望结果 | E-A1、E-Q1 |
| 映射 acceptance | `acc-fileadapter-near-limit`（条件） |
| plan_id | P-fileadapter-near-limit |

### UC-no-shell-strip

| 字段 | 内容 |
|------|------|
| id | UC-no-shell-strip |
| 名称 | 禁拆壳 |
| 角色 | 主题完整性 / 验收横切 |
| 前置 | 任意本波验收样本页 |
| 主成功步骤 | 1) 抽检 home/search/plp：header/footer/必装 widget 仍在 2) diff 无删壳降税 |
| 备选/异常 | 他席要求拆壳 → refuse + escalate |
| 期望结果 | E-X1 |
| 映射 acceptance | `acc-no-shell-strip` |
| plan_id | main |

## 8. 归属 plan（审核对象 · 默认待命）

| plan_id | mechanism（what） | owning_module | not_to_do | 默认本波 |
|---------|-------------------|---------------|-----------|----------|
| P-falsify-gate | W1–W7 证伪表；未确认禁施工 | 性能检查 + 领域探查（证据）；PM 记账 | 猜施工；跳过证伪 | **必做（澄清后立刻）** |
| P-measure-fpc | 测法对照合同；打穿档只作叠建附录 | Framework FPC 契约 + 验收脚本 | 打穿档冒充 HIT KPI | W1 确认/partial 后 |
| P-fpc-build-after | build_lock 路径降低双烧 after / 等待可分账 | `Weline_Framework` FPC ± Theme | 拆壳；storm after 当 leaf | W2 确认后可选 |
| P-wait-miss-guard | wait_miss 空等+SSR 回归护栏 | `Weline_Framework` FPC Coordinator | 放宽成双倍工 | W3 确认后可选 |
| P-clear-warm-honesty | warmed 摘要诚实；假暖可观测 | `Weline_Framework` Cache Clear | 假绿；CLI 冒充 Worker HIT | W6 确认后 |
| P-fileadapter-near-limit | 近顶拒读不放大混发 rebuild | `Weline_Framework` FileAdapter | 平行袋；拆壳换内存 | W5 确认后 |
| P-fiber-config-boundary | pin/max_active/排队语义与墙钟分账 | `Weline_Server` fiber 配置 | 重开 admission 主 KPI；隐瞒 queue_wait | W4 确认后 |
| P-search-tip-boundary | 仅 quiet 仍慢时有界 tip（非全 q） | `Weline_Search` | storm 触发；全 q | W7 且 quiet≥2.5s |
| main | 证据分账 + 禁拆壳 + quiet hold + 审核门禁 | PM 汇总；issuer=性能检查 | 仅 p95 PASS；未放行施工 | 横切 |

## 9. 框架映射（mechanism · 复用，不发明）

| 触点 | 既有锚点 | 本波用法 |
|------|----------|----------|
| FPC 协调 / 锁 | `FullPageCacheCoordinator`；`Router\Core` `fpc=build_lock\|wait_miss\|disabled` | W2/W3 证伪与可选施工；RouterPerf 分账 |
| Clear→warm | `Cache\Clear::warmStorefrontFpcAfterClear`；`CacheWarmerRegistry` | W6 诚实性；继承 capacity-warm 编排，不重开 admission |
| File 缓存近顶 | `Cache\Adapter\FileAdapter` near-limit 拒读；既有契约测 | W5 |
| Fiber 准入排队 | `wlsAwaitFiberAdmissionSlot`；`fiber.max_active` / `pin_max_active` / `admission_queue_wait_ms` | W4 边界；queue_wait 进附录 A |
| Admission 503 | `WorkerResponseMemoryGuard`（上波已关） | **本波非主 KPI**；仅归类防误报 |
| 袋 warmer | Search tip / Theme chrome / Product plp_light | 仅 W7+quiet 触发；禁全 q |
| 测法 | `_wb_nc` + OPS-S5 精神 | W1 对照；打穿≠稳态 |

**framework_first / architecture_grade**：确认项须 mechanism + owning_module + not_to_do；禁止 Runtime 硬编码业务 slug；禁止平行缓存栈。

## 10. 建议落地顺序（放行后 · 不改壳）

1. **证伪门禁**：落地 W1–W7 表（性能 + 领域探查）。  
2. **测法合同**：`_wb_nc` vs 无 buster 对照写入验收。  
3. **按确认优先级**：Clear 诚实性 → build_lock/after → FileAdapter → wait_miss 回归 → fiber 配置边界 →（条件）search tip。  
4. **每步复跑**：quiet 主表 + storm 附录 A；附录 B 仅趋势。

## 11. ready-for-plan 检查清单

- [x] 背景锚定 dig / findings / Host；与 capacity-warm 边界清晰  
- [x] quiet 主表 + storm 分账附录硬钉；禁仅 storm p95；禁拆壳  
- [x] ≥1 用户故事 + 每故事可映射 EARS  
- [x] UC 含主成功 + 条件 UC；每条映射 acceptance id  
- [x] 非目标明确  
- [x] plan 归属表 + 框架映射（复用锚点）  
- [x] 未把具体补丁步骤写成需求正文  
- [x] `status: ready-for-plan`（澄清波）→ 收口升格 `acceptance_passed`  
- [x] 对齐冻结 / 技术方案勾选 confirmed：W1（测法）+ W6（Clear 诚实）；W4 fiber deferred  
- [x] 审核放行后 construction + issuer 独占 quiet 补签  

---

## 12. 验收收口指针（耐久摘要 · 禁 dig 全文）

| 项 | 指针 |
|----|------|
| status | `acceptance_passed` |
| issuer_acceptance | `pass_with_notes`（`dev/team/storefront-mix30-wallclock/channel/issuer-perf.md` · `meetings/性能检查-review.md`） |
| 采信 dig | `dev/tmp/chaos-local-mix30-cold-20261011121453/`（`clear-warm-prior.txt` · `ledger.json` · `quiet.tsv` · `clean-hit-after-rewarm.tsv` · `stats.json`） |
| quiet-resign | `dev/tmp/storefront-mix30-wallclock-quiet-resign-20261011122340/`（`clear-warm.txt` · `quiet.tsv` · `quiet-hdrs/`） |
| 基线 dig | `dev/tmp/chaos-local-mix30-cold-20261011112103/` |
| G-W6 Clear→warm | `fpc:warmed≥7` · `fpc_warm_ok=1` · `storefront_mix_ready=1`（Theme warmer `public_origin`） |
| G-W1 LEDGER / HIT | 五列齐 · 干净 URL rewarm 后 HIT；`_wb_nc` 仅叠建档 |
| G-quiet | resign max **1.616s** ≤ 2.5s |
| storm 附录 | `admission:timeout` deferred → `P-fiber-config-boundary`；裸 p95 **不作 leaf** |
| Framework | `2.5.342` · `doc/开发日志.md` · `Cache/Console/Cache/Clear.php` |
| Theme | `2.2.975` · `Theme/doc/开发日志.md` · `Service/StorefrontFpcWarmer.php` |
| 文档复审 | `dev/team/storefront-mix30-wallclock/channel/docs-acceptance.md` |

**当前 `status: acceptance_passed`。**  
路径：`app/code/Weline/Framework/doc/开发/spec/storefront-mix30-wallclock.md`  
交付地址：N/A（性能/缓存契约，无 UI 交付页）。
