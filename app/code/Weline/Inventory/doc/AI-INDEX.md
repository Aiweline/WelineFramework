# Weline_Inventory — AI Index

- README：`doc/README.md`
- Warehouse（P3A）：`doc/warehouse.md`
- Capability：`Api/InventoryCapabilityInterface`；
  目录复制端口 `Api/InventoryCatalogCopyCapabilityInterface` +
  `Api/InventoryCatalogCopyCapability`；
  退款返库端口 `Api/InventoryRefundCapabilityInterface`；
  仓维端口 `Api/DefaultWarehouseResolverInterface` +
  `Api/WarehouseInventoryCapabilityInterface`
- Models：`InventoryStock`、`InventoryLedger`、`Reservation`、`Warehouse`、
  `WarehousePool`、`WarehouseQuota`、`WarehouseStoreAuthorization`
- Service：`InventoryService`、`InventoryAvailabilityCalculator`、`ReservationService`、`LeaseCoordinator`、`DefaultLogicalWarehouseResolver`、`WarehouseAuthorizationService`、`WarehouseInventoryService`、`WarehouseMigrationService`、`WarehouseMigrationDatabaseProbe`、Clock
- P2B-001 durable contract：
  - `InventoryService::setOnHand/reserve` 使用 Framework DML transaction；
  - projection CAS 以真实 UPDATE 结果验权；
  - ledger 保存结果 strategy/oversell/preorder allowance；
  - replay 绑定 hash + Store/Offer/quantity，失败写整体回滚；
  - signed minor arithmetic 与 128/64 command identity 写前校验。
- P2B-002 durable contract：
  - initial lease fields 与 reserve 在同一事务插入；
  - owner/start/queued/version/expires/max 全部持久化，replay 绑定
    owner/queued/explicit start；
  - renew 与状态推进绑定 `state=reserved` 和 lease version；
  - commit/release/expire 原子写 projection + ledger + Reservation CAS；
  - Cron 按 UTC cutoff/observed version 条件 expire，续租竞争获胜则跳过；
  - 过期扫描按 expires/ID 升序，单批上限 500。
- P2F-005 refund return contract：
  - `returnCommitted()` 仅处理 Order 服务端判定的未发货实物行；
  - `refund_return` ledger 与 stock projection 在同一事务；
  - 稳定幂等键重放不重复增加 on-hand。
- P3A-001 durable contract：
  - `Warehouse` 区分 `physical|logical`，nullable unique guard 约束同
    Website+mode 最多一个默认逻辑仓；
  - `WarehouseStoreAuthorization` 持久化 Store↔Warehouse 授权及 Store 默认仓；
  - 生产授权忽略调用方 `store_mode`，只信任 Websites 发布的
    `StoreCatalogInterface`；`normal→normal`、`dev|test→test`，未知 mode fail closed；
  - 默认解析顺序为 Store 精确默认绑定 → Website+environment 默认逻辑仓；fresh
    ORM model 每次复验 Website、enabled、lifecycle、type 与 mode；
  - `DefaultWarehouseResolverInterface::ERROR_MISSING` 表示迁移前缺少默认仓；
    Checkout/Order 仅对此错误保留 P2 legacy 路径，歧义与非法解析继续阻断；
  - `website_id=0` 合法；重复绑定幂等，第二个 Store 默认仓稳定冲突且不覆盖。
- P3A-002 durable contract：
  - Reservation Warehouse assignment 以 `warehouse_assign` ledger 幂等；
  - 原仓退款对 WarehouseQuota 做 CAS，同事务写含 `warehouse_id` 的
    `refund_return` ledger；
  - 新 mutation 必须有 Store↔Warehouse 授权；既有相同 ledger replay
    不因授权后来关闭而重复写；
  - Inventory 不引用任何 Order Model/Service。
- MIG-P3A durable contract：
  - 仅 migration registry 登记 clone 可 preflight/apply/verify/cutover；
  - checkpoint 后锁表重验源快照，exact quota insert 与 Reservation/Ledger
    warehouse 回填在一个 PostgreSQL transaction；
  - apply 保持 writer off；fresh verify 成功后 allowlist 才写
    `WarehouseStoreAuthorization.writer_enabled`；
  - rollback 只关 writer，保留 immutable history 与全部映射事实；
  - conflict、shared/unregistered DB、missing checkpoint 均非零且零写拒绝。
- Cron：`Cron/ReservationExpiry`（`inventory_reservation_expiry`）
- Console：`commerce:migrate-p3a-warehouse`（`Console/Commerce/MigrateP3aWarehouse`）
- Tests：`InventoryServiceTest` + `InventoryServiceIntegrationTest`（TEST-P2B-01/02/03～06，真实 SQLite、跨实例 CAS、事务回滚）、`ReservationLeaseTest`（TEST-P2B-03～06）、`WarehouseAuthorizationAndDefaultResolverTest`、`WarehouseAuthorizationDatabaseIntegrationTest`（真实 SQLite / TEST-P3A-04）、`WarehouseInventoryServiceDatabaseIntegrationTest`（Reservation mapping + original-Warehouse return）、`WarehouseMigrationServiceTest`（TEST-P3A-01）
- Docs：[`warehouse.md`](warehouse.md)
- Current module version：`2.5.5`
