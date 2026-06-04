---
name: 文档知识库工程师-会话复盘与规则沉淀
description: Extract reusable WelineFramework lessons from sessions and turn confirmed corrections into compact rules, skills, or memory.
version: 1.1.0
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
8. 验证 Markdown 可读、索引可路由、文件编码正常；必要时用 `rg` 检查重复或过时触发词。
9. 更新 `progress.md`、`result.md`。

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
- `rg` 检查关键入口仍能找到：`global-constraints.md`、`skills/_index.md`、命中技能名。
- 若改动涉及脚本或生成命令，再运行对应命令；纯 Markdown 压缩不需要单元测试。

# Delivery

最终报告包含：

- 压缩了哪些入口/规则/技能。
- 保留了哪些强约束。
- 验证命令和结果。
- 未覆盖的长提示词或建议下一步。
