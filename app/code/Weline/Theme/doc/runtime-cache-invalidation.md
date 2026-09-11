# Theme 运行时缓存失效契约

`ThemeRuntimeCacheCleaner` 负责主题切换、布局发布和后台模式变更后的定向失效。
请求链路不得因为其他 WLS 实例、失联 IPC 或持久化实例清单而阻塞数秒。

## 失效范围

- **主题发布（编辑器 / Scoped Release）必须按当前发布主题所属 Scope 调用 `clearScopedCaches(scope, themeId)`**，使 storefront Theme 命名空间世代仅对该 Scope（及其后代向量）失效；禁止在已知 typed Scope 时用 `clearNonGlobalCaches(null)`（会跳过 `generated_theme_cache`）。
- Scoped workspace `publish` 成功后由 `ThemeScopedWorkspaceRequestService` 执行 `clearAllThemeRelatedCaches`（全量主题相关缓存）；`blocked`（结构冲突）不得清缓存，且 HTTP 必须 `success=false`。
- Scoped `publish` / `publishBatch` / `rollbackReleaseBatch` 必须在主库写事务内 `w_changed(theme|theme_layout)`；`ResourceChanged` 对 `theme` / `theme_layout`（及 Theme 模块 system_config）**必须** `clearAllThemeRelatedCaches`，不得仅 `clearScopedCaches`（否则 chrome/FPC/模板池残留旧 HTML）。
- `clearAllThemeRelatedCaches`：在 `clearNonGlobalCaches` 之上再执行
  1. `theme_namespace_generations_all` — bump 全部已记录的 `*/theme*` 命名空间 + `global/storefront/theme`
  2. `fpc_cache_pools` — 清空 `fpc`/`router` 缓存池
  3. `wls_shared_fpc_full` — 经 WLS adapter 清共享 FPC/router
  4. `wls_worker_broadcast_all` — `cacheClear(null)` 广播全部 WLS 实例
  5. `cdn_full_page_purge` — 若存在 `Weline_Cdn`，对启用域名 `everything`（失败则 `hosts`）全页 purge
- `clearAllThemeRelatedCaches` 亦含 framework 非全局池、generated theme cache、ThemeData、Partials、storefront chrome、SlotRenderer、编译模板、`view/tpl`、taglib/view、进程 FPC、theme_runtime 与 router-fpc-payloads。
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

- `ThemePublishedVersionRuntimeResolver` 按请求 `ScopeIdentity` 祖先链解析 `theme_layout_version.is_published`；链上未命中时回退该 theme+pageType 任意已发布版本，保证 Website 已发布时 HTML `themePublishedVersion*` 非空。

## Chrome partial 输出缓存

- 后台/前台 chrome partial 的渲染结果只保存在当前 Worker 的有界 LRU 中；请求热路径不得为每个 head、topbar、sidebar 等 partial 调用 theme_runtime SharedState。
- 是否缓存以模板 @meta.cache 为权威；显式 mode=off 不得被类型默认值重新启用。
- cache key 必须绑定模板文件状态、area/type/option、主题与页面上下文；需要用户或角色隔离的 partial 在身份无法解析时直接绕过缓存，禁止落入共享的 unknown bucket。
- ProcessCacheResetter 的 hard reset 清空本进程 chrome LRU；Worker 启动预热只填充安全的 guest/公共上下文，登录用户/角色上下文在首次真实请求时填充。

## SlotRenderer 已发布布局 / 部件输出缓存

- 前台 `SlotRendererService` 的已发布 layout.data 与可缓存 widget.output 仅进程内 L1；请求热路径不得 `theme_runtime` get/set（池压下约 200ms/次，产品详情冷路径曾累计约 800ms+）。
- 主题发布仍调用 `purgeRuntimeCacheNamespace()` 清理共享命名空间，避免历史 Worker 残留旧值。
- 与 chrome partial 策略一致：跨 Worker 靠各自预热 / FPC，不以慢 IPC 换共享命中。

## ThemeData 运行时缓存

- `ThemeData` 请求热路径只维护进程内 L1；不得 `weline_site_runtime` get/set。
- 前台 head（`ThemeDiskHeadService`）刷新 disk_bundle 视图时只 `clearProcessMemoryCache()`，禁止请求内 `clearNamespace`。
- 显式发布 / 配置写入仍可 `ThemeData::clearCache()`（含共享命名空间）。

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
