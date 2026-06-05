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

## 2026-06-05 补充修复：默认语言选择被方案语言覆盖

### 问题

AI 建站工作台的 `pb-ai-default-locale` 表示网站访客可见内容语言，`pb-ai-plan-locale` 表示方案语言选项。此前 Stage-1 的语言解析把 `plan_locale` 放在 `default_locale` 之前；如果后台当前语言是中文，用户把默认语言切换为阿拉伯语但没有同步修改方案语言，Stage-1 仍会以 `zh_Hans_CN` 生成 `plan_json.content_locale`，随后 Stage-2 block 任务又优先读取旧 `plan_json.content_locale`，导致最终网站可见内容仍是中文。

### 修复

- Stage-1 方案生成语言优先读取 `ai_content_locale` / `selected_content_locale` / `selected_locale` / `default_locale` / `website_profile.default_locale`，仅在这些内容语言为空时才把 `plan_locale` 作为后备。
- Stage-1 prompt 的 `site_default_language` 使用同一内容语言优先级，避免 prompt 中继续把方案语言当成访客语言。
- Stage-2 `plan_json` 任务上下文优先读取当前 scope/website profile 的内容语言，再读取旧 `plan_json.content_locale`，防止历史中文计划覆盖用户新选择的默认语言。

### 验证

- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePlanJsonGenerationService.php`
- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePlanJsonTaskService.php`
- `php vendor/bin/phpunit --no-coverage app/code/GuoLaiRen/PageBuilder/test/Unit/Service/AiSitePlanJsonTaskServiceTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/GuoLaiRen/PageBuilder/test/Unit/Service/AiSitePageComponentGenerationLocaleTest.php`
- 临时 PHP 验证：当 `default_locale=ar_SA`、`plan_locale=zh_Hans_CN`、旧 `plan_json.content_locale=zh_Hans_CN` 时，Stage-1 locale、Stage-1 `site_default_language`、Stage-2 task `runtime_context.content_locale` 与 `language_contract.source_of_truth_locale` 均解析为 `ar_SA`。

## 验证

- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePageComponentGenerationService.php`
- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePlanJsonGenerationService.php`
- `php -l app/code/GuoLaiRen/PageBuilder/Service/AiSitePlanJsonTaskService.php`
- `php -l app/code/GuoLaiRen/PageBuilder/test/Unit/Service/AiSitePageComponentGenerationLocaleTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/GuoLaiRen/PageBuilder/test/Unit/Service/AiSitePageComponentGenerationLocaleTest.php`
- 针对 virtual theme #568 通过 `AiSiteAgentWorkspacePreviewService` 渲染 5 个 `pt_BR` 页面，结果为 0 个有意义 CJK 命中、0 个 placeholder 命中。
