<!-- weline:module-ai-index:auto-generated -->
# Weline_Server AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Server/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Server/doc/README.md`
6. `app/code/Weline/Theme/doc/AI-INDEX.md`
7. `app/code/Weline/Frontend/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Server`
- 目录：`app/code/Weline/Server`
- Vendor：`Weline`
- Module：`Server`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Server/etc/backend/menu.xml`

- `Console`：php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。 文件数：31
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。 文件数：14
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：7
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：11
- `Plugin`：插件扩展点。变更前确认被拦截对象和执行顺序。 文件数：2
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：109
- `etc`：模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。 文件数：6
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：5
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：2
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：10
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：0

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 相关 PHP 文件：Test/Unit/Query/ServerQueryProviderHostsAddTest.php、Test/Unit/Query/SessionAndMemoryQueryProviderTest.php、extends/module/Weline_Framework/Query/MemoryQueryProvider.php、extends/module/Weline_Framework/Query/ServerQueryProvider.php、extends/module/Weline_Framework/Query/SessionQueryProvider.php；前端/跨模块读数据先查 query 帮助。

## doc 目录

- `app/code/Weline/Server/doc/Dispatcher分流架构设计.md`
- `app/code/Weline/Server/doc/IPC控制通道架构.md`
- `app/code/Weline/Server/doc/README.md`
- `app/code/Weline/Server/doc/SSE无阻塞检测方法.md`
- `app/code/Weline/Server/doc/WLS-DISPATCHER-IDLE-SELECT-WAKEUP-FIX-2026-07-05.md`
- `app/code/Weline/Server/doc/WLS-EventBuffer-SSL-Worker.md`
- `app/code/Weline/Server/doc/WLS-FINAL-REPORT-2026-04-02.md`
- `app/code/Weline/Server/doc/WLS-FIXES-2026-04-02.md`
- `app/code/Weline/Server/doc/WLS-Gateway使用指南.md`
- `app/code/Weline/Server/doc/WLS-HA-IPC-REDESIGN-IMPLEMENTATION-CHECKLIST.md`
- `app/code/Weline/Server/doc/WLS-ISSUES-2026-04-02.md`
- `app/code/Weline/Server/doc/WLS-Lifecycle-IPC-Hardening-2026-05-23.md`
- `app/code/Weline/Server/doc/WLS-MASTER-RESURRECT-QUEUE-SPIN-FIX-2026-07-05.md`
- `app/code/Weline/Server/doc/WLS-MASTER-SELF-HEAL-HA-DESIGN-2026-04-23.md`
- `app/code/Weline/Server/doc/WLS-SUPERVISOR-PHASE-1-SCOPE-2026-04-23.md`
- `app/code/Weline/Server/doc/WLS-Worker动态扩缩容架构设计.md`
- `app/code/Weline/Server/doc/WLS-Worker扩缩容用户手册.md`
- `app/code/Weline/Server/doc/WLS-default-startup-orchestration-fixes-2026-05-27.md`
- `app/code/Weline/Server/doc/WLS-master-ipc-call-chain.md`
- `app/code/Weline/Server/doc/WLS_Session共享服务架构.md`
- `app/code/Weline/Server/doc/WLS启动与关闭链路图.md`
- `app/code/Weline/Server/doc/WLS安全与规则配置推演.md`
- `app/code/Weline/Server/doc/WLS实例隔离机制.md`
- `app/code/Weline/Server/doc/WLS架构图.md`
- `app/code/Weline/Server/doc/WLS模式部署指南.md`
- `app/code/Weline/Server/doc/Windows-event扩展编译.md`
- `app/code/Weline/Server/doc/event/integration/security_rules_updated.md`
- `app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md`
- `app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md`
- `app/code/Weline/Server/doc/wls-panel-plan/20-plugin-tag-logic.md`
- `app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md`
- `app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md`
- `app/code/Weline/Server/doc/wls-panel-plan/76-wls-panel-plugin-ui-normalization-evidence.md`
- `app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md`
- `app/code/Weline/Server/doc/wls-panel-plan/78-appstore-demo-plugin-install-evidence.md`
- `app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md`
- `app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md`
- `app/code/Weline/Server/doc/wls-panel-plan/93-official-appstore-manifest-contract.md`
- `app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md`
- `app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md`
- `app/code/Weline/Server/doc/wls-panel-plan/97-humanized-redesign-prototype.md`
- `app/code/Weline/Server/doc/wls-panel-plan/98-humanized-redesign-atomic-workplan.md`
- `app/code/Weline/Server/doc/wls-panel-plan/99-plugin-native-shell-embedding-evidence.md`
- `app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-local-development.json`
- `app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-production-default.json`
- `app/code/Weline/Server/doc/开发/plan.md`
- `app/code/Weline/Server/doc/开发/pluggable-subprocess-architecture.md`
- `app/code/Weline/Server/doc/开发/session-entry-migration-checklist.md`
- `app/code/Weline/Server/doc/开发/ssl-dynamic-restore-plan.md`
- `app/code/Weline/Server/doc/开发/task.md`
- `app/code/Weline/Server/doc/计划-AI建站-w_query与本机hosts.md`
- `app/code/Weline/Server/doc/证书管理Hook集成.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Server> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
