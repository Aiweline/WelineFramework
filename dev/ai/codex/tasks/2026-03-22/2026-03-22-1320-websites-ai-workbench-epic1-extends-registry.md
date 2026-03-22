# Websites AI工作台 Epic 1：扩展契约与 Registry

- Started: 2026-03-22 13:20
- Finished: 2026-03-22 14:10
- Status: completed
- Related Plan:
  - `dev/ai/codex/AI工作台/Websites-AI建站工作台-任务拆解.task.md`
  - `dev/ai/codex/AI工作台/Websites-AI建站工作台-接口草图.md`

## Goal

从 Epic 1 开始落地 `Weline_Websites` AI 建站工作台基础设施：

1. 为 `Weline_Websites` 增加 `AiSiteBuilderProvider` 与 `WebsiteThemeSource` 扩展契约
2. 新增 provider / theme source registry
3. 先落一个内置默认 provider，确保 registry 可发现 `websites_default`
4. 补齐单元测试与最小验证

## Scope

- 已修改：
  - `app/code/Weline/Websites/extends.php`
  - `app/code/Weline/Websites/Api/*`
  - `app/code/Weline/Websites/Service/AiWorkbench/*`
  - `app/code/Weline/Websites/extends/module/Weline_Websites/*`
  - `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/*`
  - `dev/ai/codex/AI工作台/Websites-AI建站工作台-进度.md`
- 本轮未进入：
  - session / message / event / artifact 持久化
  - 控制器 / UI / SSE
  - PageBuilder provider 迁移
  - Theme 真正主题源接入实现

## Decisions

1. `provider_code` 对应完整流程 provider，不是工具列表。
2. `Weline_Websites` 不直接感知 `PageBuilder` 私有字段。
3. registry 优先复用 `ExtendsData` 注册表，不自行扫描业务目录。
4. 主题源真实接入留在后续 Epic；本轮先把契约与 registry 搭起来。
5. 为了让单测可控，`ExtendsData` 静态读取被收口到 `ExtensionPointReader`。

## Progress Log

- 2026-03-22 13:20
  - 读取 SOUL / USER / daily memory / ACTIVE / 规划文档。
  - 选择本轮 repo skills：`extension-points`、`service-development`、`testing`。
  - 对照 `Weline_Websites`、`Weline_Seo`、`Weline_Framework` 现有 registry 模式收集实现参考。
- 2026-03-22 13:35
  - 先写 registry 单元测试，固定排序、启用过滤、无效实现过滤、缓存行为与空扩展点行为。
- 2026-03-22 13:55
  - 新增基础契约、registry interface/factory、`ExtensionPointReader`、两个 registry 与内置 `websites_default` provider。
- 2026-03-22 14:10
  - 完成语法检查、定向单测、`setup:upgrade` 和 generated registry 校验。

## Changed Files

- `app/code/Weline/Websites/extends.php`
- `app/code/Weline/Websites/Api/AiSiteBuilderProviderInterface.php`
- `app/code/Weline/Websites/Api/WebsiteThemeSourceInterface.php`
- `app/code/Weline/Websites/Api/AiSiteBuilderProviderRegistryInterface.php`
- `app/code/Weline/Websites/Api/AiSiteBuilderProviderRegistryInterfaceFactory.php`
- `app/code/Weline/Websites/Api/WebsiteThemeSourceRegistryInterface.php`
- `app/code/Weline/Websites/Api/WebsiteThemeSourceRegistryInterfaceFactory.php`
- `app/code/Weline/Websites/Service/AiWorkbench/ExtensionPointReader.php`
- `app/code/Weline/Websites/Service/AiWorkbench/ProviderRegistry.php`
- `app/code/Weline/Websites/Service/AiWorkbench/ThemeSourceRegistry.php`
- `app/code/Weline/Websites/extends/module/Weline_Websites/AiSiteBuilderProvider/WebsitesDefaultProvider.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ProviderRegistryTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ThemeSourceRegistryTest.php`

## Verification

- `php -l` 已通过：
  - 新增/修改的 Websites 契约、registry、默认 provider、测试文件、`extends.php`
- `php vendor/bin/phpunit app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ProviderRegistryTest.php app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ThemeSourceRegistryTest.php`
  - 结果：`4 tests / 13 assertions`
  - 说明：断言全部通过，但 PHPUnit 因环境级 warning（无 code coverage driver）返回非零退出码
- `php bin/w setup:upgrade -m Weline_Websites --yes`
  - 结果：成功
  - 说明：`generated/extends.php` 已收录 `AiSiteBuilderProvider/WebsitesDefaultProvider.php`

## Outcome

1. `Weline_Websites` 现在已经拥有 AI 建站 provider 与主题来源的扩展契约。
2. provider / theme source registry 已可复用，并具备基础缓存与排序行为。
3. 内置 `websites_default` 已能通过扩展机制被发现。
4. Theme source 真正实现仍待后续 Epic 接入。

## Next

1. 继续主线做 Epic 2：session / message / artifact / event 持久化。
2. 或插入做 Epic 6 前半段：给 `Weline_Theme` 增加真实 `WebsiteThemeSource` 实现。
3. 等 Websites 主流程跑通后，再接 `PageBuilderProvider`。
