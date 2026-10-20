# 请求上下文与冻结机制证据索引（2026-10-05）

回答的问题：**上下文在时序中的哪一段被计算、在哪一刻冻结、冻结后下游如何"直接读取而不重复计算"**。
对应图：`flow-request.dot/svg`（④b/④c 蓝色六边形 + `Ctx` 圆柱）、`flow-backend.dot/svg`（④b）、`flow-request-sequence.mmd/svg`（`RequestContext+Context` 与 `ScopeResolver` 两条泳道 + 两个蓝色 rect 段）。
路径前缀 `app/code/Weline/`。

## 核心结论（一句话）

一次请求只有一段"上下文计算窗口"（App::applyParsedUrl 内），窗口末端把 **ScopeIdentity** 和 **StorefrontCacheKeyContext** 两份**不可变对象**装进请求级 `Context`；此后路由、FPC、控制器、模板、翻译全部走 `current()` / `scopeIdentity()` 的纯读，缓存命中路径甚至完全跳过后续解析。

## 生命周期时间线

| # | 动作 | sourceRefs | 置信 |
|---|---|---|---|
| 1 | 每请求重置上下文存储：`RequestContext::init()` 清空 storage/cleanup/capture-discard、生成 requestId/connectionId/chainId、记录 start_time | `Framework/App.php:143`（bootstrapRequestCycle 内调用）, `Framework/Runtime/RequestContext.php:40-57` | high |
| 2 | 访客 URI 的 lang/currency 先同步一次，注释明确"在任何 Storefront scope 与所有缓存键之前" | `App.php:391-396`（`State::resetRequestPathLocalizationCache()` + `synchronizeParsedLocalization`） | high |
| 3 | 组装权威请求身份：`WELINE_ORIGIN_REQUEST_URI` / `WELINE_FULL_REQUEST_URI` 写入 server 上下文与环境量 | `App.php:418-445` | high |
| 4 | **Scope 解析入口**：`installStorefrontNavigationScope($fullRequestUri,…)`；已冻结身份走"重入即返回"分支，且要求 kind=channel + metadata + routePath 齐备，否则 `noRouter(503)` | `App.php:744-792`（:755-768 重入读、:781 安装） | high |
| 5 | 解析器经 **provides DI** 绑定：接口在 Framework，实现在 Websites（框架不反查业务模块） | `Framework/Runtime/StorefrontScopeInstallerInterface.php:11-13`, `Websites/etc/module.php:39` → `Websites/Observer/DetectWebsite.php:135`, `Websites/Service/StorefrontScopeInstaller.php:24-55`, `Websites/Service/ScopeResolver.php` | high |
| 6 | **冻结点**：`RequestContext::installScopeIdentity()` — 已有不同身份则抛 LogicException「当前请求的 ScopeIdentity 已冻结，禁止二次改写」；同值写入直接 return；发布前先清空全部可变投影（website/store/channel code+id、lang、currency…）再逐 kind republish，并写 legacy scope 串 | `Runtime/RequestContext.php:541-585` | high |
| 7 | 被冻结对象本身是 `final readonly`：scopeKind/websiteId/websiteCode/storeCode/channelCode/storeMode/contextVersion，比较用 canonical key | `Runtime/ScopeIdentity.php:1-60` | high |
| 8 | routePath 立即替换 `request.uri` / `input.server.REQUEST_URI` / `input.uri` | `App.php:447-453` | high |
| 9 | 冻结后广播门禁事件 `storefront_scope_ready_gate`、`application_context_ready`（携带 navigation_scope + scope_identity，供能力模块装中性输入） | `App.php:465-489` | high |
| 10 | **第二次冻结**：channel 身份 → `StorefrontCacheKeyContextResolver::freezeCurrent()`；后台或未配置 provider → `freezeLegacyDefault()`；否则装 request-only fence | `App.php:491-507` | high |
| 11 | freezeCurrent 内部：先装 provisional fence，再解命名空间代次向量得 SHA-256 指纹，成功装 resolved、异常装 failed(cacheable=false)；**身份/lang/currency/defaultLocale/translationLocales 全一致时直接复用既有对象**（幂等重算保护） | `Framework/Cache/StorefrontCacheKeyContextResolver.php:22-94` | high |
| 12 | 缓存键上下文对象也是不可变的（`final readonly`，构造期校验指纹必须是小写 SHA-256、可缓存必带命名空间指纹） | `Framework/Cache/StorefrontCacheKeyContext.php:13-50`，`current()/install()` :52-64 | high |
| 13 | 渲染事实袋并行冻结：`StorefrontRenderContextInstaller::installOnce()` | `App.php:509-515`, `Runtime/StorefrontRenderContext.php` | high |
| 14 | 随后才做持久态 FPC 快路径与 `url_parsed_after` 派发 | `App.php:517-536`（`tryPersistentFpcFastPath` :794-825） | high |

## "只算一次、之后直读"的读侧证据

| 读取方 | 直读方式（无 DB、无重解析） | sourceRefs |
|---|---|---|
| FPC 探测 | `StorefrontCacheKeyContext::current()` 取冻结指纹拼缓存键 | `Router/Core.php:1694`, `Router/FullPageCacheCoordinator.php:1776,4741` |
| 全站缓存/翻译/模板编译 | 同一 `current()` 读 lang/currency/locale 回退链 | `App/State.php:146,297`, `Phrase/Parser.php:1836` |
| 任意 website/store/channel 标量 | `getWelineWebsiteId()` / `getWelineWebsiteCode()` 优先从 `scopeIdentity()` 的只读属性返回，仅未冻结时才回落 `route.*` 投影 | `RequestContext.php:375-388, 699-712` |
| 遗留数组形态身份 | `scopeIdentity()` 首次 array→object 水化后**写回缓存**，后续调用即对象直返（compute-once-read-many） | `RequestContext.php:681-697` |
| 冻结后误写 | `allowFrozenScopeFieldWrite()` / `allowFrozenScopeIdWrite()`：同值写吞掉、异值写抛错 | `RequestContext.php:1072-1120` |
| 路由/控制器/视图 | 只读 `env.area`、`route.path`、`ScopeIdentity`，不再解析 URL 或站点 | `Router/Core.php`（各阶段读 WelineEnv/RequestContext） |

## 受控解冻（唯一 sanctioned 例外）

`replaceScopeIdentityForTrustedWorker()` 前置条件极窄：area 必须 `rest_frontend`、digest `hash_equals` 校验、websiteId 必须相同；先把 `SCOPE_*_PATH` 与 `STOREFRONT_ROUTE_PATH` 置 null，再重新 install。属"可信 worker 换绑 store"而非通用后门。证据：`RequestContext.php:599-640, 672-679`。

## 与前台的两处差异（后台流程图已标注）

1. **后台不做真实店面解析**：`$isBackend` 短路到 `freezeLegacyDefault()`（`App.php:500-501`），即使用户访问 `/backendKey/...` 也不会去解析 Channel——但缓存键上下文仍然被冻结为不可变对象。
2. **provider 仍由在场门禁装配**：`Websites/etc/module.php:39` 的 provides 绑定不看 URL 字面量，靠 `RegistryModulePresence` 是否在场决定；因此"后台没有店面服务"不是没注册，而是运行时按 area 选择分支。此为绘图时纠正的一个表述风险，避免读者误判为缺依赖。

## 其他 install 触发点（非主流程，画图为控制复杂度未展开）

- FPC receipt 驱动：`Server/Service/WorkerFullPageCacheFastPath.php:130,161`（从 receipt['scope_identity'] 直接装回，命中即免解析）
- 静态页生成/热袋：`Theme/Service/StorefrontNotFoundStaticGenerator.php:277,414`, `Theme/Service/StorefrontHotCacheBagSeeder.php:192`, `Maintenance/Service/MaintenanceStaticGenerator.php:243`
- Worker 侧提供者：`Websites/Integration/Framework/FrontendWorkerScopeProvider.php:408`
- 购物车域重建导航范围：`Cart/Service/CartScopeResolver.php:210-227`

## 边界与未画内容

- `StorefrontRenderContext` / `ScopeEnvelope` / `ThemeApplicationContext` 的内部字段未逐一展开，只表达"同样是一次安装、后续只读"。
- CLI / worker 任务态下的 fence seed 策略（`StorefrontCacheKeyContext.php:78-96`）只在源码里，未画图。
- WLS 纤程级 `Context` 隔离与 `GlobalsEmulator` 的关系未展开（属 transport/worker 层）。
