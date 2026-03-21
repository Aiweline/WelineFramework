# Theme runtime unification (codex-theme-runtime-unification)

- Started: 2026-03-21
- Plan: `dev/ai/plans/codex-theme-runtime-unification.plan.md`
- Status: completed

## Summary

- Runtime / partials / layout observer：`ThemeContextService::resolveTheme`、`resolveCurrentScope` 收敛 `Partials` Block、`TemplateFetchFile`、`ControllerFetchFileBefore` 中重复的预览 Session、URL、`scope_*` 逻辑。
- `AssetMerger`、`ConfigMerger` 在 `$theme === null` 时走 `ThemeContextService::resolveTheme`（与 HTTP 预览一致）。
- `ThemeConfigManager::normalizeArea` 委托 `ThemeContextService::normalizeArea`。
- `ThemeQueryProvider`：`getActiveTheme` / `scanThemeLayoutsByType` 使用 `ThemeContextService`；`is_active` 按区域激活字段计算。
- CLI `theme:active`：无参时输出前台/后台解析结果（`resolveTheme(..., allowPreview: false)`）及全局 `is_active`；激活支持第三参数 `frontend|backend|global`。
- 新增 `test/Unit/ThemeContextServiceTest.php`（纯逻辑，无 DB）。

## Verification

- `php bin/w setup:upgrade`（移除陈旧 `var/process/setup_upgrade.lock` 后成功）以刷新 `compiled_factories`。
- `php bin/w phpunit:run --module=Weline_Theme`：`ThemeContextService`、`AssetMergerOverride`、`ThemeFileOverride` 等相关用例通过；模块内仍有既有失败（Taglib::parse、部分 Contract Mock），与本次改动无关。

## Changed files (app)

- `Weline/Theme/Block/Partials.php`
- `Weline/Theme/Observer/TemplateFetchFile.php`
- `Weline/Theme/Observer/ControllerFetchFileBefore.php`
- `Weline/Theme/Helper/AssetMerger.php`
- `Weline/Theme/Helper/ConfigMerger.php`
- `Weline/Theme/Helper/ThemeConfigManager.php`
- `Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php`
- `Weline/Theme/Console/Theme/Active.php`
- `Weline/Theme/test/Unit/ThemeContextServiceTest.php`
- `Weline/Theme/i18n/zh_Hans_CN.csv`, `en_US.csv`
