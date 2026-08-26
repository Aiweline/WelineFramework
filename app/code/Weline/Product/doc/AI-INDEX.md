# Weline_Product — AI Index

- README：`doc/README.md`
- 万能产品总体计划：`doc/万能产品完善计划.md`（v1.0，IMPLEMENTING）
- 当前证据：Product `1.0.23`；PostgreSQL V2 身份已切到
  `v2_authoritative` version 6（26 Product / 26 Offer / 0 conflict）；跨站
  PostgreSQL 矩阵已通过。独立 WLS/Browser 因 Master lease owner evidence
  不可观察而阻断，不能标记 ACCEPTED。
- Shard family：`product.website`（非负规范十进制 key，且不得超过 `PHP_INT_MAX`）
- Registry：`Model/ProductShardRegistry`
- Provisioner：`Service/ProductShardProvisioner`
- Schema provider：`extends/module/Weline_Framework/Schema/ProductShardSchemaProvider.php`
- Catalog DDL：`Service/ProductShardSchemaCatalog`（SCHEMA_VERSION=4.0.0；九实体白名单；`category_link` 唯一索引 `uk_store_category_product`）
- P2A-002 正式验收：PostgreSQL 双 Website 九表、幂等、数据保留、单站
  drift 隔离 + 编译后 `product.website` provider discovery；SQLite 用例只作
  一次性隔离开发/可移植性回归
- SKU identity：`Model/SkuRegistry`、`Model/SkuAlias`、`Service/SkuRegistryService`、
  `Api/ProductIdentity`、`Api/ProductIdentityResolverInterface`
  - `SkuRegistryService` 实现公开只读 resolver，供 Vendor 等跨模块消费者
    按 SKU / Product UUID / Offer UUID 解析 canonical identity
  - 跨模块消费者不得读取 Product 私有 Model/Repository
  - request hash：32–128 hex / `varchar(128)`
  - ref_count + cleanup：条件 CAS，tombstone 保留 registry/alias，SKU 永久禁止复用
  - V2 切换：`Model/ProductIdentityCutoverState`、`Service/ProductIdentityCutoverService`、
    `Service/CompatibleProductIdentityResolver`
  - 状态机：`legacy → dual_read → v2_authoritative`；验证摘要与 active legacy
    源摘要一致才可切换，rollback 只改模式、不删 V2 数据
  - V2 权威后 `SkuRegistryService` 所有 mutation 返回
    `legacy_identity_writes_disabled`，公开 V1 resolver 保留旧数据只读回退
  - 验收：`Test/Unit/Service/SkuRegistryServiceIntegrationTest.php`、
    `Test/Unit/Service/ProductIdentityCutoverIntegrationTest.php`
- Overlay：`Service/CatalogOverlayResolver`、`Api/ResolvedScopeValue`、`Repository/*Repository`
- Overlay schema：`4.0.0`；Product/Offer publish 与 Media blob owner 使用
  writer-owned `cas_token` 回读验权
- Store copy：`ProductCopyOperation` 持久化 draft、commit claim、
  request hash、receipt/audit；Category shard 的 `global_category_uuid`
  支持跨 Website 新身份与重放映射
- P2A-004 开发回归：`Test/Unit/Repository/CatalogRepositoryIntegrationTest.php`
  一次性 SQLite 双 Website/Store fallback/cleared/COW/CAS；跨站 durable
  正式 PostgreSQL 证据由
  `Test/Unit/Service/ProductCopyDurableCatalogAdapterTest.php` 提供（4 tests /
  88 assertions，随机 schema 用后清零）
- Shard models：`Model/Shard/*`（SchemaDiffExcluded）
- Provider SPI：`Api/ProductProviderInterface`、`Api/Capability/*`、
  `Service/ProductProviderRegistry`、`doc/provider-guide.md`
  - registry-owned required/capability snapshot + canonical metadata
  - duplicate/malformed matched extension fail-fast；disabled custom 保留 default simple
  - 验收：`Test/Unit/Service/ProductProviderRegistryTest.php`（不调用 renderer dispatch）
- Scene Renderer：`Service/ProductSceneRenderer`、`Api/ProductSceneRendererInterface`、`Api/Data/ProductScene{Context,RenderResult}`
  - Browser E2E harness：`extends/.../Query/ProductSceneQueryProvider.php`（`product_scene`）、`Service/ProductSceneQueryHarnessCatalog`、`Service/Harness/*`
  - Media shareCopy/COW E2E：`extends/.../Query/ProductMediaQueryProvider.php`（`product_media`）、`Service/ProductMediaQueryHarnessCatalog`、`Test/e2e/frontend/plan-p2a07-media-cow.spec.js`
  - Spec：`Test/e2e/frontend/plan-p2c-render-scene.spec.js`（TEST-P2C-RENDER-01/02/03）
- Store Copy：`Service/ProductCopyService`、
  `Service/ProductCopyDurableCatalogAdapter`、
  `extends/module/Weline_Framework/Query/ProductCopyQueryProvider.php`、
  `Api/Data/Copy{Draft,Preview,CommitResult}`、`doc/copy-guide.md`
  - durable 开发回归：`Test/Unit/Service/ProductCopyDurableCatalogAdapterTest.php`
    （双 Website、重放/冲突、Store 补抄、目录+库存回滚）；正式矩阵使用
    `WELINE_PRODUCT_COPY_TEST_PGSQL_DATABASE=mig_clone_*`，每个用例在随机
    `product_copy_test_*` schema 隔离并清理
  - 后台 Query/ACL 验收：`Test/Unit/Query/ProductCopyQueryProviderTest.php`
    （scope 归属、墓碑拒绝、blank preview/commit、六操作显式 ACL）
- Cart V2 Provider：`extends/module/Weline_Cart/CartItemSnapshotProviderV2/ProductCartItemSnapshotProvider.php`
  + `ProductCatalogCartItemSnapshotResolver.php`；生产读取 durable
  Offer/Product/Store/EAV/Price/Media，harness catalog 仅测试使用
- Shard status Query：`product_shard_status.current` 只读当前可信 Website
  的 registry/schema 状态与 `database_driver`，不接受客户端 Website ID，
  不执行 DDL
- Hook 结构化结果：`Weline\Framework\Hook\HookRenderResult`、`Template::getHookResult()`
- P3C-001 Search current source：
  - stream：`Model/ProductSearchProjectionStream`
  - transaction publisher：`Service/ProductSearchProjectionMutationCoordinator`
  - mutation triggers：`Repository/ProductRepository`、`OfferRepository`、
    `StoreProductRepository`、`StoreOfferRepository`；Offer 事件映射父 Product，
    StoreOffer 事件映射父 Product + Store，继续只发布 `product` / `store_product`
  - projection：`Service/ProductSearchProjectionService`
  - internal Query：`extends/module/Weline_Framework/Query/ProductSearchProjectionQueryProvider.php`
    （`product_search_projection.currentWatermark/snapshotWebsite/projectChange`）
  - durable 验收：
    `Test/Unit/Service/ProductSearchProjectionServiceIntegrationTest.php`
- P3C-002 Search direct consumer：
  - `Weline_Search` 的 `ProductProjectionDirectCatalogReader` 继续只消费
    `ProductSearchProjectionSourceInterface::snapshotWebsite()`
  - direct read 使用 source watermark、snapshot hash、document count
    证明真实 Product current 读取；Product 不依赖 Search 内部实现


## 万能产品 v1.0 路由

| 目标 | 权威文件/契约 |
|---|---|
| 全量实施计划与门禁 | `doc/万能产品完善计划.md` |
| Product/Offer V2 身份 | `Api/Data/ProductIdentityV2.php`、`Api/Data/OfferIdentityV2.php`、`Service/ProductIdentityV2Service.php` |
| 分享与归属转让 | `Service/ProductGovernanceService.php` |
| Provider V2 与五类定义 | `Api/ProductProviderV2Interface.php`、`Service/Provider/` |
| 发布诊断 | `Service/ProductPublishValidator.php` |
| 分片 V2 字段 | `Service/ProductShardSchemaCatalog.php`（schema 4.0.0） |
| 旧 1:1 迁移 | `Service/ProductV2MigrationService.php`、`Console/Commerce/MigrateProductV2.php` |
| 扩展说明 | `doc/provider-guide.md` |
| 跨站规则 | `doc/copy-guide.md` |

跨模块只能使用 V2 resolver、Provider/能力接口、QueryProvider、Hook 或 Event；禁止直接依赖 Product 内部 Model/Repository。
