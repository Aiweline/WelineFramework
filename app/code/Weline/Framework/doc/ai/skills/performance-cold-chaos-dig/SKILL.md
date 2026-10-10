---
name: performance-cold-chaos-dig
description: >-
  店面冷切换混沌扫描找慢请求：多语种/货币/页面交织访问，慢点停下配合
  RouterPerf/TemplatePerf/HotCache 日志挖伪写与粘性失败。Companion of
  performance_check. Authority: this file + dev/ai-command/ai/性能冷切扫描.md.
---

# performance-cold-chaos-dig（性能冷切混沌扫描）

**MCP**：`get_skill(performance-cold-chaos-dig|doc:performance-cold-chaos-dig)`  
**指令**：`dev/ai-command/ai/性能冷切扫描.md`  
**席位**：默认由 `Team:性能检查工程师:` 执行；查出问题仍走 `findings_wake_pm`。  
**父技能**：`performance_check`（缓存合规 / 禁拆壳 / 与架构师共定制仍适用）。

## When To Use

触发词（任一即加载）

- `性能`
- `优化`
- `性能优化`
- `冷切扫描`
- `假写粘性`

用户要**多路径乱切找慢点**（语种/货币/站/博客/搜索/PLP/PDP 交织），或怀疑 **HotCache 假写 / 二次仍冷** 时加载本技能。  
纯设计评审、无实测扫描 → 只用 `performance_check`，不必本技能。

## Load First

1. 本文件 + `dev/ai-command/ai/性能冷切扫描.md`
2. `get_skill(performance_check|weline-performance-check)`（禁拆壳、缓存合规、escalate）
3. `app/code/Weline/Framework/doc/统一缓存范围与性能优化.md`
4. 本机交付 Host（`{project_hash}.test.weline.com`）；探活后扫描

## 抽象机制（硬）

一类问题：**读模型声称已缓存，但二次同 Scope 请求仍接近冷耗时**。

| 机制层 | 要验证什么 |
|--------|------------|
| **粘性（stickiness）** | 同逻辑键第二次是否明显快于第一次（排除 FPC 全页命中后的假绿） |
| **伪写（pretend write）** | `shared_write_ok=false`、WLS `file→wls_memory` 劫持后 `set()=false`、池未 `durable`/`hijack_exempt` |
| **故意不缓存** | 进程袋对大载荷直接跳过（如「>N 文档不入 L1」）却**没有** durable L2 → 每次全量重建 |
| **服务路径错位** | rollout/alias 仍走 direct/degrade，索引袋存在却从不服务 |
| **壳层后置耗时** | `TemplatePerf.after_ms`（chrome/phrase）与业务 `action_execute` 分离记账 |
| **叠建（dogpile）** | singleFlight 等待 ≪ 真实冷建 → peer 各自 builder_uncontended（矩阵尖峰，不等于单请求冷路径变快） |

特例（搜索投影、词典袋、chrome bag、货架 plan）只作 **例子**，禁止写成「只修搜索」的规则正文。

## 扫描协议（硬）

1. **交付 Host**：主链 `*.test.weline.com`；curl 须带浏览器 UA（否则 attack_guard 403）。
2. **绕全页缓存探粘性**：查询串加 `_wb_nc=<nonce>`（或等价），避免 FPC HIT 掩盖读模型冷路径。
3. **混沌冷切**：至少交织 ≥3 类表面 × ≥3 非默认 locale **与**货币；可夹站型/范围（如 b2b）。
4. **慢即停**：墙钟或 `RouterPerf.total_ms` ≥ 约定阈值（默认 **2.5s**）→ **立刻停下深挖该 URL**，禁止扫完再猜。
5. **记账分列（硬）**：
   - **冷（cold）** = 该逻辑键 **hit1**（须未入袋）
   - **粘（sticky）** = hit2–hit3，仅附录  
   **禁止**把 hit2/hit3「命中变快」当成优化主证据。
6. **安静窗复核**：矩阵尖峰须再隔离打 1 次冷 hit1，区分 **争用/叠建** vs **单请求真冷税**。
7. **日志配合**：`RouterPerf` / `TemplatePerf` / `LayoutPerf` + HotCache / `shared_write`。

## 深挖顺序（慢点停下后）

1. 拆 `action_execute` vs `TemplatePerf.after_ms`（业务 vs chrome/phrase）。
2. 对疑似读模型：找 `rememberPolicy` / process bag / durable 池；大载荷是否跳过 L1 且无 L2。
3. 对搜索/目录：确认 source（index vs direct vs degraded）。
4. 根因写成 **mechanism + owning_module + not_to_do**。
5. 动手前先写清下方 **修复说明四件套**（未写清禁止改码收口）。

## 修复说明四件套（硬）— 用户必须看得懂

面向用户收口**必须先写**（可放在冷表之前或紧接其后），缺一不可：

| # | 栏目 | 必须写清 |
|---|------|----------|
| 1 | **改了什么** | 路径 + 符号 + 改前→改后（例：`CachePolicy.singleFlightWaitMs` 1200→5000） |
| 2 | **为什么改** | 日志/安静窗证据：哪段耗时、什么机制坏了（假写 / 未入袋 / 等待≪冷建 / N+1…） |
| 3 | **因果链** | 这一改如何作用于**哪一类冷指标**：① 单请求真冷 hit1 变短；或 ② 仅降低混沌叠建尖峰（须明示「不缩短单次冷建」） |
| 4 | **怎么证明** | 对应测法：单请求冷 URL / 矩阵 cold_slow / `builder_uncontended` 消失等——**指标必须与因果链同类** |

硬禁：

- 只甩「median 降了 / 变快了」却不写改了什么、为什么。
- **因果错配**：改的是 singleFlight 等待（防叠建），却把「单请求冷路径变快」当结论；或改的是结果袋（助粘性），却把「冷 hit1 下降」当主功。
- 用暖命中表冒充冷修复证明。

## 收口证据（硬）— 冷统计前后对比

主表：

| URL / 样本 | 修前冷 hit1 | 修后冷 hit1 | 结论 |
|------------|-------------|-------------|------|
| … | …s | …s | 下降 / 持平 / 回退 |

硬规则：

1. 主证据 = 冷前后；粘性仅附录。
2. 修后样本须仍冷（新 q / 新 scope / 清袋）。
3. 若优化只改善粘性或只防叠建：冷主表如实写持平/尖峰减少，并在因果链标明类别。
4. 点名 owning_module。

## 禁止

- 无 `_wb_nc` 就宣称「已粘」
- 无 URL 级数字空谈变快
- 只用暖 hit2/hit3 命中表收口
- **无「改了什么/为什么/因果链」的数字汇报**
- 拆 chrome 壳当优化
- 平行 `static` 袋绕开 HotCache
- 查出缺口不 escalate `@项目经理：请立刻组队解决`

## 产物

```text
dev/tmp/perf-cold-chaos-{host}-{date}.tsv
dev/tmp/perf-cold-chaos-*-findings.md   # 须含：修复说明四件套 + 冷前后主表
```
