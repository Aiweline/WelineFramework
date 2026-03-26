# AI 规则与技能（主仓）

`dev/ai` 是仓内 AI 约束、技能与任务记录的主目录。

## 当前结构（整理后）

- `skills/`：唯一有效技能源（按场景触发）
- `skills/_index.md`：唯一技能路由表（Single Source of Truth）
- `codex/`：任务工作区与过程记录
- `agents/`：agent 协议与配置
- `scripts/`：脚本工具
- `archive/`：历史/兼容归档（不再作为主入口）

归档目录包括：`codex-skills/`、`rules/`、`plans/`、`my-skills/`、`tools/`、`local-lm-studio-to-cursor-switch/`、`docs/`。

---

## 约束文档（必读）

| 文档 | 说明 |
|------|------|
| **AI-开发与测试指南.md** | 合并版：角色与原则、框架方法验证、ORM/路由、测试（http:request/浏览器 MCP）、常犯错误与检查清单、监督与质量、前端响应式协议（PC/iPad/Mobile） |
| **global-constraints.md** | 全局硬性禁止（generated、error_log、alert/confirm/prompt、硬编码文案、Upgrade 做字段 CRUD、routes.xml 等） |

---

## 规则目录说明

`.mdc` 规则已迁移到 `archive/rules/` 作为历史兼容资料；当前主流程请以：

- `CLAUDE.md`
- `global-constraints.md`
- `skills/*/SKILL.md`

为准。

---

## 技能一览（skills/）

先看 **skill-trigger-reminders**，再按场景只读取命中的技能正文，不要批量读取全部 `skills/*/SKILL.md`。

- 映射入口：`skills/skill-trigger-reminders/SKILL.md`
- 完整开发技能映射（唯一索引）：`skills/_index.md`
- Codex 任务工作区：`skills/codex-task-workspace/SKILL.md`

## 新增技能

1. 在 `dev/ai/skills/<skill-slug>/SKILL.md` 创建技能，目录名使用小写英文加连字符。
2. 在 `skills/_index.md` 增加场景关键词映射。
3. 同步更新 `skills/skill-trigger-reminders/SKILL.md`（如有触发逻辑变化）。
4. 历史兼容入口 `skills/skill-trigger-reminders/references/development-skill-map.md` 仅保留跳转说明，不再维护表格正文。
