# Warehouse / Pool / Quota / 仓维履约（P3A-001/002 + MIG）

## P3A-001 current source

| 类 | 职责 |
|---|---|
| `Model/Warehouse` | physical/logical Warehouse；同 Website+mode 默认逻辑仓 nullable unique guard |
| `Model/WarehousePool` / `WarehouseQuota` | additive pool 与 Offer minor quota/version |
| `Model/WarehouseStoreAuthorization` | 持久化 Store↔Warehouse 授权与 Store 默认仓；两组唯一约束 |
| `WarehouseAuthorizationService` | 通过 `StoreCatalogInterface` 取得可信 Website/mode/lifecycle；拒绝 normal/test 跨环境绑定（TEST-P3A-04） |
| `DefaultLogicalWarehouseResolver` | fresh ORM 读取 Store 精确默认绑定，缺省时回退 Website+environment 唯一默认逻辑仓 |

不变量：

- `website_id=0` 合法；Store 与 Warehouse 必须同 Website 且都处于可用状态。
- Store `normal→normal` Warehouse；Store `dev|test→test` Warehouse；未知 mode 拒绝。
- 调用方 `store_mode` 仅供显式 memory harness 兼容，生产写入和读取均忽略它。
- 默认绑定必须指向逻辑仓；完全相同请求幂等，第二个默认仓冲突且保留原绑定。
- 本卡不启用 Warehouse writer，不修改 Order、P3A-002 fulfillment 或 MIG-P3A cutover。

## P3A-002 current source（durable cutover flag）

Inventory 只拥有 Warehouse 库存事实，不拥有 Order/FulfillmentUnit：

| 契约 | 职责 |
|---|---|
| `DefaultWarehouseResolverInterface` | 公开读取 Store 的默认逻辑仓；实现仍由可信 Store catalog 判定 environment |
| `WarehouseInventoryCapabilityInterface::assignReservationWarehouse()` | 对既有 Reservation 做授权、幂等的 Warehouse 映射，写 `warehouse_assign` ledger |
| `WarehouseInventoryCapabilityInterface::returnCommittedToWarehouse()` | 对原 WarehouseQuota 做版本 CAS，并在同一事务写带 `warehouse_id` 的 `refund_return` ledger |

`DefaultWarehouseResolverInterface::ERROR_MISSING` 是跨模块稳定错误码。MIG-P3A
尚未建立默认逻辑仓时，Checkout/Order 只对该错误保留 P2 legacy 路径，不写
Warehouse 事实；默认仓歧义、环境不匹配、未授权或其他解析错误仍须 fail
closed，不得降级。

`WarehouseStoreAuthorization.writer_enabled` 是每条默认绑定的持久化写入
开关，默认 `0`。Order 的 mode-off 新单只记录 `legacy_default` 分配，
继续使用 P2 Store logical inventory；verify 后由 MIG 按 Website 开启时，
Checkout 才把既有 P2 Reservation 绑定到该 Warehouse，Order/FulfillmentUnit
才记录 `warehouse` 来源。已有
`warehouse` 来源的旧单可通过公开端口推进原仓退款。缺少 Store↔Warehouse
授权或 WarehouseQuota 入口时固定 `BLOCKED_AUTHORIZATION`，不得回退到其他仓。

Order 侧 FulfillmentUnit、部分发货 CAS/ledger、Offer allocation 与 refund
outbox 路由见
[`Order/doc/warehouse-fulfillment.md`](../../Order/doc/warehouse-fulfillment.md)。

## MIG-P3A（`commerce:migrate-p3a-warehouse`）

`WarehouseMigrationService` 与 `WarehouseMigrationDatabaseProbe` 只接受
migration registry 已登记的 PostgreSQL `mig_clone_*`：

1. `preflight --database=...` 读取真实 Stock/Reservation/Ledger/Warehouse/
   Authorization/Quota，输出 schema 指纹、行数/hash/watermark、冲突与逐
   Warehouse/Offer 守恒；
2. `apply --database=...` 先落不可变 checkpoint/journal，再在同一事务锁表，
   重验源快照，写 exact WarehouseQuota，并只回填 Reservation/Ledger 的
   `warehouse_id`；writer 始终保持 `off`；
3. 新 CLI 进程用 `verify --checkpoint=...` 重载 journal 并复核 immutable
   history、映射计划、守恒和目标指纹；
4. verify 成功后才允许
   `allowlist --checkpoint=... --website=0` 写 durable flag；
5. `rollback --checkpoint=...` 只清除 writer flag，保留 quota、Reservation
   映射与不可变 ledger 历史，继续向前修复。

共享库、未登记 clone、缺失 checkpoint、mode 未关闭、指纹/源快照漂移和
任一映射冲突都必须在写入前非零退出。

## 验证

```bash
vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php \
  app/code/Weline/Inventory/Test/Unit/Service/WarehouseAuthorizationAndDefaultResolverTest.php \
  app/code/Weline/Inventory/Test/Unit/Service/WarehouseAuthorizationDatabaseIntegrationTest.php
vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php app/code/Weline/Inventory/Test/Unit
php bin/w setup:upgrade --module=Weline_Inventory
php bin/w setup:schema:check -m Weline_Inventory --json
php bin/w framework:compile
```

模块版本：Inventory `2.5.5`。SQLite/内存测试覆盖授权、Reservation 映射、
原 WarehouseQuota 回库和 writer cutover；MIG-P3A 另以登记的真实
PostgreSQL schema clone 覆盖首次 apply、幂等重跑、fresh verify、allowlist、
mode-off rollback、冲突零写和 clone 销毁。
