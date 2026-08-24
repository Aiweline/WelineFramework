# Weline_Search — AI Index

- README：[`README.md`](README.md)
- 架构：[`search-index.md`](search-index.md)
- Shard：`SearchShardKey`、`SearchShardRegistry`、`SearchShardProvisioner`
  - family=`search.website`
  - schema=`2.0.1`
  - entities=`document`、`watermark`、`applied_event`
- Storage：`DatabaseSearchIndexStore`
  - staging generation 全量构建
  - active generation 原子切换
  - durable idempotency / document CAS / contiguous watermark
- Product source：`ProductSearchProjectionSourceInterface` →
  `ProductQuerySearchProjectionSource`
- Build：`SearchIndexBuilder`
- Incremental：`ProductSearchProjectionChangedObserver` →
  `SearchIndexIncrementalQueue` → `SearchIndexIncrementalApplier`
- Scope：Website/Store/Channel/locale/currency 是文档身份；Queue content
  不携带 Scope，权威 Scope 仅来自持久 `scope_envelope`
- Storefront Query：`SearchQueryProvider` → `SearchQueryService`
  - 浏览器 operation 只有只读 `search`，参数只有 `q`
  - Website/Store/Channel/locale/currency 只来自
    `RequestContext::scopeMetadata()`
  - mode `off/shadow` 直接读 Product current；`allowlist/on` 才尝试索引
- Product direct：`ProductDirectCatalogReaderInterface` →
  `ProductProjectionDirectCatalogReader`
  - 复用 `ProductSearchProjectionSourceInterface::snapshotWebsite()`
  - 返回 source watermark、snapshot hash、source document count 和 hits
  - 精确 locale/currency 覆盖中性 `''/''`；partial dimension fail-closed
- Degraded serving：`SearchDegradeMarker` →
  `DatabaseSearchDegradeMarkerStore` → `SearchDegradeState`
  - index not-ready/read failure 时显式 `product_direct_degraded`
  - marker 按 Website 持久化并使用 writer-token/version CAS
  - 仅 Search incremental 与 Product current 水位相等且达到 required
    watermark 时解除
- Rollout：`SearchRolloutGate`
  - Env 整对象锁或 global SystemConfig；默认 `off`
  - allowlist identity=`website_id:store_id:channel_id`
- Serving alias：`SearchAliasStore` → `SearchServingAlias`
  - per-Website `direct/index`、generation、version 持久化
  - writer-token + expected version/generation 原子 CAS
  - storefront 只有 rollout 生效且 alias 指向 active generation 才读索引
- MIG-P3C：`MigrateP3cSearch` → `SearchMigrationService`
  - 命令：`commerce:migrate-p3c-search`
  - 仅 migration registry 登记的 `mode=full` clone 可执行
  - `preflight → apply(shadow/direct) → fresh verify → allowlist`
  - rollback 恢复 `off/direct`，保留 checkpoint、journal 与索引 generation
- Schema Provider（唯一）：
  `extends/module/Weline_Framework/Schema/SearchShardSchemaProvider.php`
- P3C-001 tests：
  - `SearchIndexBuilderTest`
  - `SearchShardPersistenceIntegrationTest`
  - `SearchIndexIncrementalQueueTest`
  - Product `ProductSearchProjectionServiceIntegrationTest`
- P3C-002 tests：
  - `SearchQueryServiceTest`
  - `SearchQueryProviderTest`
  - `ProductProjectionDirectCatalogReaderTest`
  - `SearchDegradeMarkerPersistenceIntegrationTest`
  - `SearchRolloutGateTest`
  - `Test/e2e/frontend/plan-p3c03-degraded-search.spec.js`
- MIG-P3C tests：
  - `SearchMigrationServiceTest`（checkpoint、alias CAS、shadow payload、
    rollback 与真实查询门禁）
  - `SearchQueryServiceTest`
  - full-clone CLI fresh-process/tamper/rollback acceptance
- 当前边界：`TASK-P3C-001` 的分片索引/全量/增量与
  `TASK-P3C-002` 的可信 storefront query/Product current 直读/显式降级/
  recovery gate 已实现；`TASK-MIG-P3C` shadow/alias/cutover 已完成工程
  验收，独立重跑 TEST-P3C-01..04 后 `GATE-P3C=GO`，P3 聚合复验后
  `GATE-P3=GO`。生产 `mode=on` 与 `PG-8` 尚未在本文档宣告完成。
