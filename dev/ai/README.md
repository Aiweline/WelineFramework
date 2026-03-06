# AI 规则与技能（主仓）

本目录为 Cursor 规则与技能的**主存储位置**，`.cursor/rules`、`.cursor/skills`、`.cursor/plans` 通过目录联结引用此处。

- **rules/** — 始终应用的规则（.mdc）
- **skills/** — 按场景触发的技能（各技能目录下的 SKILL.md 等）
- **plans/** — 总计划文件（.plan.md）

编辑时请在本目录修改。

---

## 约束文档（必读）

| 文档 | 说明 |
|------|------|
| **AI 提示词.md** | 角色定义、框架方法验证、必读文档、回答原则 |
| **AI-常犯错误.md** | 代码生成常犯错误与正确写法（模块注册、数据库、接口、XML、路由等） |
| **AI-前端.md** | 前端相关约束（若有） |
| **AI-监督智能体.md** | 监督/审计相关说明（若有） |

---

## 规则一览（rules/）

| 规则文件 | 说明 |
|----------|------|
| code-generation-rules.mdc | 代码生成强制规则、前端/CSS/JS/通知规范、禁止 generated/ |
| cursor-as-reference.mdc | .cursor 仅引用，主仓在 dev/ai |
| skill-trigger-reminders.mdc | 场景→技能触发表（错误修复、计划、CSS/JS、通知、模块间查询、Session 等） |
| auto-update-skills-on-error.mdc | 错误修复后必须更新 ERROR_LOG、COMMON_ERRORS、相关技能 |
| error-prevention-event.mdc | 事件 dispatch/数据格式/触发顺序 |
| wls-state-management.mdc | WLS static 状态须注册 StateManager 重置 |

---

## 技能一览（skills/）

按场景参见 **skill-trigger-reminders**；常用技能包括：

- **code-generation-standards** — 模块结构、框架边界
- **module-development** — register、Schema、Setup、路由、event.xml
- **theme-development** — 主题变量、CSS 命名空间、JS 闭包、禁止硬编码颜色
- **database-model-standards** — #[Col]/#[Table]、链式 fetch、setup:upgrade
- **friendly-notifications** — 禁止 alert/confirm/prompt，BackendToast/BackendConfirm
- **unified-query-provider** — 模块间查询 w_query()、FrameworkQueryService
- **create-event** / **create-hook** / **create-extends** — 事件、Hook、扩展点
- **error-learning** / **error-tracking** — 错误记录与知识库更新
- **create-plan** / **plan-code-auditor** / **post-plan-completion-check** — 计划与审计
- **cursor-as-reference** — 规则与技能编辑位置（dev/ai）
- 其余见 `skills/` 下各目录的 SKILL.md
