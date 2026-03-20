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
| **AI-开发与测试指南.md** | 合并版：角色与原则、框架方法验证、ORM/路由、测试（http:request/浏览器 MCP）、常犯错误与检查清单、监督与质量、前端响应式协议（PC/iPad/Mobile） |
| **global-constraints.md** | 全局硬性禁止（generated、error_log、alert/confirm/prompt、硬编码文案、Upgrade 做字段 CRUD、routes.xml 等） |

---

## 规则一览（rules/）

| 规则文件 | 说明 |
|----------|------|
| code-generation-rules.mdc | 代码生成强制规则、前端/CSS/JS/通知规范、禁止 generated/ |
| cursor-as-reference.mdc | .cursor 仅引用，主仓在 dev/ai |
| skill-trigger-reminders.mdc | 场景→技能触发表（计划、CSS/JS、通知、模块间查询、Session 等） |
| error-prevention-event.mdc | 事件 dispatch/数据格式/触发顺序 |
| wls-state-management.mdc | WLS static 状态须注册 StateManager 重置 |

---

## 技能一览（skills/）

先看 **skill-trigger-reminders**，再按场景只读取命中的技能正文，不要批量读取全部 `skills/*/SKILL.md`。

- 映射入口：`skills/skill-trigger-reminders/SKILL.md`
- 完整开发技能映射：`skills/skill-trigger-reminders/references/development-skill-map.md`
- 常驻压缩：`skills/context-compression/SKILL.md`
- 规则/技能/计划仓定位：`skills/cursor-as-reference/SKILL.md`
