# Weline_Search：Website 分片索引与可信查询（TASK-P3C-001/002）

## 事实边界

Product published current projection 是商品目录事实源。Search 只保存可重建
投影，不得反向写 Product，也不得因 Search 不可用而把空结果伪装成成功。

当前交付分片索引、全量构建、增量追平、可信 storefront query、
Product current 直读、显式降级，以及 `TASK-MIG-P3C` 的
shadow/fresh-verify/persistent-alias cutover。迁移任务已完成工程验收；
独立重跑 TEST-P3C-01..04 后 `GATE-P3C=GO`。生产 `mode=on`、
P3A/P3B/P3C 聚合复验后 `GATE-P3=GO`；`PG-8` 仍未签署。

## 分片与 Schema

| 项 | 值 |
|---|---|
| family | `search.website` |
| shard key | `website_id` 的规范十进制字符串，`0` 合法 |
| schema version | `2.0.1` |
| provider | `extends/module/Weline_Framework/Schema/SearchShardSchemaProvider.php` |
| registry | `SearchShardRegistry`，状态与 schema fingerprint 持久化 |
| provisioner | `SearchShardProvisioner`，重复 provision 不重复 DDL |

每个 Website shard 有三张表：

- `document`：保存 Website、Store、Channel、locale、currency、generation、
  source document version、payload hash、SKU 和发布状态。
- `watermark`：保存 active/build generation、full/incremental/source
  watermark、build fencing token、schema fingerprint 与行版本。
- `applied_event`：保存 generation 内事件序列、幂等键和 payload hash。

`document` 的唯一身份包含 generation、entity、Store、Channel、locale
和 currency；相同 SKU 在不同 Store/Channel 可以有不同投影，不能互相覆盖。

## Product current source

Product 模块拥有 `ProductSearchProjectionStream` 和
`ProductSearchProjectionService`。内部 Query provider
`product_search_projection` 提供：

- `currentWatermark`：读取 Website 单调 source sequence。
- `snapshotWebsite`：读取稳定的已发布 Product current projection。
- `projectChange`：按事件目标重新投影当前 Product/StoreProduct。

Product publish、SKU 变更和 StoreProduct selection 在同一 Framework
transaction coordinator 中写目录事实与递增 source sequence；提交后发布
不可变 `ResourceChange`。Search 只通过公开 Query 契约读取 Product。

## 全量构建

`SearchIndexBuilder::rebuildWebsite()`：

1. 确认 registry ready；未 provision 的 Website 自动走正式 provisioner。
2. 读取 Product source watermark 和 snapshot。
3. 写入新的 staging generation，active generation 在此期间保持可读。
4. 再读 source watermark；发生并发变更时有界重试。
5. 在 commit fencing 内再次校验 source watermark，原子切换 active
   generation。

构建完成后 `full_watermark == incremental_watermark == source_watermark`。
旧 generation 不会被未完成构建覆盖。registry fingerprint 是构建与
watermark 的权威 fingerprint。

## 增量链路

```text
Product transaction
  → ResourceChange outbox
  → async Search observer
  → Queue createIfAbsent
  → SearchIndexIncrementalQueue
  → Product projectChange
  → DatabaseSearchIndexStore atomic apply
```

Queue content 只允许 `contract`、`event_id`、`event_seq`、`target_type`
和 `target_id` 五个字段。Website/Store 维度仅从持久 `scope_envelope`
读取；content 携带额外 Scope 字段会 fail-closed。

增量应用在一个事务内完成 applied-event 幂等、document version/hash CAS、
scope delete/upsert 和连续 watermark 推进。低版本不能覆盖高版本；同版本
不同 payload hash 是硬冲突；重复事件返回 replay，不产生第二条 Queue。

## Scope 与回滚

- `website_id=0` 是合法默认站点。
- Store/Channel 查询必须同时匹配精确数字身份，禁止跨 Scope 泄漏。
- locale/currency 只允许精确值或同时为空的中性文档；中性文档先加载，
  精确文档按 entity identity 覆盖，禁止 partial dimension。
- 浏览器 `search.search` 只接受 `q`；Website/Store/Channel/locale/currency
  全部来自服务端 `RequestContext::scopeMetadata()`。
- serving alias 按 Website 持久化 `direct/index`、generation 与 version；
  apply 后保持 `shadow/direct`，只有 fresh verify 成功后的精确 allowlist
  才 CAS 到已验证 generation。
- rollout 默认 `off`；off/shadow 或 alias direct 均保持 Product current
  直读。只有 alias index、generation 等于 active generation 且完整
  Website/Store/Channel rollout 生效时才读取 Search。
- 回滚把 rollout 写回 `off` 并按预期 reason 清理 marker；Product 事实和
  source stream 保持不变，alias 恢复 direct，Search generation 和迁移
  checkpoint/journal 保留。

## MIG-P3C shadow、验证与 alias CAS

入口命令是 `commerce:migrate-p3c-search`。迁移只允许
`mig:foundation clone-create --mode=full` 创建并登记的隔离 clone；
共享库、schema clone、未登记数据库、fingerprint 或 checkpoint 不一致
均在业务写入前退出 `2`。

迁移顺序：

1. `preflight` 绑定目标 clone，读取真实 Product/Search/Scope、watermark、
   alias 与 rollout 证据。
2. `apply` 固化不可变 manifest/journal，从 Product current 全量重建新的
   Search generation，追平增量 tip，保存逐 Website 文档摘要与 canonical
   shadow report；serving 仍为 `shadow/direct`。
3. `verify` 在新进程重新绑定 clone、加载 checkpoint 并重算 generation、
   watermarks、文档 hash 与 shadow report。任何数据篡改或观察窗变化都
   fail-closed。
4. `allowlist` 内部再次 fresh verify，随后以 expected alias/generation/
   version 做 per-Website CAS，并只启用命令指定的
   `website:store:channel`。
5. `rollback` 持久恢复 `off/direct`；重复执行幂等，不删除 Product 事实、
   Search generation 或 checkpoint。

```bash
php bin/w mig:foundation clone-create --mode=full --purpose=p3csearch
php bin/w commerce:migrate-p3c-search preflight \
  --database=mig_clone_p3csearch_... --website=0 --store=1 --channel=1 \
  --locale=zh_Hans_CN --currency=CNY
php bin/w commerce:migrate-p3c-search apply \
  --database=mig_clone_p3csearch_... --website=0 --store=1 --channel=1 \
  --locale=zh_Hans_CN --currency=CNY
php bin/w commerce:migrate-p3c-search verify \
  --database=mig_clone_p3csearch_... --checkpoint=p3csearch-...
php bin/w commerce:migrate-p3c-search allowlist \
  --database=mig_clone_p3csearch_... --checkpoint=p3csearch-... \
  --website=0 --store=1 --channel=1
php bin/w commerce:migrate-p3c-search rollback \
  --database=mig_clone_p3csearch_... --checkpoint=p3csearch-...
```

## Storefront query 与 Product current 直读

`SearchQueryProvider` 只发布一个 frontend read operation：`search(q?)`。
任何客户端 Scope 参数都会返回 `search_scope_invalid`；缺少完整 channel
Scope 也 fail-closed。

`ProductProjectionDirectCatalogReader` 通过 Search 自有接口消费 Product
公开的 `snapshotWebsite()`。每次直读结果包含：

- `direct_source_watermark`
- `direct_snapshot_hash`
- `direct_document_count`
- `direct_match_count`

因此即使命中为零，也能区分 Product current 的真实空集与基础设施异常。
Product snapshot/文档契约无效或 Product 直读不可用时返回稳定错误，禁止
用空成功掩盖故障。

## 显式降级与恢复门

`SearchRolloutGate` 读取 Env 整对象锁或 global SystemConfig，默认
`off`；allowlist 只接受完整 `website:store:channel`。只有 `allowlist/on`
会尝试 Search index。

索引未 ready、受控不可用或读取异常时，服务返回
`source=product_direct_degraded` 和稳定 reason，同时把 Website marker
写入 `search_degrade_state`。marker 保存 required Product watermark、
标记时 Search watermark、version 与 writer-owned CAS token，跨 WLS
Worker/新 PHP 进程可见。

恢复调用只有同时满足以下条件才 CAS 清除：

1. Search incremental watermark 与 Product current watermark 相等；
2. 两者都不小于 marker 的 required source watermark。

落后、超前或并发 writer 冲突均保持 active。`TASK-MIG-P3C` 后续才负责
正式 rebuild/shadow/alias cutover；本任务只交付可复用恢复门。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Search/Test/Unit/bootstrap.php \
  app/code/Weline/Search/Test/Unit
php bin/w phpunit:run --module=Weline_Search
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php \
  app/code/Weline/Product/Test/Unit
php bin/w framework:compile
php bin/w setup:di:compile
php bin/w setup:upgrade --stage=schema_diff -m Weline_Product
php bin/w setup:upgrade --stage=schema_diff -m Weline_Search
php bin/w query:help search
php 迁移前 AI 资料（已清理） --module=Weline_Search
PLAYWRIGHT_INSTANCE_NAME=ai-test-p3c002-20260728-0048 \
PLAYWRIGHT_TARGET_ORIGIN=https://127.0.0.1:9632 \
PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_WORKERS=1 \
php bin/w e2e:run \
  app/code/Weline/Search/Test/e2e/frontend/plan-p3c03-degraded-search.spec.js \
  --project=chromium --headless
```

关键集成证据：

- `SearchShardPersistenceIntegrationTest`：真实 SQLite 三表、重复 provision、
  多代 full build、乱序增量、重放、Store/Channel 隔离。
- `ProductSearchProjectionServiceIntegrationTest`：真实 Product 九表、
  source stream、双 Store/Channel 投影、deselect delete identity 和事务回滚。
- 真实 PostgreSQL + Queue：跨 PHP 进程完成 outbox → async observer →
  Search Queue → durable apply/replay，并使 full/incremental/source watermark
  收敛。
- `SearchDegradeMarkerPersistenceIntegrationTest`：两个独立 store/service
  实例共享 marker，落后水位拒绝、相等水位 CAS 清除。
- `TEST-P3C-03`：fixture 与四 Worker WLS 分属不同进程；真实
  `Weline.Api.search.search({q})` 返回 Product current degraded 证据，
  不注入 Product/Search 假行。
- `TEST-P3C-04`：真实 Product/Search 水位 `2/2`；`1/2` 解除被拒，
  `2/2` 才清除 marker。
- `TASK-MIG-P3C`：登记 full clone 上
  `preflight → apply → fresh verify → allowlist → query → rollback`
  跨进程闭环；shared/schema/unregistered clone 和错误 Scope 都在写前
  exit `2`，tamper 后 verify 拒绝，恢复后重新通过。

模块版本：`1.4.1`。
