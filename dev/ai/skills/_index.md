# Weline AI skill index

Routing only. Trigger conditions live in each skill's frontmatter `description`; workflow details live in that skill. Load the smallest set that covers the task, adding another skill only for a genuinely separate domain.

## Before selecting a skill

- Read `AI-ENTRY.md` and `dev/ai/global-constraints.md`.
- For `app/code/{Vendor}/{Module}` work, read `dev/ai/diagrams/08-module-docs-index.txt` and the owning module's `doc/AI-INDEX.md`.
- Inspect targeted source and existing verification evidence; do not treat skills as a substitute for current implementation facts.

## Routing

| Task signal | Skill |
|---|---|
| Detailed engineering plan, task cards, acceptance matrix, Plan mode | `planning` |
| Cross-cutting change boundary, validation evidence, repository hygiene | `通用工程师-开发规范与代码质量` |
| Module feature/requirement change, **doc/需求.md**, version progress in **doc/开发日志.md**, development closure, or operator handoff | `通用工程师-开发规范与代码质量` plus the owning domain skill |
| Framework internals, shared abstractions, DI/base behavior | `框架核心工程师-框架核心开发` |
| ORM, schema annotations, model persistence and query execution | `框架核心工程师-ORM与数据模型` |
| `w_query`, cross-module reads, provider discovery, `query:help`, QueryProvider descriptors | `unified-query-provider` |
| Controllers, custom URLs, ModuleRouter, events, Hooks, extends | `框架核心工程师-路由事件与扩展` |
| CLI commands, scaffolding, generators | `框架核心工程师-命令与代码生成` |
| Business module structure, controllers, menus, bounded features | `业务模块工程师-模块开发` |
| Services, orchestration, business rules | `业务模块工程师-服务层与业务逻辑` |
| Module config, cache, backend menu/permission wiring | `业务模块工程师-配置缓存与后台权限` |
| Theme inheritance, templates, layout, `app/design`, source tracing | `前端主题工程师-主题模板开发` |
| Blocks, widgets, Taglibs, reusable page sections | `前端主题工程师-组件与页面构建` |
| Browser business requests, `Weline.Api.*`, worker/query-bin, streams | `前端主题工程师-前端API交互` |
| Visual hierarchy, responsive behavior, interaction/UX quality | `ui-ux-pro-max` plus the owning frontend skill |
| Official Taglib/control selection or Taglib development | `framework-taglib-catalog` |
| Visitor Pixel markers and section/event attribution | `visitor-pixel` |
| i18n, translation files, user-visible messages | `通用工程师-国际化与用户提示` |
| WLS workers, lifecycle, reload/restart, process stability | `WLS运行时工程师-WLS进程稳定` |
| Session isolation, Session Server, SSE runtime | `WLS运行时工程师-Session与SSE运行时` |
| Weline Panel WLS performance diagnostics | `WLS运行时工程师-WLS面板性能诊断` |
| Weline Panel SEO diagnostics | `SEO面板诊断` |
| ACL, backend access and menu visibility | `安全权限工程师-ACL与后台安全` |
| Session/auth-area isolation and sensitive-state protection | `安全权限工程师-会话配置与数据保护` |
| Validation-layer selection or test authoring/running guidance | `testing` |
| Risk-based validation strategy | `QA测试主管-测试策略治理` |
| Evidence review and quality-gate decision | `QA测试主管-质量门禁验收` |
| Focused PHPUnit/Pest unit or integration tests | `单元测试工程师-单元测试覆盖` |
| Explicit fixture, dataset or regression-input design | `单元测试工程师-测试数据与回归` |
| Explicit Playwright/E2E flow work | `E2E自动化工程师-端到端流程测试` |
| Route, HTTP or lightweight UI smoke | `E2E自动化工程师-路由与UI冒烟验证` |
| CI/release readiness and gate enforcement | `CI发布工程师-CI与发布门禁` |
| Shell/PowerShell portability and command safety | `CI发布工程师-环境兼容与命令安全` |
| Weline_Deploy/Webhook/release-system work | `CI发布工程师-部署发布系统` |
| Exact passphrase 「分仓」 | `CI发布工程师-分仓发布` / `.codex/skills/fencang-release` |
| Exact passphrase 「分项」 | `CI发布工程师-分项更新` / `.codex/skills/fenxiang-update` |
| Exact passphrase 「回灌」 | `CI发布工程师-回灌验证`（须已给框架仓；修核→推送→update:core→验证） |
| Third-party payment provider integration | `payment-provider-development` |
| Queue diagnosis or operations | `queue` |
| SystemConfig scope behavior | `system-config-scope` |
| Module/README/API/architecture documentation | `文档知识库工程师-文档规范与变更记录` |
| Skill catalog, routing or knowledge structure | `文档知识库工程师-技能索引与知识库` |
| Confirmed lesson, user correction or rule deduplication | `文档知识库工程师-会话复盘与规则沉淀` |
| Live multi-agent dispatch, dependency sequencing, or edit-overlap coordination | `技术主管-任务拆分与调度` |
| Progress/evidence integration and first-line acceptance | `技术主管-一级验收与进度追踪` |

## Optional code-intelligence fallback

Use the matching `.claude/skills/gitnexus/*/SKILL.md` only when the user requests GitNexus or the primary project-intelligence path cannot satisfy a code task. It is not a second always-on rule layer.

## Supporting documents

- Agent roster: `dev/ai/agent/README.md`
- Team workflow: `dev/ai/skills/TEAM_WORKFLOW.md`
- Legacy-to-current names: `dev/ai/skills/ROLE_SKILL_BINDING.md`
