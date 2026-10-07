# RequestContext 请求级 Memo 约定

权威边界仍见 [WLS-Fiber上下文与进程缓存边界.md](./WLS-Fiber上下文与进程缓存边界.md)。本页只约定 **同请求内** 如何把「第一次算得的事实」写进袋子，避免下游重复查库/RPC。

## API

```php
RequestContext::remember(string $key, callable $builder): mixed
```

- 同 Fiber 请求：`has($key)` 则直接返回；否则执行 `$builder` 并 `set`。
- 键名：点分 + 版本后缀，例 `product.identity_cutover.snapshot.v1`、`i18n.installed_active_codes.v1`。
- **不**替代 `StorefrontScopeHotCache`：可跨 Worker 共享 / 有 CachePolicy 的店面事实仍走 HotCache + `prefetchPolicy`。
- 变更写路径必须 `set` 新快照或 `remove` 旧键（见 Product cutover `publishRequestSnapshot`）。

## 与页级预取编排的分工

| 机制 | 键/入口 | 用途 |
|---|---|---|
| `RequestContext::remember` | 业务键 | 无 Policy 的请求内快照 |
| `PageDictionaryPrefetchCoordinator` | `phrase.page_dictionary_union.primed.v1` | 布局前一次 Phrase 模块并集 MGET |
| `StorefrontHotCachePagePrefetch` | `storefront.hotcache.page_prefetch.primed.v1` | 残差 HotCache `prefetchPolicy` 批次（channel/meta） |
| `ThemePathResolvePagePrefetch` | `theme.path_resolve.page_prefetch.primed.v1` | `theme.path.resolve` 嵌套字面 fetch 批次（禁 collector 内 resolve） |
| `StorefrontWidgetRuntimeSchedule` | `storefront.widget_runtime_schedule.primed.v1` | 布局前统一调度：HotCache/path → 词典 → asset 扫描 |

调度入口：`ControllerFetchFileAfter` → `StorefrontWidgetRuntimeSchedule::primeBeforeLayoutFetch`。

## 反例

- 把胖词典/HTML 塞进 RequestContext（应 L2 / 片段缓存）。
- 在未初始化 RequestContext 时依赖 `remember`（CLI/无请求应走 builder 直通或进程袋）。
- 把请求私有会话态标 `ProcessShared`。
