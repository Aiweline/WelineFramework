# 性能检查-design — 主题开发架构

| 字段 | 值 |
|------|-----|
| slug | theme-arch-perf-review |
| seat | Team:性能检查工程师: |
| date | 2026-09-22 |
| work_kind | 设计检查（双轨·本波仅 design） |
| architect_joint | pending（须与 Team:架构师: 同会冻结方向） |
| stance | **可接受需优** |
| mcp | prepare_project ok；`get_skill(performance_check)` 本会话 DISABLED（索引禁用）→ 宿主权威 `dev/ai-command/ai/性能检查.md` + `统一缓存范围与性能优化.md` |
| client_session_id | theme-arch-perf-20260322 |
| readiness_id | ready-1790050248125-65ddc0aa37d6876e |

## 业务特性摘要

- **读多写少**店面渲染；写侧为主题发布 / scoped Release / 编辑器草稿。
- **多 website/store/channel**；布局结构与语言无关，chrome/nav HTML 依赖 lang（及部分 currency）。
- **个性化/草稿**：预览三态（editor_mode / Token / 正式）禁止进公共 FPC；draft/target 不进布局结构共享缓存。
- 热路径：主题身份 → 资源发现 → layout 解析 → Slot 填充 → chrome/partial → FPC。
- Owner：`Weline_Theme`；协调器 `StorefrontThemeCacheCoordinator`；失效 `ThemeRuntimeCacheCleaner`。

## 框架结构映射

| 段落 | 机制 |
|------|------|
| 主题身份 | `ThemeContextService` → `CachePolicy theme.published_binding.v1` + 请求内 Model 副本 |
| 资源发现 | `ThemeDirectoryResolver` + `ThemeResourceCatalog`（进程实例袋；发布经 Cleaner clearCache） |
| 布局解析 | `LayoutResolveService`（event，轻量）+ `ThemeScopedPreviewResolver` 结构/I18N 分层 |
| Slot | `SlotRendererService`：结构 HotCache + 进程 L1；`runtimeCacheGet/Set` 恒空（禁 theme_runtime IPC） |
| chrome/nav | `Partials` + `StorefrontHeaderNavFragmentCache` → `StorefrontScopeHotCache` |
| 预览旁路 | `ThemeEditorFpcBypassProvider` 侧车规则 |
| 失效 | namespace `theme` + `clearAllThemeRelatedCaches`（宽） |

## 缓存合规结论

| 项 | 结论 |
|----|------|
| 布局结构 CachePolicy `vary:[]` + channel scope | PASS（对齐 `layout-slot-cache-keys.md`） |
| published snapshot / binding / chrome / nav | PASS（走 HotCache + dependencies） |
| 草稿/target 不进结构共享 | PASS |
| 预览 FPC bypass | PASS |
| ThemeResourceCatalog / DirectoryResolver 进程袋 | **异议**：有 Cleaner 挂钩，但非 CachePolicy/代次；跨 Worker 无 L2，冷 Worker 重复扫盘 |
| Slot/chrome 进程 L1 代替 theme_runtime | **可接受**（文档化 IPC 回归规避；结构已升 HotCache） |
| 平行进程内袋新建 | **禁止**；后续优化必须落 HotCache/Policy |

## findings（须与架构师共定制后施工）

见父会话正文 P0/P1/P2 与优化方向 ≤7。

## escalate

`@项目经理：请立刻组队解决` — 本席 stance=可接受需优；有 P1 项需架构师+主题席对齐后纳入 deps，禁止本席私排施工。

suggested_seats: 架构师, 主题开发工程师
