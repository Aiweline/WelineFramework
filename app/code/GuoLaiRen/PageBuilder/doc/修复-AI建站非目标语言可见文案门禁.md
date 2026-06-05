# 修复-AI建站非目标语言可见文案门禁

## 问题

AI 建站 Stage-3 组件生成中，`page_goal`、`block_goal`、`story_goal` 等字段用于向 AI 传递当前 block 的规划上下文，但不应该作为访客可见文案输出。

在 `content_locale=pt_BR` 的站点中，曾出现葡语标题下渲染中文 block 目标说明的情况，例如“聚合核心价值、特色内容、信任信息和主要行动入口”。根因不是这些规划字段不该传递，而是生成后的可见 HTML 没有强制按目标语言校验：`assertRenderedHtmlMatchesLocale()` 为空实现，硬策略也没有按目标 locale 拦截 CJK 可见文案。

## 修复

- `AiSitePageComponentGenerationService` 在 content 组件 `html_content` 进入硬策略检查时，按渲染上下文解析目标内容语言。
- 对非中日韩目标语言，硬策略拒绝有意义的 CJK 访客可见文本。
- 最终 PHTML 渲染为 HTML 后再次执行语言门禁，防止中文通过默认配置、PHP 变量或 AI 直接硬编码进入页面。
- `page_goal` / `block_goal` 仍作为 AI 上下文保留，但只能用于理解 block 意图，不能作为可见内容通过门禁。
- Stage-1 方案构建 prompt 与 Stage-2 build 任务上下文必须显式携带当前网站默认语言：`site_default_language`、`content_locale` 与 `language_contract`。方案中的主题、导航、页级 block、`field_plan.sample`、SEO、CTA、alt/title/aria/placeholder 等候选访客文案都必须按该语言生成或在 build 前重写。

## 验证

- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePageComponentGenerationService.php`
- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePlanJsonGenerationService.php`
- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePlanJsonTaskService.php`
- `php -l app/code/GuoLaiRen/PageBuilder/test/Unit/Service/AiSitePageComponentGenerationLocaleTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/GuoLaiRen/PageBuilder/test/Unit/Service/AiSitePageComponentGenerationLocaleTest.php`
- 针对 virtual theme #568 通过 `AiSiteAgentWorkspacePreviewService` 渲染 5 个 `pt_BR` 页面，结果为 0 个有意义 CJK 命中、0 个 placeholder 命中。
