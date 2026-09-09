# Weline_Product

商品目录 Website 物理分片与 Provider SPI（万能商城内核 P2A）。

外部应用创建、编辑和读取产品见 [产品 REST API](rest-api.md)；按语言保存、批量多语言及翻译接入见 [REST 多语言指南](rest-api-multilingual.md)。

总体完善路线与待确认产品决策见：[万能产品完善计划](万能产品完善计划.md)。

商品搜索引擎 URL 接入见 [商品 Sitemap 与 URL 变更](product-seo-sitemap.md)：
Product 提供 SitemapUrlProvider，保存/发布/下架复用现有 ResourceChange 并携带当前及旧 URL。

店面读模型：PDP 报价只在框架 Context 传递；分类文本按 website/实际 locale 共享，URL 留在请求内；新品候选按 website/日期窗口/分页共享，卡片价格和可见性继续按有效 channel 投影。小型推荐采用 SQL 分批候选并补足有效商品，复用现成列表。实现与验收状态见 [开发日志](开发日志.md) 的 1.0.181 条目。

促销标识选择在冷读取时按 200 商品批量查店铺与权威身份，SKU/名称关联数据按实际筛选读取；搜索分类下拉直接消费既有基础分类树，不生成导航 URL。此路径无新增共享缓存，验收证据见开发日志 1.0.185 条目。

商品卡片规格链接通过 Eav 可选共享身份接口，按实际变体轴批量读取 ID/code/原始值；同请求后续卡片复用轴投影，避免为了生成链接加载全实体的选项翻译。显示标签、商品私有选项、非可选 provider 的原有读取路径与 token 回退不变，见开发日志 1.0.190。

## P2A-002：Product shard schema/state

- Family code：`product.website`
- Shard key：`website_id` 的规范十进制字符串（含 `0`）；`website_id < 0` 非法
- Shard key 上界：不得超过当前运行时 `PHP_INT_MAX`，禁止整数溢出后映射到错误站点
- 物理表：`product_ws_{shardKey}_{entity}`；entity 必须命中
  `ProductShardKey::ENTITY_CODES` / `ProductShardSchemaCatalog::ENTITIES`
  的九项白名单，禁止任意后缀成为物理表
- 注册表：`product_shard_registry`（全局，非分片）
- 状态机：`unprovisioned → provisioning → ready | maintenance | failed`
- DDL：`ProductShardProvisioner` → Framework `ShardSchemaProvisioner`；失败 maintenance，不删表
- Provision 结果门禁：family/shard 身份、终态、表集合与 ready 指纹必须匹配声明；
  伪造或不完整结果进入 maintenance，不能把注册表标成 ready
- Schema bump：`ready` 且 `schema_version != ProductShardSchemaCatalog::SCHEMA_VERSION` 时允许 `ready→provisioning` 升级
- Family checkpoint 固定使用 Website `0` 的稳定模板；新增已注册 Website 只展开实际 DDL，不改变 schema generation
- Ready 修复：当前 schema version 但指纹为空时同样走
  `ready→provisioning`，禁止空指纹短路为可写
- 写门禁：仅 `website_id >= 0` 且 `ready`（`assertReady` / `isWritable`）
- Extends：`extends/module/Weline_Framework/Schema/ProductShardSchemaProvider.php`

## P2A-003：SKU / Product / Offer identity

- 表：`weline_sku_registry`（唯一身份）、`weline_sku_alias`（rename 后旧 SKU）
- 服务：`SkuRegistryService::claimLocked($sku, $requestHash)`
  - 同 SKU + 同 hash → 幂等回放
  - 同 SKU + 异 hash → `sku_request_hash_conflict`
  - UNIQUE(sku) 竞态必须先退出并回滚失败事务，再回读 winner 比较 hash；
    禁止在 PostgreSQL aborted transaction 内查询，否则会泄漏 `25P02`
- `request_hash`：32–128 位十六进制，存储列为 `varchar(128)`
- `renameSku`：旧 SKU 写入 alias，canonical 切到新 SKU
- `ref_count` 增减使用 `(registry_id,status,ref_count,cas_token)` 条件 CAS，冲突有界重试，
  禁止丢更新和负数
- `cleanupOrphanBySku` 仅通过 `status=active AND ref_count=0` 条件更新
  tombstone；并发出现引用时 fail-closed
- tombstone 保留 registry 与全部历史 alias；canonical SKU 和旧 alias 都是永久
  身份保留字，禁止被另一 Product/Offer 身份复用
- 公开 DTO：`Api/ProductIdentity`
- 公开只读解析契约：`Api/ProductIdentityResolverInterface`；
  provider 为 `CompatibleProductIdentityResolver`。`legacy` 模式读取旧表，
  `dual_read` / `v2_authoritative` 模式 V2 优先，并对未迁移冲突行和历史
  alias 保留旧表只读回退。Vendor 等跨模块消费者必须使用此契约，不得读取
  Product 私有 Model/Repository。
- 切换状态持久化在 `weline_product_identity_cutover_state`：
  `legacy → dual_read → v2_authoritative`。只有 active legacy 源摘要与最近
  成功验证摘要完全一致且开放冲突为零时才可切换；rollback 只回退模式，
  不删除 V2 Product/Offer/alias/audit。
- `v2_authoritative` 后 `SkuRegistryService` 的 claim/rename/ref_count/cleanup
  全部以 `legacy_identity_writes_disabled` 拒绝，resolver 读取仍兼容。

## P2A-004：Website 分片 Model + Store overlay Repository

- Schema version：`4.0.0`（`category_link` Store overlay：`store_id` /
  `scope_state` / `selected` / `position`；唯一索引逻辑名
  `uk_store_category_product(store_id,category_id,product_id)`，避免与旧
  `uk_category_product(category_id,product_id)` 同名改列导致 SchemaDiff 拒迁；
  另含 `cleared` / `publish_version` / media COW + writer-owned `cas_token` +
  cross-Website `global_category_uuid`；亦为 `shard:product.website` checkpoint）
- Shard Models（`Model/Shard/*`，SchemaDiff 排除，DDL 归 Provider）：
  Product / Offer / Category / CategoryLink / AttributeValue / Price / Media / StoreProduct / StoreOffer
- Repositories（同 Website 内 Store→Website fallback，禁止跨站）：
  - `AttributeValueRepository`：explicit / cleared；`deleteOverlay` 恢复继承；required cleared → `cleared_at_scope` 禁发布
  - `PriceRepository`：cleared → `price_cleared_at_scope` 不可售；删除覆盖恢复父价
  - `MediaRepository`：单一 blob owner + `ref_count`，`shareCopy` 同事务共享；
    `cowEdit` 通过 owner CAS 分叉，编辑原 owner 时提升并重挂剩余副本
  - `ProductRepository` / `OfferRepository`：`publish` 同时匹配
    `publish_version + cas_token`，回读只接受本 writer token；不依赖
    adapter affected-row
  - `StoreProductRepository` / `StoreOfferRepository`：Store 选品 overlay（`store_id≠0`）
- 纯解析：`Service/CatalogOverlayResolver` + `Api/ResolvedScopeValue`

## 入口

| 类 | 用途 |
|---|---|
| `Service/ProductShardProvisioner` | 注册/provision/schema bump/ready gate |
| `Model/ProductShardRegistry` | 分片状态与指纹 |
| `Service/SkuRegistryService` | SKU claim/rename/ref_count |
| `Api/ProductIdentityResolverInterface` | 跨模块只读 Product identity 解析 |
| `Service/CatalogOverlayResolver` | Store overlay 解析 |
| `Repository/*Repository` | 目录读写 / COW / publish |
| `Api/ProductIdentity` | 对外身份 DTO |
| `Api/ResolvedScopeValue` | overlay 解析结果 |
| `Service/ProductProviderRegistry` | Product type Provider SPI |
| `Api/ProductProviderInterface` | Provider 小接口 |
| `Extends/.../ProductShardSchemaProvider` | setup:upgrade 枚举分片表 |
| `Extends/.../ProductCatalogCartItemSnapshotResolver` | Cart durable Product 快照 |

## P2A-005：Product Provider capability SPI

- 扩展点：`extends/module/Weline_Product/ProductProvider/`（见 `extends.php`）
- 前台成交价（活动/折扣扩展）：见 [storefront-offer-price.md](storefront-offer-price.md)；槽位 `StorefrontPriceAdjustmentProvider`。
- 商品询价（仅询价规格提交落库）：见 [product-quote-request.md](product-quote-request.md)。
- 接口：`Api/ProductProviderInterface` + `Api/Capability/{Pricing,Inventory,Renderer}CapabilityInterface`
- Registry：`Service/ProductProviderRegistry`（code/type 唯一硬失败；
  注册时固化 required/capability contract；权威 metadata 防伪；
  匹配到的坏扩展 fail-fast；`listMetadata` 不调用 Renderer dispatch）
- 默认：`DefaultProductProvider`（type=`simple`，required=`name,sku`）
- 指南：[`doc/provider-guide.md`](provider-guide.md)

## P2C-001：Scene Renderer + 结构化 Hook

- `Service/ProductSceneRenderer`：按 type → capability → custom / 默认模板
- Fallback：bug 空 → 默认；`handled_empty` → 真空；异常 → 记错并回默认
- Provider 缺失/禁用：稳定错误码 + 内置 `simple/default`；未知 scene fail-closed 空输出
- 安全：字段 `htmlspecialchars`；拒绝 `options.template(_path)` 请求指定模板
- DI/cache：custom renderer 经 ObjectManager 创建；缓存键覆盖规范化完整渲染输入
- Framework：`Hook/HookRenderResult` + `Template::getHookResult()`；Taglib `<else/>` 在 DEV 注释之前运行时 opt-in

## 外部应用 REST 多语言管理（首版 1.0.176，复验 1.0.184）

`REQ-PRODUCT-0044` 扩展为按请求语言创建和编辑商品、一次提交多语言，以及经已配置翻译渠道生成译文后保存。名称、短描述、详情及三类 SEO 文案按语言增量写入；未提交的语言和字段保留。公共商品参数继续使用既有管理命令。

新建时源文只初始化一次默认回退；后续指定语言编辑不改写默认回退。批量语言写入位于同一商品事务，保存仍只更新一次版本。详情新增 `locale`、`content`、`translations`，并保留原始 `attributes` 和完整管理快照。

接入方式、语言优先级、JSON 示例、自定义属性、店铺覆盖和翻译失败行为见 [REST 多语言接入指南](rest-api-multilingual.md)。

Product REST 定向单测 11 tests / 58 assertions 已通过。多语言创建、请求体 locale 与请求头语言编辑、一次保存多个语言、自定义属性语言、409/400 回滚，以及无渠道自动创建和编辑返回 502 且不写入，已通过真实 HTTP 验证。最终主阶段为 13 次请求、47/47 项断言；手工译文配合 translate_to 的优先覆盖补充为 4 次请求、15/15 项断言；请求头选择及查询参数优先的两个只读复验也通过。 官方路由升级完成后，语言选择、手工译文持久化及文档页共 4/4 项回读通过。 环境翻译渠道记录为 0，真实自动翻译成功仍待配置后验收。API 文档页已恢复 HTTP 200、`text/html`、1,165,919 字节；独立 fresh Weline Chrome 新任务标签页 `goto` 30 秒超时或返回 `Debugger unattached`，另一入口创建内置浏览器立即返回 `Browser is not available: iab`；均未在页面 UI 发请求，在线调用尚未验收。 验收商品 324、339、340 已归档，最终版本分别为 1、9、2；应用 3、4 及令牌已撤销，旧令牌实测 HTTP 401，私有验收凭据文件已删除。

## P2C-002：Store Copy

- `Service/ProductCopyService` + `Api/Data/Copy{Draft,Preview,CommitResult}`
- `Service/ProductCopyDurableCatalogAdapter`：ready shard 上的 Repository
  preview/commit、目标事务、稳定 UUID 映射与 Store 补抄
- 三入口：`blank` / `site_pull` / `store_inherit`
- 库存默认 0；`inventory_copy_qty` 显式才复制；跨站新分类 UUID；重复 skip/update
- 指南：[`doc/copy-guide.md`](copy-guide.md)
- durable operation：`Model/ProductCopyOperation` 持久化 draft、commit
  claim、request hash 与 receipt/audit
- 向导 stub：`Weline_Websites` → Admin `StoreCopy::wizard`
- 当时验收版本：`1.0.14`；当前模块版本见文末。

## 店面目录热缓存与库存

- 首页/部件卡片走 `StorefrontCatalogViewService::publishedOffers()`（`StorefrontScopeHotCache`，约 300s fresh / 1800s stale）；PDP 走 `livePublishedOffersForProduct()` 旁路热缓存。
- `Weline_Inventory::stock_projection_changed`（Product 观察者 `InventoryStockProjectionChangedObserver`）在库存投影真正变更后调用 `StorefrontCatalogCacheCoordinator::notifyCatalogChanged`，含默认站 `website_id=0`。
- `StorefrontCatalogCacheInvalidator` 对 `website_id=0` 同样清理主题 chrome/片段缓存，不得把 0 当成空值跳过。

## 目录快照细分计时

目录快照读取继续在统一 `timing.log` 的 `trace_summary.phases` 下记录 `product.catalog.snapshot.attributes/prices/media_rows/media_url/availability/deals`，分别对应原属性读取、价格读取、媒体列表、每商品媒体地址、批量库存能力与每报价促销调用。标签仅包围原调用，保留返回与异常；记录次数和耗时而不记录业务值。它们和 options 已包含在 snapshots 父阶段中，不能重复求和；尚未覆盖的循环/覆盖合并不能仅由差额判作 CPU。

## 基础商品搜索的详情批量读取

`ProductAdminReadService::search` 在既有每批200商品内保持原身份解析和过滤，再对命中商品批量读取价格与媒体，按 Offer/Product 归组回填。原 `prices` 排序、主媒体选择、Store 选品与完整 DTO 字段/最终排序保持；零命中不读取价格、媒体或活动店铺。活动店铺列表仅在一次 search 首次命中时取得，后续批次复用函数内结果，不产生跨请求缓存。

`PriceRepository::listExplicitRows` 和 `MediaRepository::listByProductIds` 的批量读取使用现有 `fetchIterator` 后按原规则归一化/排序，允许完整批次超过 fetchArray 的10000行上限，不提高或绕过框架保护参数；媒体旧分表缺 store_id 时的既有 PDO 回退保持。所有 Website/Store 筛选参数和 private asset 解析继续走原仓储与媒体 Presenter。

## P2E-001：Cart Product 快照

- `ProductCartItemSnapshotProvider` 保留注入 catalog / resolver 的测试缝，
  正式请求由 `ProductCatalogCartItemSnapshotResolver` 读取 durable Website
  shard，不再把 `CartHarnessCatalog` 当作生产目录
- Offer/Product 均须 `published`；Store/Channel Scope 通过公开
  `StoreCatalogInterface` 解析 Store ID、状态、生命周期和 `store_mode`
- Store 选品使用 `StoreOfferRepository`；名称与 `product_type` 使用
  EAV Store→Website/locale fallback；价格使用 Price Store→Website fallback；
  首张 Media path 写入快照
- 当前币种和语言来自服务端 `RequestContext`；可选库存只通过
  `InventoryCapabilityInterface` 运行时公开 Provider 获取，Product 不引用
  Inventory 内部 Service/Model
- Product 通过模块 `provides` 注册
  `CartPriceSellabilityProviderInterface`；实现位于
  `Integration/Cart/ProductCartPriceSellabilityProvider.php`。依赖方向为
  Product→Cart API，Cart/Checkout 不再引用 Product 内部 Model、Repository
  或异常类
- `ProductCopyDurableCatalogAdapterTest` 的一次性 SQLite shard 只作隔离开发
  回归，覆盖 published、Store overlay、名称、价格、媒体、库存与 Store
  下架；正式结论使用该测试的 PostgreSQL 隔离数据库模式

## Catalog 快照选项性能诊断

`ProductCatalogCartItemSnapshotResolver::resolveCatalogOffers` 的原 `buildOptions` 调用使用框架统一 `RequestLifecycleTrace::measurePhase('product.catalog.snapshot.options', ...)` 计时。在同请求 `timing.log` 的 `trace_summary.phases` 查看调用次数、耗时与已埋点 DB/WLS 计数；该阶段已经包含在 `product.catalog.snapshots` 中，不能重复相加。它不记录选项值、不改变范围或缓存，也不跳过任何 Offer、私有检查或异常路径。

## P3C-001：Search current projection source

- `ProductSearchProjectionStream` 保存每个 Website 的 durable、单调
  `event_seq`；`website_id=0` 合法。
- `ProductSearchProjectionMutationCoordinator` 使用 Framework transaction
  coordinator，把 Product publish/SKU/versioned/lifecycle、Offer
  create/publish/versioned/lifecycle，以及 StoreProduct/StoreOffer selection
  事实变更与 source sequence 递增放在同一 Website 事务；提交后发布不可变
  `ResourceChange`。Offer 统一映射到父 Product，StoreOffer 映射到父 Product
  与目标 Store，不扩展 Search target type。
- `ProductSearchProjectionService` 从 Product shard 与公开 Store/Channel
  catalog 生成 Offer 级 current projection。已发布 Product/Offer 默认继承到
  活动 Store，StoreProduct 或 StoreOffer 显式 deselect 生成精确 Scope delete
  identity。
- internal Query provider `product_search_projection` 只开放给服务端模块，
  提供 `currentWatermark`、`snapshotWebsite` 和 `projectChange`；Search
  不直接依赖 Product 私有 Model。
- `ProductSearchProjectionServiceIntegrationTest` 使用真实 SQLite Product
  九表验证 durable stream、双 Store/Channel Offer projection、两级 deselect、
  四个 Repository 的父 Product 事件映射和事务回滚。
- 当时验收版本：`1.0.15`；当前模块版本见文末。

## P3C-002：Search degraded direct read 边界

- Search 的正式 direct adapter 位于 `Weline_Search`，只通过
  `ProductSearchProjectionSourceInterface::snapshotWebsite()` 消费 Product
  current；Product 不引用 Search Service/Model。
- snapshot 的 `source_watermark`、`snapshot_hash`、`document_count` 与
  documents 是防假空证据。Search 必须自行执行完整
  Website/Store/Channel/locale/currency 过滤，并在 Product 契约或读取失败时
  fail-closed。
- Product current 仍是商品目录事实源；Search marker、rollout 和 alias
  不得反向改写 Product。

## 验证

```bash
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Model/ProductShardKeyTest.php
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Model/ProductShardRegistryTest.php
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Service/ProductShardProvisionerTest.php
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Service/ProductShardProvisionerIntegrationTest.php
php bin/w setup:di:compile
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Service/ProductShardProviderDiscoveryTest.php
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Api/ProductIdentityTest.php
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Service/SkuRegistryServiceTest.php
php bin/w phpunit:run --name=app/code/Weline/Product/Test/Unit/Service/SkuRegistryServiceIntegrationTest.php
php bin/w phpunit:run --name=WebsiteShardModelTest
php bin/w phpunit:run --name=CatalogOverlayResolverTest
php bin/w phpunit:run --name=ResolvedScopeValueTest
php bin/w phpunit:run --name=CatalogRepositoryIntegrationTest
php bin/w phpunit:run --name=ProductProviderRegistryTest
php bin/w phpunit:run --name=ProductCopyDurableCatalogAdapterTest
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php \
  app/code/Weline/Product/Test/Unit/Service/ProductSearchProjectionServiceIntegrationTest.php
```

`ProductShardProvisionerIntegrationTest` 使用一次性 SQLite 文件做可移植性
开发回归，并验证
`website_id=0` 的九表创建、真实注册表状态、重复执行零新增 DDL、数据保留与临时文件清理。
新站点必须先通过 `php bin/w setup:upgrade` 安装并启用 `Weline_Product`；
仅执行 DI 编译不会把未启用模块加入 extends 注册表。

正式 `TEST-P2A-02` 由
`Websites/Test/e2e/frontend/plan-commerce-kernel-dual-scope.spec.js` 在默认
PostgreSQL 上创建两个真实 Website shard，制造 A 站索引 drift 后验证 A
进入维护、B 仍为 ready，且 `product_shard_status.current` 回传
`database_driver=pgsql`。该 Query 只读当前可信 Website，不接受客户端
Website ID，也不在请求路径执行 DDL。

`SkuRegistryServiceIntegrationTest` 使用一次性 SQLite 文件，验证 128 位
request hash、64 位 CAS token、claim/replay/conflict、rename/alias、ref_count CAS、underflow、
tombstone、alias 永久保留、SKU 禁止复用与临时文件清理。
`ProductIdentityCutoverIntegrationTest` 使用同一一次性 SQLite 数据库贯通
legacy claim、幂等迁移、dual read、逐行验证、源摘要失效、V2 权威、旧写
门禁、兼容 resolver 与 dual/legacy rollback；它是开发证据。登记的 full
clone 已另行完成 26 Product / 26 Offer 的 apply/verify/cutover/rollback/
final cutover，最终为 `v2_authoritative` version 6、0 missing、0 conflict；
独立 WLS 与 Browser 仍是 M6 未完成门禁。
MIG-P2-ORDER 还必须在登记的 PostgreSQL 隔离 clone 上以两个独立进程同时
claim 同一新 SKU、不同 hash，验收恰好一个成功、另一个稳定返回
`sku_request_hash_conflict`，且 registry 仅一行、无孤儿。

`CatalogRepositoryIntegrationTest` 使用一次性 SQLite 做隔离开发回归，按真实 shard catalog
创建 Website `0` 与普通 Website 的物理表，验证 Store→Website fallback、
跨站隔离、EAV/Price cleared、Store-only overlay、Product/Offer owned publish
CAS，以及 Media blob owner/ref_count/COW owner promotion。

`ProductProviderRegistryTest` 验证 capability 小接口发现、重复 code/type
硬失败、required contract 注册快照、权威 metadata 防伪、capability
声明/实例一致性、坏扩展 fail-fast，以及禁用 custom 后 default simple
继续；不调用 Renderer dispatch。

`ProductCopyDurableCatalogAdapterTest` 默认使用一次性 SQLite 双 Website
shard 做隔离开发回归；正式矩阵必须注入任务独占、验收后删除的 PostgreSQL
测试库。用例验证
验证 Store overlay、跨站新 Category UUID、Product/Offer 去重、字段包、
媒体、库存默认 0、receipt 重放/冲突、目录与库存共同失败回滚，以及
Cart Product durable 快照解析。

## 商品目录媒体批量读取

目录快照先建立完整的首图引用 map，再一次调用
`StorefrontProductMediaUrlResolver::resolveReferences()`。可选的
`FileAssetBatchUrlResolverInterface` 把原始文件及语言记录查询集中在
FileManager；未实现该能力的第三方 manager 继续原单项路径。

保留 UUID v4 检查、非 asset 引用透传、不可用图片空字符串、请求语言到
`en_US` 再到原网站默认语言的候选顺序，以及完整 website/store/channel
`ScopeIdentity` 和逐项 `PUBLIC_PUBLISH` 权限。每项 URL 独立生成，不复用后台
展示 URL，也不新增业务缓存。详情页的原媒体入口保持原有职责。

`product.catalog.snapshot.media_url` 仍覆盖全部媒体 URL 工作，但 `calls`
现在记录批次数，目录重建时为一次，不再等于逐商品调用次数。比较前后性能
应查看整批 duration、DB spans 和真实图片结果；不能仅凭 calls 变小宣称提速。

定向用例覆盖跨 200 项分块、语言/范围/权限、私有 URL 及旧接口兼容。
真实验收须核对商品页完整图片、价格、库存和语言输出，并完成 Browser
排序/筛选/分页操作；完整 WLS 冷请求低于 1 秒的目标仍在进行中。
交付地址：[商品页](https://p05113ef3.test.weline.com:9555/en_US/products)。

当前模块版本：`1.0.185`。V1 `ProductIdentityResolverInterface` 继续兼容读取，
新 Product/Offer 身份、后台命令/读模型、五类 Provider 与 Search/Cart 等消费链
统一使用 V2 契约。


## 万能产品 v1.0（权威实施入口）

- 权威计划：[`万能产品完善计划.md`](./万能产品完善计划.md)。
- 全局身份：Product 与 Offer/SKU 使用独立 V2 注册表；旧 `SkuRegistry` 仅承担兼容读取与幂等迁移输入。
- 标准类型：`simple`、`configurable`、`virtual`、`downloadable`、`bundle`。
- Website 是产品经营事实边界，Store 默认继承并可覆盖；跨 Website 复制同一身份但业务数据独立，不自动同步。
- 发布通过 `ProductProviderV2Interface` 统一诊断；显式零价有效，零库存可发布但不可购买。
- 非生产迁移入口：`php bin/w commerce:migrate-product-v2 inventory|dry-run|apply|verify|cutover|rollback`。写状态操作仅允许当前数据库为明确的 `mig_clone_*`；`cutover` / `rollback` 必须提供 `--expected-version`。
- 2026-08-26 数据库证据：完整 `setup:upgrade` 退出 0；V2 身份切换最终 26/26、0 冲突；Product 创建原子回滚 1/10、跨站复制 PostgreSQL 4/88；Product 全量 205/1485（1 skip、1 PHPUnit deprecation）。用于复制矩阵的临时 full clone 和随机测试 schema 均已清理。
- 当前运行阻断：独立 WLS Worker 因 managed-child Master lease owner evidence 不可观察而退出，HTTP/TLS reset/timeout；因此后台 ACL/CSRF、五类真实前后台路径和 375/768/1024 Browser 仍未验收。
- 完成状态以计划 M6 的 PostgreSQL、真实 HTTP/ACL/CSRF、独立 WLS Browser 和数据库断言为准；未全过不得标记 ACCEPTED。
