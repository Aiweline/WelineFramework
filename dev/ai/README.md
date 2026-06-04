# AI 规则与技能

`dev/ai` 是仓库 AI 规则、技能、智能体名录和任务记录目录。默认只加载入口和命中技能，避免历史资料占用上下文。

## 默认入口

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/skills/_index.md`
4. 命中的 `dev/ai/skills/*/SKILL.md`

## 目录边界

| 路径 | 默认加载 | 用途 |
|---|---:|---|
| `global-constraints.md` | 是 | 唯一全局规则 |
| `skills/_index.md` | 是 | 技能路由 |
| `skills/*/SKILL.md` | 按需 | 专项技能 |
| `agent/README.md` | 按需 | 智能体名录 |
| `diagrams/` | 按需 | 架构图谱和模块文档索引 |
| `codex/tasks/**` | 否 | 单任务过程证据和恢复记录 |
| `archive/**` | 否 | 历史资料、旧规则、旧计划 |
| `plans/**` | 否 | 长期计划，仅任务相关时读取 |

## 维护规则

- 入口文件只做索引，不复制规则正文。
- 总则只放跨角色硬约束和默认流程。
- 技能只放触发条件、边界、流程和验证要求。
- 长案例、历史报告、迁移记录放 `archive/**` 或任务目录，默认不加载。
- 新增技能后只更新 `skills/_index.md` 的路由，不把技能全文搬到索引。

`AI-开发与测试指南.md` 是扩展参考资料，不作为默认入口；如与 `global-constraints.md` 冲突，以 `global-constraints.md` 为准。
