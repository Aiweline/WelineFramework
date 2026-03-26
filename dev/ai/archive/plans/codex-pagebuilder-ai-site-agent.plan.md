---
name: Codex Recovered Plan - PageBuilder AI Site Agent
overview: Recover the unfinished AI site-agent implementation plan from Codex session logs.
source_session: C:\Users\17142\.codex\sessions\2026\03\21\rollout-2026-03-21T13-09-29-019d0ecc-6631-7e63-9426-e30dec56a91b.jsonl
source_timestamp: 2026-03-21T05:52:40.297Z
status: in_progress
isProject: true
todos:
  - id: codex-pagebuilder-ai-site-agent-1
    content: Inspect current module wiring and insertion points
    status: completed
  - id: codex-pagebuilder-ai-site-agent-2
    content: Add persistent models and services for sessions, events, virtual themes, and site publishing
    status: completed
  - id: codex-pagebuilder-ai-site-agent-3
    content: Extend the style, theme, and rendering pipeline for virtual database-backed themes
    status: completed
  - id: codex-pagebuilder-ai-site-agent-4
    content: Add backend controllers, APIs, SSE endpoints, and the admin menu or page
    status: completed
  - id: codex-pagebuilder-ai-site-agent-5
    content: Implement the frontend page with scope persistence and stage-driven UI
    status: in_progress
  - id: codex-pagebuilder-ai-site-agent-6
    content: Run targeted verification and fix integration issues
    status: in_progress
---

# Codex Recovered Plan - PageBuilder AI Site Agent

## Recovery Note

Recovered from the last `update_plan` found in the source session. This reflects the plan state in the session log and has not been revalidated against the current repository.

## Progress (2026-03-21)

- **会话与事件落库**：`GuoLaiRen/PageBuilder` 新增 `AiSiteAgentSession`、`AiSiteAgentSessionEvent` 与 `AiSiteAgentSessionService`（`public_id`、scope JSON、阶段、站点 ID、`weline_theme_id`、发布状态、事件流）。虚拟部件本身沿用 `Weline\Theme` 的 `ThemeComponent` + `VirtualThemeComponentSource`；会话表通过 `weline_theme_id` 关联主题层。
- **模块版本**：`register.php` → `1.0.31`，依赖 `Weline_Theme`。
- **虚拟主题渲染管线（todo 3）**：
  - `PageBuilderThemeComponentBridge`：`ThemeComponentCatalog` + 组件 code 归一化，解析 `Weline_Theme::theme_component`（含 `source_type=virtual`）。
  - `PageRenderService::render(..., ?int $welineThemeId)`：在 `renderRegionComponents` 中文件与 `Component` 模型均未命中时，用 `ThemeComponentRenderer` 渲染虚拟部件。
  - `ComponentRenderer::renderSingle` 支持选项 `weline_theme_id` / `theme_component_area`，供局部刷新 API 走同一路径。
  - 后台 `Preview::full` / `stylePreview` 已支持查询参数 `weline_theme_id`。
- **会话 API + SSE（todo 4）**：`AiSiteAgent` 增加 `getStateJson`、`postMergeScope`、`postReplaceScope`、`postSetStage`、`postBindLinks`、`getStreamSse`；服务层增加 `replaceScope`、`listEventsAfterId`、`getLatestEventId`；工作台模板支持阶段/绑定/Scope 编辑、预览链接（`scope.preview_page_id` + 会话 `weline_theme_id`）、`<w:theme:sse-terminal>` 订阅增量事件。
- **可视化与 Visual API 透传 `weline_theme_id`（2026-03-21 续）**：`Visual/Api/Component::postPreview` 在带 `weline_theme_id` 时优先 `ComponentRenderer::renderPreview`（虚拟部件 + 回落文件）；`postAdd` 局部渲染时透传 `weline_theme_id` / `theme_component_area` 与页面 `style_settings`；`ComponentRenderer::renderPreview` 支持合并 `$extraOptions`；`visual_config.phtml` / `visualConfig.phtml` 预览 iframe 与 `refreshPreview` 追加 `weline_theme_id`（GET 或 assign）；`component_panel` 组件预览 POST 附带 `window.visualWelineThemeId`。模块版本 `register.php` → `1.0.27`。
- **虚拟部件进面板与 Slot 放行（2026-03-21）**：`SlotValidator::canPlace` / `canPlaceInRegion` / `canPlaceInSlot` 在带 `weline_theme_id` 时用 `PageBuilderThemeComponentBridge` 解析 `ThemeComponentDefinition` 元数据；`getComponentsForBuilder` 支持 `weline_theme_id` + `theme_component_area`，将 `sourceType=virtual` 的部件按布局区域并入 `by_region`（与已有 code 归一化去重），分组标签「主题虚拟部件」；`component/list` GET 透传；侧栏 `component_panel` 经 `visual_weline_theme_id` 带参请求列表；`postValidate` 同步传参。
- **兼容查询与 slots API 虚拟化透传（2026-03-21）**：`compatible()`/`slots()` 支持 `weline_theme_id` 与 `theme_component_area`，并透传到 `SlotValidator::getCompatibleComponentsForRegion` / `getCompatibleComponentsForSlot` / `getComponentSlots` / `resolvePlacementComponentInfo`；虚拟主题组件可被 `component/compatible` 与 `component/slots` 正确返回。`register.php` → `1.0.31`。
- **建站向导 UI（todo 5 进行中）**：`workspace.phtml` 增加八阶段 Pill 导航 + 分步表单（简报/域名/等域名/虚拟主题/页面类型/内容/可视化/发布），字段经 `postMergeScope` 写入约定 scope 键（如 `site_title`、`target_domain`、`page_types[]`、`preview_page_id`）；快捷链至快速建站、域名管理、建站智能体、网站构建器、网站列表；原 Scope JSON 折叠为「高级」`<details>`。
- **向导内 AI 与域名状态（2026-03-21）**：`postAiGenerateBrief` 使用 `AiService::generate` + `AiResponseJsonParser` 写入 `site_title` / `site_tagline` / `brief_description`；`postQueryDomainStatus` 调用 `QuickBuildAggregator::getDomainLifecycleStatus`，结果 JSON 展示于域名与「等域名」步骤，并记 `domain_status` / `ai_brief` 事件。工作台 assign `ai_module_available` 控制 AI 按钮显隐。
- **发布前检查 + 自动轮询（2026-03-21）**：`postPublishChecklist` 汇总 `website_id`/`weline_theme_id`/`preview_page_id`/`target_domain`/域名状态并记 `publish_check` 事件；向导增加「执行发布前检查」输出面板与「自动轮询（10秒）」开关（域名阶段与等待阶段共享状态输出）。
- **todo 6 自动化验证（2026-03-21）**：直接执行 `vendor/phpunit` 跑 `GuoLaiRen_PageBuilder` 套件；修复 `SlotValidatorTest::testSlotPlacement_Valid` 无断言风险，结果 `34 tests / 99 assertions / 4 skipped / 1 deprecation`，无失败与风险。
- **todo 6 联调阻塞记录（2026-03-21）**：WLS 已启动并可访问（`https://127.0.0.1:9981`）；`/pagebuilder/backend/aiSiteAgent/index` 未登录态返回 302 到后台登录页，说明路由与 ACL 链路正常。当前 CLI 无可用后台登录 session，暂无法在命令行完成会话态 API/SSE 端到端验证。
- **下一步（todo 5/6）**：发布一键化、域名轮询自动化与 todo 6 验收。

## Original Explanation

已经完成现有渲染、域名、建站、SSE 与布局链路的落点确认，开始进入第一批实际改动：先搭建会话/事件/虚拟主题/发布服务基础设施，再接入渲染与后台界面。
