# Weline_Search

`Weline_Search` S1 起为**无 Product 硬依赖的万能搜索枢纽**；业务通过 `Searcher` 扩展注册（如 `ProductSearchProvider`），Product 模块 `requires Weline_Search`。

## Area / Slot

- Provider 实现 `areas(): list`（默认 `['frontend']`），Registry / Query / `<w:search area="…">` 按区域过滤类型。
- `frontend`：店面 `/search` + 热搜；`backend`：后台顶栏等，需管理员会话，建议 `navigate-hits="true"` 点击直达。
- 示例：`Weline_SystemConfig` 注册 `system_config`（仅 `backend`）。

`SearchProviderRegistry::all()` 保留完整 Provider 实例映射；带 `area` 的调用也复用该映射，并在每次读取时重新执行 `areas()` 过滤。前台读取不会覆盖后台 Provider，也不会把后台专属类型带到页头。`forceReload` 仍强制重新发现。搜索类型和分类选项继续由既有 `search.provider_types` 的 ScopeHotCache 策略（channel + lang + area，catalog/config 失效依赖）及页头请求 memo 统一缓存。

## S1 验收要点

- 唯一前台入口：`GET /search?q=&type=`；页头 `<w:search />`
- QueryBin `search.search` / `hotWords` / `types` 与 GET 共用 `SearchParamGuard`（支持 `area`）
- 默认引擎 `mysql`；可选 `wls_memory`；`redis`/`elasticsearch` 未配置 fail-closed
- Scoped 分析表：`search_query_log`、`search_query_daily`、`search_top_query_daily`、`search_hot_word`、`search_slow_log`、`search_slow_daily`
- 后台：搜索报告 + 性能慢日志（`<w:scope>` 切换）；顶栏万能搜索 `<w:search area="backend" />`
- `@Cdn` + `@Attack` → `Weline_Framework::controller_annotation_rules_collected`；Cdn/WLS 各自监听

历史 P3C 投影索引能力仍保留，详见 [`search-index.md`](search-index.md)。

## 店面 tip 查询结果袋预热（Clear / HotCache）

**无新增对外 REST/Query 契约**——仅内部 `CacheWarmer` + Clear 编排行为。

| 项 | 说明 |
|----|------|
| Warmer | `SearchQueryResultTipCacheWarmer`（`cache.warmer.search.query_result_tip`） |
| 池 | `search`；Clear 经 `CacheWarmerRegistry::warmUp('search')`（Framework `Cache/Clear::warmStorefrontFpcAfterClear`） |
| 种子 | **有界 tip**：默认 `q=hanfu` × 冻结矩阵 `de_DE/USD` · `ja_JP/USD` · `fr_FR/EUR`（page=1、type=all、area=frontend）；**禁止**全 q 笛卡尔 |
| 袋 | 写入既有 `search.query_result` HotCache（`StorefrontScopeHotCache`）；**不等于** Product `product.search_projection_scope` peek HIT |
| 验收指针 | 波 `storefront-xscope-hotcache-wave` · UC `A-search-query-result`；安静窗冷 hit1 无主导 `search.query_result` 空袋 builder（见 team `meetings/性能检查-review.md` / `test-exec.md`） |

契约：`Test/Unit/Service/SearchQueryResultTipCacheWarmerContractTest.php`。投影 warmer 仍属 Product；本 warmer 只补 tip 结果袋。

## Provider 内容索引（Blog 等）

非 Product 类型通过 `Searcher` 的 `documentsForIndex()` 写入 `search_provider_document` 表。**前台查询只读索引，禁止回退源表 SQL。**

索引维护：

1. **增量**：业务保存/删除事件（如 `Weline_Blog::post_search_index_changed`）
2. **定时**：Cron `search_provider_index_rebuild`（`*/15 * * * *`）对 `website_id=0` 及全部站点 `rebuildAll`
3. **升级**：`setup:upgrade` 后空索引 warmup
4. **手动**：

```bash
php bin/w search:provider-index:rebuild          # 全量重建
php bin/w search:provider-index:rebuild -i blog  # 仅 Blog
```
