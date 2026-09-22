# 提示词优化 · 语义复审 — ui_prototype_gate_before_test

| 字段 | 值 |
|------|-----|
| role | 提示词优化工程师 |
| agent_id | 6f2d9cd3-0e87-4c11-b015-d3b91d0c802a |
| 波次 | 语义复审轨 |
| verdict | **pass** |
| result | closed |
| 日期 | 2026-09-22 |

权威对照：`提示词优化.md` 改写铁律；`工程团队.md` 总原则 4/4b + 流水线；`HardConstraintsCatalog` id `ui_prototype_gate_before_test`；`McpSkillCatalog` principles + `acceptance_gate_order` + 席镜；`GuidanceWorkflowCatalog` norms/forbidden/required。  
本波义务来自用户点名 + 已冻 align-freeze，非本席发明。

---

## 复审清单

### 1. 硬顺序是否一致

**pass。** 各权威落点同序：

1. 专席合规复审 pass  
2. UI ∥ 原型审查（可打回 → PM resume 开发子智能体）→ `acceptance-ui.md` / `acceptance-prototype.md` 双 pass  
3. 测试执行（UT/RT/WB/e2e）  
4. 项目经理汇审  
5. 才可向用户汇报  

证据：`工程团队.md` §4b / UI/原型成果审查波 / 硬顺序段；`HardConstraintsCatalog` summary + agent_guidance 压缩句；`McpSkillCatalog.acceptance_gate_order` = `specialty_reviews_pass → ui_and_prototype_review_pass → tester_execution_pass → pm_huishen_pass → user_report_allowed`；`AI工程交付流程.md` §3b/§6/验收段；`GuidanceWorkflowCatalog` engineering_team notes HARD ORDER。

### 2. 红灯骨架可施工期落盘、执行须过签

**pass。** 写清于：`工程团队.md` 4b（「红灯骨架可在施工期落盘，**执行**须等过签」）、流水线「测试时序」表、测试席 `prompt_increment`、HardConstraintsCatalog「red-light skeleton during construction is still allowed; EXECUTION waits」。

### 3. 是否削弱既有硬规则

| 规则 | 判定 |
|------|------|
| `acceptance_substantive_signoff` | **未削弱** — 原则 4 仍要求实质对照活页/否决权/禁代签；4b 只钉时序 |
| `one_seat_one_agent` | **未削弱** — principles / 骨架 / HardConstraints 仍强制真实子智能体 |
| `findings_wake_pm` | **未削弱** — fail→escalate+resume 开发；PM 镜仍保留 findings_wake_pm |

### 4. 互相矛盾 / 残留歧义

未发现「验收波并行签收」旧句。发现并**最小修补**两处歧义/缺口：

| # | 问题 | 修补 |
|---|------|------|
| A | `工程团队.md` 施工流水线「可测面就绪才执行」易读成与 4b 抢跑同义 | →「执行须等 UI+原型过签（UI in_scope；见 ui_prototype_gate_before_test）」 |
| B | 项目经理 `prompt_increment` 未点名门禁/`acceptance_gate_order`（UI/原型/测试已有） | 补 HARD 行：硬顺序 + 禁并行抢跑 + 汇审后才汇报 |
| C | `GuidanceWorkflowCatalog` surface description「验收UI+原型签收」过短；norm/required「before 交出」弱于「先于测试执行」；forbidden 缺显式抢跑 | description/norm/required 对齐硬顺序；forbidden 增「测试执行前双 pass」「汇审前禁汇报」 |

修补后无残留矛盾；**未**削弱原义、**未**新增 align-freeze 外义务。

---

## 抽检（原禁止/强制仍可执行）

- [x] UI/原型未双 pass → 禁测试执行  
- [x] UI/原型 fail → resume 开发，禁代签  
- [x] 测试 pass → 须 PM 汇审才汇报  
- [x] e2e 绿不代签 UI/原型/汇审  
- [x] 一席一智能体 / findings_wake_pm 仍可执行  

---

## verdict

**pass** — 硬顺序与红灯/执行分界一致；未削弱 `acceptance_substantive_signoff` / `one_seat_one_agent` / `findings_wake_pm`；歧义已最小修补。

paths_changed（本席）：

- `meetings/提示词优化-review.md`（本文件）
- `roster.md`（登记 agent_id）
- `dev/ai-command/ai/工程团队.md`（流水线一句）
- `app/code/Weline/Ai/Mcp/src/McpSkillCatalog.php`（项目经理 prompt_increment）
- `app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php`（description/norm/forbidden/required）

无需 escalate 项目经理。
