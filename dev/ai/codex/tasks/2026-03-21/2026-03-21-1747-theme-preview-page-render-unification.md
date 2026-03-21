# Task: Theme preview page render unification

- Started: 2026-03-21 17:47
- Status: in_progress
- Related plan: `dev/ai/plans/codex-theme-runtime-unification.plan.md`

## Goal

统一 `Weline_Theme` 的后台布局预览、前台 `preview_theme` 预览和实际页面渲染入口，让它们共用页面类型识别、布局选择、默认 JSON 布局数据和生命周期 CSS/JS 合并逻辑。

## Context

- 用户要求 `theme/backend/theme-editor/layout-preview` 不再只渲染裸 `default.phtml`，而是遵循 Theme 主题 JSON 中的默认布局信息。
- 用户要求 `index/index?preview_theme=11` 这类地址也能像后台 `layout-preview` 一样，根据当前页面自动匹配到对应布局。
- 用户特别指出除了 `app/design/WeShop/motor/frontend/layouts/homepage/default.phtml` 之外，其它页面类型也要补齐。
- 当前 `Weline/Theme` 工作区存在大量未提交修改，尤其是运行时统一相关改动；本任务需要在现有修改基础上继续推进，不能回滚用户已有变更。

## Findings

- `Controller/Backend/ThemeEditor::getLayoutPreview()` 仍在直接 `fetch` `theme/{area}/layouts/{type}/{option}.phtml`，绕过了统一页面解析与布局应用链路。
- `Controller/Router::rewritePreviewThemeQuery()` 目前只写入预览上下文，尚未把 `preview_theme` 请求导向统一的页面渲染入口。
- `Service/ThemePreviewEntryApplication`、`Service/ThemeContextService`、`Observer/LayoutSlotRenderer`、`Service/ThemeLayoutService` 已提供可复用基础，可作为统一收口点继续扩展。

## Progress

- 2026-03-21 17:47 已完成启动上下文读取、技能选择与任务记录初始化。
- 2026-03-21 17:47 已锁定本轮优先排查文件：`ThemeEditor.php`、`Router.php`、`ThemePreviewEntryApplication.php`、`ThemeContextService.php`、`LayoutSlotRenderer.php`、`ThemeLayoutService.php`。

## Next

- 设计统一的页面预览应用服务，明确 page type / layout option / status / area / theme 的解析来源。
- 实现后台与前台预览入口收口，覆盖 homepage 之外的页面类型。
- 对修改后的 PHP 文件执行语法检查，并补充任务结果与恢复说明。
