# Weline_Search

`Weline_Search` S1 起为**无 Product 硬依赖的万能搜索枢纽**；业务通过 `Searcher` 扩展注册（如 `ProductSearchProvider`），Product 模块 `requires Weline_Search`。

## S1 验收要点

- 唯一前台入口：`GET /search?q=&type=`；页头 `<w:search />`
- QueryBin `search.search` / `hotWords` / `types` 与 GET 共用 `SearchParamGuard`
- 默认引擎 `mysql`；可选 `wls_memory`；`redis`/`elasticsearch` 未配置 fail-closed
- Scoped 分析表：`search_query_log`、`search_query_daily`、`search_top_query_daily`、`search_hot_word`、`search_slow_log`、`search_slow_daily`
- 后台：搜索报告 + 性能慢日志（`<w:scope>` 切换）
- `@Cdn` + `@Attack` → `Weline_Framework::controller_annotation_rules_collected`；Cdn/WLS 各自监听

历史 P3C 投影索引能力仍保留，详见 [`search-index.md`](search-index.md)。

