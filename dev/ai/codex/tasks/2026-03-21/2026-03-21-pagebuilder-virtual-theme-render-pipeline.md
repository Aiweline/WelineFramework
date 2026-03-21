# Task: PageBuilder 虚拟主题渲染管线（计划 todo 3）

- Date: 2026-03-21
- Plan: `dev/ai/plans/codex-pagebuilder-ai-site-agent.plan.md`
- Status: completed

## Changes

- `Service/Theme/PageBuilderThemeComponentBridge.php` — 按 `weline_theme_id` + 组件 code 解析 `ThemeComponentDefinition`
- `Service/PageRenderService.php` — `render(..., ?int $welineThemeId)`；`renderVirtualThemeComponentHtml`；`renderWelineThemeId` 在 try/finally 中恢复
- `Service/Component/ComponentRenderer.php` — `weline_theme_id` 优先虚拟渲染
- `register.php` — `1.0.25`，依赖 `Weline_Theme`
- `Preview.php` — 已存在/对齐 `weline_theme_id` GET 参数

## Verify

- `php -l` on modified PHP files — OK

## Next

- todo 4：API + 菜单 + Visual `renderSingle` 调用处传入会话的 `weline_theme_id`
