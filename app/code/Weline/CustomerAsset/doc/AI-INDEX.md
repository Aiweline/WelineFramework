# Weline_CustomerAsset — AI INDEX

- Docs：[`customer-asset.md`](customer-asset.md)
- Api：`CustomerAssetFacadeInterface`（仅服务端；bounded reads + committed return）、
  `CustomerAssetConflictInterface`（跨模块稳定冲突契约）
- Models：`AssetAccount`、`AssetLedger`、`AssetReservation`
- Service：`CustomerAssetService`、`CustomerAssetRolloutGate`、
  `CustomerAssetMigrationService`、`CustomerAssetConflictException`、
  `AccountAssetPresenter`
- CLI：`commerce:migrate-p4d-customer-asset`
- Account Hook：`account.sidebar`、`account.sidebar.content`；只消费 Customer-owned projection
- Rollout capability：`customer_asset`
- Tests：TEST-P4D-01/02/03/04/05 + MIG-P4D；registered PostgreSQL
  full clone `31/243`，含跨进程竞争、事务回滚、跨模块退款守恒、
  fresh checkpoint、durable allowlist 与 mode-off rollback
- Status：`TASK-P4D-001 = ACCEPTED`；`TASK-P4D-002 = ACCEPTED`；
  `TASK-MIG-P4D = ACCEPTED`
- Gate：`GATE-P4D = GO`；总聚合 `GATE-P4 = GO`；next=`PG-8`
  （独立复验前仍为 `NO-GO`）
