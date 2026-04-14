# Skills Index (Single Source of Truth)

本文件是 `dev/ai/skills` 的**唯一技能路由索引**。  
历史上分散在多处的路由表已合并到这里，后续只维护这一份。

## 使用规则

1. 先按任务关键词匹配技能。
2. 只读取命中的 `SKILL.md`，不要批量读取全部技能。
3. 同时命中多个场景时，只保留最相关的 1~3 个技能。
4. 若命中技能正文继续引用其他技能或 reference，再按需读取。

## 全局入口技能

| 技能 | 说明 |
|---|---|
| weline-framework-core | 框架核心开发，Guardrails 硬约束，开发工作流 |
| weline-framework-runtime | WLS 运行时、进程、Session Server、状态管理 |
| weline-framework-skill-router | 混合任务路由，根据关键词跳转对应技能 |

## 常驻

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| 计划、拆任务、pre-plan、plan.md、task.md、审计、都做完了 | planning | `dev/ai/skills/planning/SKILL.md` |
| dev/ai/codex、ACTIVE.md、任务状态、任务进度、任务工作目录、resume、result.md | codex-task-workspace | `dev/ai/skills/codex-task-workspace/SKILL.md` |

## 开发场景映射

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| PHPUnit、http:req、`php bin/w e2e:run`（UI/E2E/冒烟）、QA、Browser MCP、Playwright、路由验证 | testing | `dev/ai/skills/testing/SKILL.md` |
| 事件、dispatch、event.xml、Hook、Extends、extends.php | extension-points | `dev/ai/skills/extension-points/SKILL.md` |
| Sticker、Weline_Sticker、w:sticker、贴纸、非侵入改模板、sticker:collect、sticker:refresh | weline-sticker | `dev/ai/skills/weline-sticker/SKILL.md` |
| WLS、Worker、server:start、reload、restart、static、State、Processer | runtime-and-process | `dev/ai/skills/runtime-and-process/SKILL.md` |
| Session、登录态、认证、SessionFactory、AreaConfig | session-development | `dev/ai/skills/session-development/SKILL.md` |
| 主题、前端、phtml、CSS、JS、模板、静态标签、暗色适配 | theme-development | `dev/ai/skills/theme-development/SKILL.md` |
| tpl、view/tpl、编译模板、模板被覆盖、源模板定位、com_ 前缀模板 | template-source-editing | `dev/ai/skills/template-source-editing/SKILL.md` |
| Block、Taglib、Widget、DataTable、`<w:d-table>` | frontend-components | `dev/ai/skills/frontend-components/SKILL.md` |
| 翻译、i18n、`__()`、`<lang>`、`@lang`、i18n csv | i18n-internationalization | `dev/ai/skills/i18n-internationalization/SKILL.md` |
| alert、confirm、prompt、toast、确认弹窗、用户提示 | friendly-notifications | `dev/ai/skills/friendly-notifications/SKILL.md` |
| Model、ORM、`#[Col]`、`#[Table]`、pagination、SQL、列表分页 | database-model-standards | `dev/ai/skills/database-model-standards/SKILL.md` |
| QueryProvider、w_query、模块间查询、查询型接口 | unified-query-provider | `dev/ai/skills/unified-query-provider/SKILL.md` |
| menu.xml、ACL、`#[Acl]`、权限、菜单显示 | acl-permission-system | `dev/ai/skills/acl-permission-system/SKILL.md` |
| env.php、SystemConfig、配置项、PHP 扩展、requirements | config-and-env | `dev/ai/skills/config-and-env/SKILL.md` |
| 模块开发、register.php、Backend Controller、setup:upgrade、event.xml | module-development | `dev/ai/skills/module-development/SKILL.md` |
| Service、业务逻辑层、Api 接口、Controller 与 Model 之间 | service-development | `dev/ai/skills/service-development/SKILL.md` |
| 缓存、CacheFactory、CacheInterface、cache:clear | cache-usage | `dev/ai/skills/cache-usage/SKILL.md` |
| 调试日志、Debug::env、agent_log、caller key、过滤日志 | debug-logging | `dev/ai/skills/debug-logging/SKILL.md` |
| 文档、开发文档、使用文档、变更记录 | documentation-standards | `dev/ai/skills/documentation-standards/SKILL.md` |
| 创建命令、CommandAbstract、command:upgrade、Console | create-framework-command | `dev/ai/skills/create-framework-command/SKILL.md` |
| PHP 8.4、Property Hooks、null 安全、array_find、mb_trim | php84-performance | `dev/ai/skills/php84-performance/SKILL.md` |
| 代码生成、strict_types、ObjectManager、生成代码风格 | code-generation-standards | `dev/ai/skills/code-generation-standards/SKILL.md` |
| Windows 引号、PowerShell、exec、proc_open、命令转义 | windows-command-quoting | `dev/ai/skills/windows-command-quoting/SKILL.md` |
| 路由、URL、getUrl、getBackendUrl、404、405、语言币种 | weline-routing | `dev/ai/skills/weline-routing/SKILL.md` |
| SSE、EventSource、text/event-stream、流式输出、terminal.start | sse-streaming | `dev/ai/skills/sse-streaming/SKILL.md` |
| PageBuilder、style、layout、`@fields`、head-common、footer-common、data-glr | pagebuilder-style-templates | `dev/ai/skills/pagebuilder-style-templates/SKILL.md` |
| visitor、pixel、`<pixel>`、`weline-pixel::`、UV、PV | visitor-pixel | `dev/ai/skills/visitor-pixel/SKILL.md` |
| 网站转模板、克隆设计、HTML 转组件、外部站点转 PageBuilder | website-to-template | `dev/ai/skills/website-to-template/SKILL.md` |

## 补充资料

| 场景/关键词 | 资料 | 路径 |
|---|---|---|
| 社区模块、社区插件、社区模块开发汇总 | community-module | `dev/ai/skills/community-module/SKILLS-CONSOLIDATED.md` |

## 迁移说明

- 旧文件 `skills/skill-trigger-reminders/references/development-skill-map.md` 已改为跳转入口。
- 旧的 Codex 专用路由文档已归档到 `dev/ai/archive/codex-skills/`。
