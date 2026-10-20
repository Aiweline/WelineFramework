# WLS：Fiber 上下文 vs 进程/共享缓存（硬边界）

> 请求全链路主干（入口→管线→FPC/袋→响应）见 [请求上下文逻辑图.md](./请求上下文逻辑图.md)。

## 定案

| 层级 | 放什么 | 不放什么 |
|------|--------|----------|
| **Fiber / 请求上下文** | `Request` / `Response`、RequestContext、真正请求私有状态（`RequestLocalInterface`） | 词典/HotCache/FPC/媒体 URL 等胖缓存 |
| **进程袋** | Worker 内 L1（有上界）、`ProcessSharedInterface` 服务实例 | 按 `request_id` 开永久键 |
| **Memory Service** | 跨 Worker 权威/大块（Phrase Shared、FPC shared 等） | 请求围栏指纹 |

多 Fiber 并发时：**每个 Fiber 只有自己的上下文**；可读缓存一律进程袋或 Memory Service，禁止「每 Fiber 复制一份胖单例 + 私有袋」。

## 进程袋（命名 L1）契约

权威 API：`Weline\Framework\Phrase\DictionaryCacheNamespace`。

| API | 用途 | 登记进程表 |
|-----|------|-----------|
| `processBag($name, &$bag, $maxEntries, $locales)` | Worker 常驻 L1；`$name` 是显式身份 | 是（同名幂等，只移引用） |
| `requestBag(&$bag, $maxEntries, $locales)` | 请求级袋；随 `Parser::reset` / Fiber 释放 | **否** |
| `localCache(...)`（`@deprecated`） | 旧 API，语义已降为请求袋，仅供仓外迁移 | **否** |

硬规则：

1. **身份显式**：空名不得登记；禁止引用探测/匿名采纳（旧 `anon_N` 已删除）。
2. **容量必填**：每袋声明 `maxEntries`；满袋**一次削到 90% 水位**（`intdiv($max * 9, 10)`），后续插入摊还 O(1)。
3. **记账不落热路径**：locale 记账只在袋尺寸变化时写 `ProcessMemoryStore`；请求袋不写 Store。
4. **先 R0 后 L1**：请求内事实先 `RequestContext` memo，再命名进程袋，再 L2（`ScopeSharedMemo` / `w_cache`），DB 仅 miss。
5. **门禁**：`ProcessBagRegistryContractTest`（空名不登记、命名合法、登记表 ≤40）；`ProcessBagIdentityContractTest`（请求袋永不登记/不写 Store、90% 水位）。
6. **重袋观测**：条目数 `> 20`（`DictionaryCacheNamespace::HEAVY_BAG_ENTRY_THRESHOLD`）的袋进入 `MemDiag.process_bag_heavy_names`。这是观测信号，不是硬失败；真实上界仍是各袋 `maxEntries`。
   - **重袋豁免清单**（按设计可超 20，新增超阈袋必须先登记再改门禁）：
     `worker*`（11 个 `BAG_*` 词表）、`globalDictionaryModuleBags`、`phrase.*`、`i18n.repository.country.rows`、`i18n.model.local_words`、`i18n.active_locale.codes`、`i18n.taglib.language_select.items`、`i18n.repository.locale.installed_active`、`i18n.taglib.language_switcher.html`、`i18n.taglib.language_switcher.chrome_words`、`currency.local_names`、`shipping.region_names`、`shipping.street_names`、`shipping.postal_place_names`、`product.*`。
   - 未登记却超阈的袋：优先拆分命名/降低 `maxEntries`，不要只把阈值调大。

生产命名袋清单（新增袋必须同时更新本节与门禁豁免）：

| 袋名前缀 | Owner | 说明 |
|----------|-------|------|
| `worker*`（11 个 `BAG_*`） | `Phrase\Parser` | 词典 L1 分层/物化/翻译/模块/全局词典 |
| `globalDictionaryModuleBags` | `Phrase\GlobalDictionaryModuleBagCache` | 整 locale/module 文件袋 |
| `i18n.*` | `I18n` | taglib 渲染、模型、active locale、本地化仓库 L1 |
| `phrase.*` | `Phrase` | 词表/测试袋 |
| `currency.local_names` | `Currency` | 币种本地名；保存/删除时 `clearProcessCache` |
| `shipping.region_names` / `shipping.street_names` / `shipping.postal_place_names` | `Shipping` | 地址展示名（`locale\|id` 扁平键）；`ProcessCacheResetter` 清理 |
| `product.*` | `Product` | PDP/店铺页渲染袋（buy box、defer SSR 卡片、页面分配） |
| `storefront.render_context.v1` | `Storefront` | 渲染上下文袋 |

`i18n.repository.locale.installed_active` / `i18n.repository.country.rows` 由 locale 目录观察者 `LocaleCatalogChangedClearActiveLocales` 失效。

MemDiag：`process_bags`（name → entries/bytes）、`process_bag_entries`、`process_bag_bytes`；登记数不随请求增长。

Fiber **结束/回收**（Worker 仍可能握着 terminated Fiber 引用）必须显式释放：
- `ObjectManager::clearRequestScopeForFiber`（含 Template / FiberOutputBuffer 的 Fiber WeakMap）
- `ObjectManager::sweepTerminatedFiberScopes`（reset 扫尾）
禁止只依赖 WeakMap+GC。

## SoftSurvivor（soft 压力角色）

权威：`Weline\Framework\Runtime\SoftSurvivorCatalog`。

| 角色 | soft 压力 | 二次 include 大编译表 | 说明 |
|------|-----------|----------------------|------|
| `ROLE_CONTENT` | 可卸 | — | 可重建袋（词典、NodePool、TemplateCache、FPC process payload 等） |
| `ROLE_REGISTRY` | **禁卸** | **禁二次 include**（WLS/persistent） | 大编译注册表；统一门闩见 [`../开发/spec/process-resident-compiled-php.md`](../开发/spec/process-resident-compiled-php.md) |

**REGISTRY ≠ 仅「soft 不丢引用」**：领域 loader 仍须经 `CompiledPhpRegistry`（或等价 once 门闩），否则 mtime 再 include 仍会 OOM。

**language 排除**：`generated/language/**` 整袋禁止进 REGISTRY / CompiledPhpRegistry 常驻编译表（Phrase/WLS 既有硬门禁）。

## ObjectManager

- `ProcessSharedInterface` → 始终写入/读取进程 `$instances`（Fiber 内也如此）。
- 未标记类 → 仍 Fiber 本地（兼容；避免把带请求可变字段的服务误共享）。
- `RequestLocalInterface` → 明确请求上下文（Http Request/Response、`Template` 已实现）。

已标 ProcessShared 的热路径示例：`CacheManager`、`StorefrontScopeHotCache`、`FullPageCacheCoordinator`、`SingleFlightCoordinator`、`NamespaceGenerationRepository`、`StorefrontProductMediaUrlResolver`、`Url`、`RuntimeCachePolicy`、Theme 片段缓存协调器 / 路径解析、SearchProviderRegistry、OfferPrice 组装注册表。

**禁止**对 `SlotRendererService`（持 `Template` 注入 + 单次 `SlotRenderPass` 算法草稿）、持 Request/Session 链或可变 ORM 的服务标 ProcessShared。请求事实只进 `RequestContext` 袋；SlotRenderer **不得**维护 layout/widget 请求或进程 L1（已发布结构 HotCache 仅允许在 `ThemeRuntimeLayoutResolver` 读模型侧）。

新增可跨 Fiber 共享的缓存协调器时：**必须**实现 `ProcessSharedInterface`，且请求维状态只进 `RequestContext`。

## 验收信号（MemDiag）

- `om_fiber_bucket_count`：约等于并发中的请求 Fiber 数（小）。
- `om_fiber_instances`：每桶仅上下文类，不应再含 CacheManager / HotCache / FPC coordinator 等进程共享服务。
- `om_main_instances`：进程共享服务计数随暖机上升、不随并发线性翻倍。
- `process_bags` / `process_bag_entries` / `process_bag_bytes`：命名进程袋清单；登记条目不随请求数增长（`ProcessBagIdentityContractTest`）。
- `process_bag_heavy` / `process_bag_heavy_names`：条目数 `> 20` 的袋数量与名单（观测信号；豁免清单见「进程袋契约」第 6 条）。
