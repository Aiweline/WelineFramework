# AI 规则与技能（主仓）

`dev/ai` 是仓内 AI 总则、技能、智能体名录与任务记录的主目录。

## 当前结构

- `global-constraints.md`：唯一 AI 总则与全局约束（Single Source of Truth）。
- `skills/`：唯一有效技能源，按角色和场景组织。
- `skills/_index.md`：唯一技能路由表，只负责技能路由。
- `agent/`：智能体 Markdown 名录，包含指令和 Skill 绑定。
- `codex/`：任务工作区与过程记录。
- `agents/`：agent 协议与配置。
- `scripts/`：脚本工具。
- `archive/`：历史兼容资料，不再作为主入口。

归档目录包括：`codex-skills/`、`rules/`、`plans/`、`my-skills/`、`tools/`、`local-lm-studio-to-cursor-switch/`、`docs/`。

---

## 总则文档（必读）

| 文档 | 说明 |
|------|------|
| `global-constraints.md` | 唯一 AI 总则与全局约束，包含对抗性思维、反顺从原则、并行拆分、WLS 安全、开发禁令和验证底线。 |

`AI-开发与测试指南.md` 是扩展参考资料，不作为总则入口；如与 `global-constraints.md` 冲突，以 `global-constraints.md` 为准。

---

## 规则目录说明

历史 `.mdc` 规则已归档到 `archive/rules/`。当前规则总入口只有一个：

- `global-constraints.md`

`skills/*/SKILL.md` 只写专项技能独有规则；跨角色共识规则必须回到 `global-constraints.md` 维护。

---

## 智能体名录

- 入口：`dev/ai/agent/README.md`
- 每个智能体文件固定包含“指令”和“Skill”两部分。
- `Skill` 必须指向 `dev/ai/skills/*/SKILL.md`。
- 所有工程智能体必须遵守 `global-constraints.md`，并加载 `通用工程师-开发规范与代码质量` 作为共识技能。

## 技能一览（skills/）

先看 `global-constraints.md`、`skills/_index.md` 与 `agent/README.md`，再按场景只读取命中的技能正文，不要批量读取全部 `skills/*/SKILL.md`。

- 智能体名录：`agent/README.md`
- 完整开发技能映射：`skills/_index.md`
- 团队协作流程：`skills/TEAM_WORKFLOW.md`
- Codex 任务工作区：`codex/`

## 新增技能

1. 在 `dev/ai/skills/{智能体或角色}-{技能名}/SKILL.md` 创建技能。
2. 在 `skills/_index.md` 增加角色和场景关键词映射。
3. 如新增或调整智能体，在 `dev/ai/agent/{智能体}.md` 中更新“指令”和“Skill”绑定。
4. 专业技能必须包含 Shared Collaboration Contract，并指向 `通用工程师-开发规范与代码质量`。
5. 专业技能不得复制总则正文；跨角色规则统一维护在 `global-constraints.md`。
