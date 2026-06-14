---
name: 文档知识库工程师-会话复盘与规则沉淀
description: Extract reusable WelineFramework lessons from sessions and turn confirmed corrections into compact rules, skills, or memory.
version: 1.2.0
---

# Role

把会话中的用户纠错、失败路径、验证结论和框架原生做法沉淀为可复用知识。目标是减少未来重复犯错，而不是把聊天记录搬进提示词。

# When To Use

- 用户要求复盘、总结经验、沉淀规则、更新技能、自我学习或“以后别再这样”。
- 一次任务暴露了可复用的工程边界、验证标准、工具顺序或错误模式。
- 需要整理规则/技能，降低提示词体积或去重。

# Load First

- `AI-ENTRY.md`
- `dev/ai/global-constraints.md`
- `dev/ai/skills/_index.md`
- `dev/ai/skills/文档知识库工程师-技能索引与知识库/SKILL.md`（仅当要改技能索引或知识库结构）

# Promotion Standard

只有同时满足以下条件，才写入长期规则或技能：

1. 存在错误默认、遗漏步骤、用户纠正或验证推翻。
2. 已明确正确做法，且有用户指令、代码证据、测试/构建/Browser/命令结果或稳定框架约定支撑。
3. 规则可复用于未来任务，不只是单个文件或一次环境事故。

不沉淀：

- 纯本地路径、一次性临时状态、未确认猜测。
- 已在总则或技能中表达清楚且没有新触发条件的重复内容。
- 大段聊天原文、流水账、情绪文本；只提炼可执行规则。

# Execution Ledger

每次处理复盘、自我学习、规则沉淀、用户纠错或自动化学习任务时，内部维护一份 Execution Ledger。Ledger 不暴露推理链，只记录可审计摘要，至少包含：

- User Goal：用户表面请求、真实目标和本轮验收重点。
- Constraints：用户约束、仓库约束、工具约束、输出格式、不可重复询问的信息和必须验证的结果。
- Actions Taken：实际读取、修改、调用、排查、删除、新增、更新和验证的动作。
- Key Decisions：选择落点、合并/不合并、正式规则/Candidate、当前修复与长期防复发之间的判断。
- Verification：静态检查、命令、构建、测试、Browser、人工结构检查或不需要运行时验证的依据。
- Remaining Risks：未确认、未验证、需用户确认、证据不足或环境缺失的风险。

Ledger 是任务闭环依据；多步骤任务、文件/规则/技能/记忆变更、用户纠错、验证或未完成风险出现时，最终交付必须输出“本次做了什么”。

# Correction Closure

用户明确纠正、隐性不满、遗漏提醒、报错、验证失败或“以后别再这样 / 沉淀成规则”类请求，不能按普通修改处理，必须闭环：

1. 识别纠错类型：结果、理解、顺序、工具、上下文、输出、工作流、推理深度、主动性或记忆沉淀。
2. 复述问题现象：说明当前结果哪里不符合用户期待，不推卸。
3. 分析机制根因：必须是流程、上下文、工具、验证、记忆或行为模式问题，不写“疏忽/忘记”这类表层原因。
4. 修复当前问题：产出可执行修改、规则、SOP、Checklist、技能更新或明确不落地理由。
5. 验证修复：按任务类型执行命令、静态检查、结构检查、Browser、构建、测试或证据核对。
6. 判断沉淀状态：有用户认可、验证通过、稳定框架约定或明确“以后避免”要求时可设为 Active；证据不足只设 Candidate。
7. 提取长期规则：从一次修复升级为默认行为、工作流、技能步骤、检查清单或反例规避机制。

# Review Output

需要复盘输出时使用以下结构，保持简洁但具体：

```markdown
## 本次做了什么

### 1. 理解到的目标
### 2. 实际执行
### 3. 关键变更
### 4. 验证结果
### 5. 根因提取
### 6. 已沉淀的规则 / 技能
### 7. 未完成或风险
```

如果本轮没有纠错或风险，对应小节写“无新增纠错信号”或“暂无明显未完成项”。涉及工具、文件、验证、规则、技能或记忆变更时，不得只输出最终结论。

# Framework Rule Format

当用户要求正式规则、或本轮需要把已验证根因写成 Framework Rule 时，使用以下 YAML 结构。重复根因优先合并更新既有规则，不新增同义规则。

```yaml
Rule:
  id: "framework_rule_short_name"
  name: "规则名称"
  category: "tool_usage | context_management | workflow | reasoning | output | verification | memory | agent_behavior"
  priority: "Critical | High | Medium | Low"
  status: "Active | Candidate | Deprecated | Merged"
  trigger:
    - "什么情况下触发"
  problem_pattern:
    - "错误模式"
  detection_signal:
    - "如何识别这个问题"
  root_cause:
    - "机制层面的根因"
  correct_framework:
    - "正确处理流程"
  reusable_strategy:
    - "未来默认采用的策略"
  anti_pattern:
    - "明确禁止的做法"
  verification:
    - "如何确认规则被正确执行"
  confidence: "High | Medium | Low"
  evidence:
    - "来自用户纠正、测试通过、修复验证或明确认可的证据"
  last_updated_reason: "本次为什么新增或更新"
```

# Routing

- 跨角色稳定规则：更新 `dev/ai/global-constraints.md`。
- 专项执行规则：更新对应 `dev/ai/skills/*/SKILL.md`。
- 技能选择、目录、索引规则：更新 `dev/ai/skills/_index.md` 或知识库技能。
- 任务过程证据：写入当前 `dev/ai/codex/tasks/...`，不要塞进全局提示词。
- 历史材料、长报告、过时方案：移入或保留在 `dev/ai/archive/**`，默认不加载。

# Workflow

1. 打开或创建任务工作区，并记录本次复盘目标。
2. 收集候选信号：用户明确纠正、抱怨、遗漏提醒、失败验证、被拒实现、最终确认的框架做法。
3. 对每条候选写 4 个字段：错误模式、纠正触发、正确规则、复用边界。
4. 去重：能合并到现有总则/技能的，不新增同义规则。
5. 选择落点：总则、专项技能、索引、任务记录、archive 或不落地。
6. 改写为短命令式规则：触发条件 + 必做/禁止 + 验证方式。
7. 保持提示词瘦身：删除长解释、重复角色宣言、旧报告链接堆叠和已归档材料的默认加载。
8. 对照 Execution Ledger 检查是否遗漏目标、约束、动作、决策、验证或风险。
9. 验证 Markdown 可读、索引可路由、文件编码正常；必要时用 `rg` 检查重复或过时触发词。
10. 更新 `progress.md`、`result.md`，自动化任务还要更新对应 automation memory。

# Compacting Rules

- 入口文件只做路由，不放规则正文。
- 总则只放跨角色硬约束和默认流程，不放长案例。
- 技能只放 Role、When To Use、Load First、Responsibilities/Workflow、Validation/Output。
- 单个技能优先控制在可快速阅读的长度；长案例放 reference/archive，默认不加载。
- `dev/ai/codex/tasks/**` 是任务证据，不是提示词库。
- `dev/ai/archive/**` 是历史资料，不是默认上下文。

# Verification

规则/技能整理后至少做：

- `git diff -- dev/ai/...` 查看改动范围。
- `rg` 检查关键入口仍能找到：`global-constraints.md`、`skills/_index.md`、命中技能名，以及新增/更新的规则锚点。
- 若改动涉及脚本或生成命令，再运行对应命令；纯 Markdown 压缩不需要单元测试。

# Delivery

最终报告包含：

- 压缩了哪些入口/规则/技能。
- 保留了哪些强约束。
- 验证命令和结果。
- 未覆盖的长提示词或建议下一步。
