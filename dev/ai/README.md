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

按场景参见 **skill-trigger-reminders**；常用技能包括：

- **code-generation-standards** — 模块结构、框架边界
- **module-development** — register、Schema、Setup、路由、event.xml
- **theme-development** — 主题变量、CSS 命名空间、JS 闭包、禁止硬编码颜色
- **database-model-standards** — #[Col]/#[Table]、链式 fetch、setup:upgrade
- **friendly-notifications** — 禁止 alert/confirm/prompt，BackendToast/BackendConfirm
- **unified-query-provider** — 模块间查询 w_query()、FrameworkQueryService
- **extension-points** — 事件、Hook、Extends 定义与实现（合并原 create-event/hook/extends/implement-extends）
- **testing** — PHPUnit、http:req、前端 E2E、QA（合并原 php-unit-testing、http-request-testing、frontend-automation-testing、quality-assurance）
- **planning** — 计划前分析、创建计划、完成后校验、审计（合并原 create-plan、pre-plan-analysis、post-plan-completion-check、plan-code-auditor）
- **frontend-components** — Block、Taglib、Widget、DataTable（合并原 block/taglib/generate-component/datatable-component）
- **config-and-env** — 配置与 PHP 扩展（合并原 config-management、php-extension-dependency）
- **runtime-and-process** — 进程与 WLS（合并原 process-management、weline-server）
- **context-compression** — 上下文压缩省 Token（必加载 alwaysApply）
- **cursor-as-reference** — 规则与技能编辑位置（dev/ai）
- 其余见 `skills/` 下各目录的 SKILL.md
