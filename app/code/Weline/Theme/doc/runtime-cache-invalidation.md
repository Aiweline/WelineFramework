# Theme 运行时缓存失效契约

`ThemeRuntimeCacheCleaner` 负责主题切换、布局发布和后台模式变更后的定向失效。
请求链路不得因为其他 WLS 实例、失联 IPC 或持久化实例清单而阻塞数秒。

## 派生 PHTML 的失效入口

布局固化的当前契约以 [布局固化与默认注入](./布局固化与默认注入.md) 为准。编辑或注入变更先生成并完整替换受影响 PHTML，再由 BakeCoordinator 调用 `clearLayoutEntityCaches(themeId, canonicalScope, storeMode)` 推进该产物 owner 的 Theme 展示代际。普通页面访问只选择模板源，不动态生成产物、不补播种部件位置，也不读取渲染 HTML 快照。

`canonicalScope` 不编码 storeMode；保存及插件重固化必须从实际产物版本传入模式，不能仅凭 storageScope 重建后默认使用 normal。Store／Channel 的 normal、test、dev 各自推进独立命名空间；Website／Global 仍沿用其不区分模式的命名空间。

失效范围以实际发布版本的 owner 为准，不能从访问域名推断。访问站点可能继承 Global owner 的版本；该版本更新必须推进 `global/storefront/theme`，仅推进访问站点或默认站自己的 Theme 代际不能替代此操作。全局推进只改变已有版本指纹，不等于清空缓存池、模板或数据库历史。

兼容布局保存入口也须先解析并固定实际版本，用同一版本的 owner 生成 PHTML 和推进展示代际。Channel 请求继承 Website 或 Global 的版本时，不得生成父 owner 的文件却只失效 Channel 的缓存。

验收同时检查正常缓存下首次访问和后续 FPC 命中。`no_cache` 仅用于区分渲染与缓存问题，其通过结果不能替代正常缓存验收。

## 既有发布、切换和兼容入口的失效范围

以下记录仍在代码中保留的 Scoped Release、主题切换及静态资源发布入口；不能据此要求每次派生 PHTML 保存清空全部缓存。旧入口与纯 PHTML 主链路的清理仍需逐项核对，不能把保留实现当成本次改造已完成的证明。

- **主题发布（编辑器 / Scoped Release）必须按当前发布主题所属 Scope 调用 `clearScopedCaches(scope, themeId)`**，使 storefront Theme 命名空间世代仅对该 Scope（及其后代向量）失效；禁止在已知 typed Scope 时用 `clearNonGlobalCaches(null)`（会跳过 `generated_theme_cache`）。
- Scoped workspace `publish` 成功后由 `ThemeScopedWorkspaceRequestService` 执行 `clearAllThemeRelatedCaches`（全量主题相关缓存）；`blocked`（结构冲突）不得清缓存，且 HTTP 必须 `success=false`。
- **店面世代 = 主题版本编码（硬）**：`themePublishedVersionId` = `ThemeScopeGenerationCode`（`t{theme}.{area}.{store}.{scopeFp}.v{n}`），由当前 Scope 已发布 `ThemeScopeVersion` 确定性算出。**只有**运营后台可视化编辑器点击「发布」（含回退到更旧密封版）才会换代；编译 / 固化 / `theme:update -f` / 清缓存 **禁止**旁路造新已发布版本，只读当前码写/重暖产物。版本未换则世代码不变。过渡期 `collectAcceptableStorefrontGenerationIds` 兼收遗留数字 `version_id`。
- **Formal Taglib 编译类（硬）**：`ThemeLayoutEntityFormalLocaleCompileService` 在阶段2/2 theme-head 合包预热之后**必须**调用 `clearAllThemeRelatedCaches(themeId, theme_layout_formal_compile_warm)`——CLI `theme:update`、编辑器发布、流水线 compile worker 凡走 `compileAfterPromote*` 同源清理 chrome/模板/FPC；禁止只写在 `Upgrade` 命令里。进程内 + `var/locks/theme-formal-compile-cache-clear` 短窗合并，避免同主题多 identity 工人 N× staging promote。CDN 失败软告警，不回滚已 promote 的 `com_*`。`clearAllThemeRelatedCaches` **永不**调用 `advancePublishedStorefrontGeneration*`。**CLI `theme:update -f`** 在 `cdn_full_page_purge` 失败时必须抛 `ConsoleException` 并非零退出（产物仍不回滚），禁止把「失败 N」打印成成功；边缘 HTML TTL 默认 300 秒 + `StaticCarryOver` 保留窗口是兜底，不替代真实 purge 能力。
- **CLI `theme:update` 默认是布局级失效（硬）**：固化切流传 `cache_invalidation=none`，随后按主题前端布局调用 `ThemeStorefrontSlugFpcPublisher::purgeLayoutsForThemeTargets()`，只清受影响页面的 FPC 叶子 + CDN URL（`impact.urls/slugs`），并只 bump 对应 Scope 的 Theme 世代。禁止默认走 `clearAllThemeRelatedCaches`、`deploy:mode:set prod` 或 Cloudflare `Purge Everything`。`-f` 才显式全量清，仅用于故障恢复；`setup:upgrade` 的 `runOnce` 仍保留历史全量清理（`cache_invalidation` 默认 `all`）。
- **主题更新不需要维护模式（2026-10-28 起硬）**：构建写批目录 / 批实体根；promote 只翻指针 + 把主题静态落到批次派生段（`ThemeUpdateBatchService::pinnedStaticBuildForBatch()`），promote **不再** `retireStaleThemePairDirs()`，旧段由 `theme:update gc` 回收；出站 `StaticAssetHtmlRewriter` 按指针段解析主题资源、模块资源仍走部署号。`setup:upgrade` 对已有代次指针的主题只 `ThemeUpdateRuntime::markDirty()`，构建时若 `var/process/setup_upgrade.lock` 被占用则跳过。**禁止**把 `theme:update` 放进 `maintenance:enable/disable` 窗内；flat 模式（`dev.static_flat_urls=true`）不支持零停机，属例外。
- **dev origin-watch（硬）**：`ThemeLayoutOriginWatchService` 在 solidify/chrome heal 之后用 `purgeLayoutsForThemeTargets` 只清**本次涉及布局** FPC（`impact.slugs`）。部件：账本 `layout_type`，否则快扫布局 `<w:widget module/type/name>`；主题未覆盖时监视 `__ext__/{Module}/…` 模块 phtml **与** `@widget.source` CSS/JS（`…/asset/*`；变更=assets 发布+部件级联 force_pages + `FpcStaticDiskStore::republishFromFileOnAllHosts` 刷新模块直链 live 镜像）。**另**：共享清单监视启用模块 `app/code/**/view/statics/**/*.{css,js}`（键 `__modstatic__/…`）→ **仅** `republishFromFileOnAllHosts`（禁止因此走主题 assets bake / 布局 FPC purge）。验收仍可叠 `_wb_nc`/`_weline_dev` 边缘打穿（Server 2.0.213）与内容哈希愈合作双保险。**禁止** origin-watch `clearAllThemeRelatedCaches` / `purgeAllLive`。全主题 bake / chrome heal 才展开 frontend 布局目录再按 slug 清。
- Scoped `publish` / `publishBatch` / `rollbackReleaseBatch` 必须在主库写事务内 `w_changed(theme|theme_layout)`；`ResourceChanged` 对 `theme` / `theme_layout`（及 Theme 模块 system_config）**必须** `clearAllThemeRelatedCaches`，不得仅 `clearScopedCaches`（否则 chrome/FPC/模板池残留旧 HTML）。例外：CLI `theme:update` 的布局级 `theme_layout` upsert 由 `ThemeRuntimeCapability` 按 `resourceScope` 走 `clearScopedCaches`，并只发受影响 URL 的 FPC/CDN purge（见上条）。
- `clearAllThemeRelatedCaches`：在 `clearNonGlobalCaches` 之上再执行
  1. `theme_namespace_generations_all` — bump 全部已记录的 `*/theme*` 命名空间 + `global/storefront/theme`
  2. ~~`fpc_cache_pools` / `wls_shared_fpc_full` / `wls_worker_broadcast_all`~~ — 2026-10-26 起移除：发布 = 构建号换代，旧世代预热窗口内继续服务（世代容忍），不做 FPC/Shared/worker 整包清理
  5. `cdn_full_page_purge` — 若存在 `Weline_Cdn`，**预热驱动逐 URL 刷新**（2026-10-26 修订，不再 purge everything）：`WarmupCollectService::collectAllProviders()` 把预热 URL 全量入队（`WarmupSend` 去重命中时重置 processed/target/status），由 `WarmupRunner` 逐条「先逐 URL 边缘 purge（`CachePurger::purge(domain,'urls',…)`，失败仅记日志保边缘旧世代）→ 再回源预热」。未预热 URL 继续吃边缘旧世代，由 `isAcceptableStorefrontGenerationId`（同 Scope 链最近 3 个 SEALED）兜底不 409。
- `clearAllThemeRelatedCaches` / `clearScopedCaches` / `clearNonGlobalCaches` 的 FPC 树路径为 **staging→暖→promote**（`FpcHtmlDiskStore::beginStagingGeneration` 种子自 live → `StorefrontFpcWarmer` / Orchestrator → **首页体 `republishHomepageIntoOpenStaging`** → `promoteStagingGeneration`）；**禁止**主题发版先 `purgeAll` 空切 live。暖请求须带 FPC pierce（`StorefrontWarmupHttpClient`）；**`needBody`（staging 重烤首页）另加 `x-wls-fpc-bypass: 1`，并拒绝 `X-Weline-Fpc: HIT` 回退下一 target**——仅 pierce 在部分边缘仍可能 HIT 旧页并把硬链 seed 写回 staging。FPC 池关闭时仍须显式 publish 进 staging。暖失败则 `abandonStagingGeneration`，live 保持旧树。外置 Shared 指针恒为 `generated/html/live/…`；遗留 `router-fpc-payloads` 仅 hydrate/GC。
- **`clearAllThemeRelatedCaches` 原子顺序（硬）**：先跑 `clearNonGlobalCaches(..., runStagingWarmPromote=false)` + Shared/process/CDN purge，**最后**再 `fpc_staging_warm_promote`。禁止 warm 之后再清 Shared 把新烤页打成空洞。promote 后须 **HTTP 访问验证**：每个 warm Host 访客路径 live 与同 Host Origin（pierce+bypass）比对。**空 `themePublishedVersionId` = 该 Scope 草稿/从未编辑器发布**（与 `ThemePublishedVersionRuntimeResolver`「空 id 跳过强制」同精神）→ live+origin 皆空或 origin 不可验证时软过（日志 `draft_never_published`）；仅当 origin 烤出非空而 live 空、或两边非空且不等 → fail-closed。**禁止** CLI 直调 `collectAcceptableStorefrontGenerationIds()` / 单一 `currentVersionId()` / 仅扫磁盘比对进程内 Scope 探针（Global 脏指针 vs Channel/Store 烤入会造成 setup:upgrade 假阴性）。见 Frontend [`店面世代与前端工作流.md`](../../Frontend/doc/架构/店面世代与前端工作流.md) §6.1。
- storefront chrome / header-nav CachePolicy 依赖必须含 `theme`，否则只 bump theme 代际不会让 chrome 信封失效。
- `clearScopedCaches` / `clearNonGlobalCaches` 必须清理编译模板缓存（`TemplateCacheManager`）、模块本地 `view/tpl/**` 编译产物，以及 `taglib`/`view` 池：`@static`/`<css>`/`<js>` 会在编译期把带 `?v=` 的 URL 写进这些产物，仅 bump generation 或只清 `var/cache/template` 无法让前台立刻吃到新 `theme_static_version`。
- `theme_static_version` 由 `Env::setConfig` 写盘后，WLS Worker 须在 `cache_clear` 中 `Env::reloadPersistentConfigFromDisk()`，否则其它 Worker 进程仍持有旧 Env，重编译会再次 bake 旧 `?v=`。
- ThemeEditor 兼容发布路径（`postPublish` / `publish-version` / `publish-and-exit`）在重建 generated theme cache 前后，通过 `flushFullPageCache($context, $themeId)` 走同一 Scope 定向失效。
- `clearScopedCaches` / `clearNonGlobalCaches` 必须整池清空 `weline_theme_storefront_chrome`（并 `Partials::clearAllCaches` / process reset）。仅 `forget(theme.chrome.header)` 无法匹配实际键 `theme.chrome.header.{sha1}`，嵌套部件（如 account 头像壳）会继续吐旧 HTML。
- `clearScopedCaches` / `clearNonGlobalCaches` 必须清理 `Partials` 进程缓存与 storefront chrome；否则页脚槽位发布后仍可能继续吐旧 chrome HTML。
- 遗留 `ThemeLayoutService::getLayout` 按 **slot 近优先** 合并 Scope 祖先链：Channel 只覆盖 `delivery` 时不得截断 Website 的 `footer-about-links` 等其它槽。
- FPM/CLI 没有 `WLS_INSTANCE` / `WLS_INSTANCE_NAME` 时，先清理当前进程和本地缓存，再由控制面在一个总 deadline 内并发通知正在运行的 WLS 实例。
- WLS Worker 存在当前实例名时，只清理该实例的 Shared State，并向该实例发送 cache epoch。
- 全实例广播只能读取持久化 endpoint 并并发尝试；禁止在请求内对每个历史实例串行执行端口/进程探测。
- Router 持久池通过 `Framework\Cache\CacheManager::pool('router')->clear()` 失效，不得实例化不存在的 RouterCache Factory。

## 前台发布回执

- `ThemePublishedVersionRuntimeResolver` 按请求 `ScopeIdentity` 祖先链解析已发布 `ThemeScopeVersion`；保证 Website 已发布时 HTML `themePublishedVersion*` 非空。`resolveGenerationIdentity()` 是店面世代权威（非系统 `deploy_version`）。
- **主题开发 ↔ 发布身份**：[`架构/主题开发与店面世代.md`](./架构/主题开发与店面世代.md)。
- **开发者维护前端勾连**：发布后如何 hole-punch / handshake / Worker 一对一身份，见 Frontend [`架构/店面世代与前端工作流.md`](../../Frontend/doc/架构/店面世代与前端工作流.md)。

## Chrome partial 输出缓存

- 后台/前台 chrome partial 的渲染结果只保存在当前 Worker 的有界 LRU 中；请求热路径不得为每个 head、topbar、sidebar 等 partial 调用 theme_runtime SharedState。
- 是否缓存以模板 @meta.cache 为权威；显式 mode=off 不得被类型默认值重新启用。
- cache key 必须绑定模板文件状态、area/type/option、主题与页面上下文；需要用户或角色隔离的 partial 在身份无法解析时直接绕过缓存，禁止落入共享的 unknown bucket。
- ProcessCacheResetter 的 hard reset 清空本进程 chrome LRU；Worker 启动预热只填充安全的 guest/公共上下文，登录用户/角色上下文在首次真实请求时填充。

## SlotRenderer 与已发布布局读模型

- 正常命中派生 PHTML 时，关系和参数来自模板内显式调用；随后走 **Taglib 编译** 得到 `com_*` 再执行。以下结构读模型说明仅适用于尚需原模板或编辑器数据的入口。
- 前台 `SlotRendererService` **不**缓存 layout.data / widget.output（无请求 L1、无进程 L1、无 theme_runtime get/set）。
- 插槽渲染 = 向 `ThemeRuntimeLayoutResolver` 取结构 → 普通部件模板/Component 渲染。
- 已发布非 target 布局结构 HotCache（`publishedLayoutStructurePolicy`）挂在 **`ThemeRuntimeLayoutResolver`**（读模型），不挂在 SlotRenderer。
- 主题发布仍可调用 `SlotRendererService::purgeRuntimeCacheNamespace()` 清理历史 `theme_runtime` 命名空间残留。
- 与 chrome partial 策略一致：跨 Worker 靠各自预热 / FPC，不以慢 IPC 换共享命中。

## ThemeData 运行时缓存

- `ThemeData` 请求热路径只维护进程内 L1；不得 `weline_site_runtime` get/set。
- 前台 head 不得在 `editor_mode` / `preview` / `visual_editor` 上 `clearProcessMemoryCache()`。进程 L1 只在显式 `theme_disk_refresh` 或发布 `clearCache()` 时清。
- 显式发布 / 配置整池失效仍可 `ThemeData::clearCache()`（含共享命名空间）。单条 `ThemeData::set` 只改已装入桶里的对应键，不整池清空。
- 编辑预览范围只保存改过的行。读链在预览范围之后回落 `default`。禁止在读路径按 `@meta::theme.{area}.` 前缀克隆词典。

## 前台已发布快照与请求描述符（2.2.262）

ThemeScopedWorkspace 的可选 readPublishedSnapshot 仅返回 payload/release_id/source_scope，复用原 requestLoadCacheKey 和 rememberRequestLoad 追踪。已有写入口 flushRequestLoadCache 同步清理轻读快照；无单独品牌缓存。事务内绕过共享请求快照，并删除实际访问的 workspace 行 memo，避免读到事务前指针或把事务内新值留下；祖先/default-locale 遍历传递同一开关，不清理无关编辑器状态。当前只做请求内复用，尚未将 appearance payload 发布到公共 L1/L2。

品牌读取优先通过 RequestContext::scopeIdentity 获取已经验证的身份，只有缺少身份时保留原 global 权威回退。模板后缀的中央请求 memo 包含 URI、实际显式预览参数、area/scope/locale/currency；不缓存 HTML、模型、Token 验证结果或文件新鲜度。

## Deadline

- WLS 请求 Fiber 内的 cache-clear IPC 等待上限为 `50ms`。
- FPM/Web 请求总等待上限为 `250ms`。
- CLI 控制面默认上限为 `2s`，用于可观测的显式命令。
- IPC 失败不得回滚已完成的本地失效；新 cache epoch 由后续控制面重试收敛。

## 性能回归

2026-07-12 的无 WLS 实例基线暴露了错误的全实例广播：后台模式同步超过 `40s`。
改为 endpoint 快读、总 deadline 并发与本地定向失效后，217 个历史实例文件的全局广播筛选为 `40.627ms`，
后台模式同步为 `57.536ms`，`ThemeRuntimeCacheCleaner` 本地阶段为 `59.542ms`，且所有步骤成功。

上线前还必须在独立 WLS 实例内验证：广播命中正确 instance，请求时延不超过预算，所有 Worker 的 FPC/Static L1 按 epoch 收敛。

2026-08-16 的后台性能修复已在独立 WLS 实例验证：Worker/Watchdog 3/3 READY，Worker 被精确终止后约 3 秒恢复；默认实例重启后的公开请求 20 次平均 TTFB 63ms、P95 148ms。登录态 Dashboard 的最终浏览器数字仍应以实际用户会话刷新后的 WelinePanel 为准。

## 已发布绑定目录（2.2.261）

`theme.published_binding.v1` 使用中央 CachePolicy/HotCache，以显式 ScopeIdentity canonicalKey + area 作为逻辑键（因此 CLI/队列不得借用当前请求的 Scope）。目录使用 global policy 的 `theme` 依赖，即既有 `global/storefront/theme`；不是将所有站点合并成同一缓存键。当前发布 helper 已推进该全局依赖，批量回滚现补齐同事务事件。未来缩小失效范围必须同步完善写入 namespace，不能单方面改变读依赖。

L1/L2 只保存 theme_id / release_id；无有效 Release 保存缺省标识，legacy active theme 在每个请求重新获得。模型纯数据仅由统一 rememberForRequest 挂到既有 Context，返回独立模型。事务和无请求上下文不复用。WelineTheme 独立 save 与 ThemeAiDraftService 组件 publish 的完整 changed 契约尚未覆盖，因此本轮不共享它们的可变行或组件目录。
