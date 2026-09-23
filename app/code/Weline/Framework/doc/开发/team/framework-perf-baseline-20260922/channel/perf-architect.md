## msg-1 | 2026-09-22T11:35:00+08:00 | from:性能检查工程师 | to:架构师 | thread:perf-architect | kind:stance

框架级检查：verdict=fail（冷路径未达标）。缓存机制设计大体合规；CachePool getMultiple 仍为逐项循环；本机 HTTPS 首页冷 TTFB≈2.36s / 暖≈0.014s；列表冷≈2.30s / 暖≈0.009s。优化方向草案见 meetings/性能检查-design.md。请联合冻结。

## msg-2 | 2026-09-22T11:36:00+08:00 | from:架构师 | to:性能检查工程师 | thread:perf-architect | kind:stance

stance: 同意（机制主路径合规；实现缺口共修，不否决 HotCache 体系）。
cache_compliance: CachePolicy+scope/vary/dependencies 合规；多 Worker 仅允许带 epoch 路径；有条件异议：I18n 全清、Slot runtimeCache stub、Theme 布局未完整挂 Policy、部分写入口未走 w_changed。
joint_optimization_directions:
1. 批量预取正式入口（prefetchWords / 真 MGET·MSET；禁 DB N+1→RPC N+1）
2. 商品/目录事实与聚合分层（独立 namespace + 正式 bump；禁删依赖假 HIT）
3. Theme 已发布布局/Slot 进 HotCache（替换空 stub 私有袋；预览草稿禁入）
4. I18n 定向失效（global/i18n generation；收敛全清广播）
5. 搜索读模型收口（校验后切正式 alias；direct 须约束 scope）
forbiddens: 业务 parallel static；无 epoch 进程袋；可变/个性化进共享池；假 HIT；跨样本伪加速；跨模块直调捷径；单方定「加缓存」。

## msg-3 | 2026-09-22T19:31:34+08:00 | from:架构师 | to:性能检查工程师,主题,后端 | thread:perf-architect | kind:stance | re:wave8-8a2

stance: **固化模板硬口径冻结**（覆盖「再缓存 LayoutSlot/header」主解）。
storefront: 直接加载 published bake；header/chrome 须 bake 进壳；禁 runtime SlotFiller/injectChrome。
regen_windows_only: editor publish · 注入收集（default_injection / 有部件必入）。
hotcache: 种袋/header·chrome_slot **降辅**，不得代替固化直读。
权威: channel msg-95 · meetings/wave8-8a2-solidified-template.md · surfaces.md。

## msg-4 | 2026-09-22T19:53:30+08:00 | from:架构师 | to:性能检查工程师,主题,Product | thread:perf-architect | kind:stance | re:wave9-9a

stance: **A 轴 total 面冻结**（固化主门保持关账）。
P0: `theme.storefront_head`→9s · `product.card.render`→9p。
P1: dict_prefetch / partials.head / category_nav（后排）。
forbiddens: 回退 shell 直读；fill/injectChrome 布局再生；8c\* 种袋代布局；删功能语义；平行 static；自 reload。
允许: Policy / 片段缓存 / 批渲染 / 非首屏延迟。
权威: channel msg-103 · meetings/wave9-9a-a-axis.md · surfaces.md wave9。

## msg-8 | 2026-09-22T12:15:00+08:00 | from:主题开发工程师 | to:性能检查工程师,架构师 | thread:perf-architect | kind:facts

work_mode=theme_module_runtime（审查运行时，本回合未改码）
thoroughness=very_thorough

### 1) 每请求扫盘 / 次数 / remember·HotCache（店面主链 vs 编辑器）

| 组件 | 每请求扫盘？ | 量级 | 缓存 |
|------|-------------|------|------|
| ThemeDirectoryResolver | 冷首次：`is_dir`×(链深×≤3候选 + 全模块 view/theme/area) | O(chain+modules) | 进程内 `$themeChainCache`/`$areaDirectoriesCache`；**无** HotCache |
| ThemeResourceCatalog | 冷首次：`RecursiveDirectoryIterator`+`file_get_contents`/meta 全树 | O(目录层×文件) | 实例 `$rawCache/$resolvedCache/$slotCache`；**无** HotCache |
| ThemePathResolver + TemplateFetchFile | **是**：每个模板 `is_file` 沿链 | O(模板数×链深) | **无** remember/HotCache；目录列表可沾 DirectoryResolver 实例袋 |
| LayoutResolveService | 否（仅 event） | — | — |
| LayoutResolveObserver | 末路 1–2×`is_file`（仅默认模块 layouts 根） | O(1) | 无 |
| ProductLayoutResolveService | 无盘；`optionExists`→Catalog.getLayouts 可触发全树扫 | miss 时重 | Catalog 实例袋 |
| SharedChromeService | 编辑态 workspace 语义；非店面扫盘 | — | workspace/Request memo |
| StorefrontThemeCacheCoordinator | Policy 定义 only | — | — |
| StorefrontHeaderNavFragmentCache | 否（HTML fragment） | — | **HotCache** `rememberPolicy`（channel+lang/currency） |
| StorefrontFpcWarmer | 非请求路径；warmup HTTP | ≤8 paths | FPC 池 |
| AssetMerger/Deduplicator | 扫目录；**生产请求未引用**（仅 UT） | — | 无 |
| LayoutAssetsManager | 只读 URL/磁盘路径定位；禁止请求期写编译产物 | `is_file` 于 Gateway miss | 编译期产物 |
| PreviewContextService | **非**每请求重扫主题树；`themeSupportsArea` 最多 3×`is_dir`（ensureThemeIds/fallback） | O(1) | 无目录树 cache |
| SlotRenderer getLayoutData | 结构：**HotCache** `publishedLayoutStructurePolicy` + 进程 L1；草稿/page target 禁入 | 1×结构/键 | R3 已落地 |
| 店面 LayoutSlotRenderer | **hard cut** Entity SlotFiller；不走 processSlots/getLayoutData | bake/entity | ConfigStore/Pointer/Snapshot HotCache（既有） |
| ThemeContextService binding | — | — | HotCache `theme.published_binding.v1` + `rememberForRequest` model |

### 2) design 继承链长度成本

- `getAreaDirectories`：每层最多 3 次 `is_dir`；链越长冷开销线性升。
- `ThemePathResolver` miss：每层 `is_file`（+偶发 fallback）并 `load(parent)`；链越长模板解析越贵。
- `ThemeResourceCatalog`/`ThemeComponentCatalog`：按链层收集文件/定义，子主题 logical_key 覆盖；链×文件数放大冷扫描。
- `WelineTheme::getThemeChain`：Model `_cache`（`theme_chain_v2_{id}`）已挡重复 DB；DirectoryResolver 再滤 area 时仍付 `is_dir`。

### 3) Slot / 部件注入 N+1

- 店面：Entity 填槽按 bake 的 slot→widgets **串行 SSR**（每 widget 一次渲染）；hard cut **禁止**请求期 default_injections bake。
- 残留：`shellMissingRequiredInjections` 可拉 `ThemeComponentCatalog::getDefinitions`（实例袋，冷仍走链+文件源）；overlay 二次扫描 HTML。
- 编辑器/预览：`processSlots` 对 N widgets 逐个 `doRenderWidget`；结构 HotCache 不覆盖 widget HTML；进程 `$widgetOutputCache`，`runtimeCacheGet` **恒 null stub**（刻意避 theme_runtime IPC）。

### 4) CSS/JS 合并缓存

- `AssetMerger`：运行时无缓存、**不在店面热路径**（仅测试调用）；合并=路径去重列表，非写盘。
- 店面资产：`LayoutAssetsManager`/`ThemeResourceGateway` → **编译/deploy 产物** `pub/static`；请求只定位/按需 publish，不重合并。

### 5) 主题席「架构可优化且不伤能力」建议（5）

1. TemplateFetchFile/ThemePathResolver：`rememberForRequest` 或 HotCache（deps=`theme`）缓存 `modulePath+themeId→absPath`，去掉每模板 O(chain) `is_file`。
2. ThemeResourceCatalog 冷扫描升格 CachePolicy HotCache（禁 draft/editor 个性化键），服务 `optionExists`/编辑器打开。
3. ThemeDirectoryResolver `getAreaDirectories` 结果跨 Worker HotCache（theme+area），降多 Worker 冷 `is_dir`×模块。
4. ThemePathResolver 回退链复用 `getThemeChain` 数据，避免逐层 `ObjectManager::make+load`。
5. Entity 路径：`getDefinitions`/required-injection 检查 HotCache 化或 bake 已完备时短路；widget SSR 数据批预取归业务/i18n 席，主题不平行私袋。

### 6) stance（对性能席联合方向）

| # | 方向 | 主题席 |
|---|------|--------|
| 1 | 真批量 MGET / prefetchWords | **同意（消费方）**；chrome/标签词受益；Owner 非 Theme |
| 2 | 目录/商品事实分层 | **同意**；禁 Theme 平行袋 |
| 3 | 已发布布局/Slot HotCache | **同意且 R3 已 patched**；残余=widget SSR/HTML 体积（B4） |
| 4 | I18n 定向失效 | **同意（消费方）** |
| 5 | Search + FPC deferred 闭环 | **同意**；`StorefrontFpcWarmer` 已贡献 `/` 与 products；Host/adopt 归后端 Runtime |

禁区对齐：不业务 parallel static；草稿不进共享结构池；不大重构/不碰 generated。
