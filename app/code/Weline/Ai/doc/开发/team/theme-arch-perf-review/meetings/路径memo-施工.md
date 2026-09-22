# 路径 memo 施工纪要

- 日期：2026-09-22
- 席位：Team:主题开发工程师:（`work_mode=theme_module_runtime`）
- 依据：`align-freeze.md` P1「模板路径解析 rememberForRequest / HotCache（deps=theme）」

## 做了什么

- `ThemePathResolver::resolveThemeFile` 入口：`themeId + 规范化 modulePath` → `StorefrontScopeHotCache::rememberForRequest('theme.path.resolve', …)`，builder 调用原 DirectoryResolver + 继承链 `is_file` 逻辑。
- 无 `Context::hasCurrent()` / 无 `themeId`：直接原逻辑，不碰 HotCache。
- **仅请求内 memo**（RequestContext）；本波不写 `rememberPolicy` / 进程 L1 / 跨 Worker 共享池。
- `TemplateFetchFile` 已注入并调用同一 `ThemePathResolverInterface`，无平行扫盘。

## 未做（冻结 / 下一波）

- 未改 design 继承优先级、Preview 三态、未恢复 theme_runtime IPC、未大拆 Editor。
- Catalog / getAreaDirectories HotCache、发布失效收窄：仍属 align-freeze 下一波。

## 验证

- Unit：`ThemePathResolverRequestMemoContractTest`（source 含 `rememberForRequest`；同请求二次 resolve builder 仅 1 次）。
