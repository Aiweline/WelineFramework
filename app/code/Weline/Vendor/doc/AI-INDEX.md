# Weline_Vendor — AI INDEX

- Docs：[`vendor.md`](vendor.md)
- Models（P4A-001 durable）：`VendorRecord`、`VendorWebsiteAuthorizationRecord`、
  `VendorStoreAccountBindingRecord`、`VendorProductBindingRecord`
- Models（P4A-002 durable）：`VendorSplitRuleRecord`、
  `VendorSplitSnapshotRecord`、`VendorPayoutRecord`、
  `VendorRefundReversalRecord`
- Value objects：`VendorIdentity`、`VendorSplitSnapshot`
- Service（P4A-001）：`VendorRegistryStore`、`VendorAuthorizationService`、
  `VendorStoreAccountBindingService`、`VendorEligibilityService`、
  `VendorProductBindingService`、`VendorAclGuard`、`VendorService`
  - 生产默认 ORM；显式 `forTesting()` 才允许进程内存
  - `StoreCatalogInterface` 验证 Store 归属/状态/mode
  - `ProductIdentityResolverInterface` 验证 canonical Product identity
  - test/dev Store 拒绝 live 账户
- Service（P4A-002）：`VendorSplitRuleStore`、`VendorSplitSnapshotStore`、`VendorPayoutLedger`、`VendorRefundReversalService`、`VendorSettlementService`
  - production ORM；显式 `forTesting()` 才允许 memory seam
  - `vendor.split.v2` 冻结 Store-scoped account/legal/commission/currency
  - payout/reversal replay-safe、CAS/versioned，reversal 与 payout 同事务
  - reconciliation 按 Vendor/environment/Store mode 隔离
- Service（MIG）：`VendorMigrationService`、`VendorShadowComparator`、
  `VendorRolloutGate`
  - only registered full clone；stable sorted row/schema/report hashes
  - apply=`shadow`；fresh verify 后精确 Website/Store allowlist
  - checkpoint-bound mode-off rollback；既有 payout/reversal 继续
- Console：`commerce:migrate-p4a-vendor`（`Console/Commerce/MigrateP4aVendor`）
- Rollout capability：`vendor`
- Tests：
  - P4A-001：`VendorIdentityAuthorizationTest`、
    `VendorDurableIdentityIntegrationTest`
  - P4A-002：`VendorSplitPayoutReversalTest`、
    `VendorOrderGroupSettlementTest`、
    `VendorDurableSettlementIntegrationTest`
  - MIG-P4A：`VendorMigrationServiceTest`
- Current decision：`TASK-P4A-001..002`、`TASK-MIG-P4A = ACCEPTED`；
  独立 current-source Gate 复验通过，`GATE-P4A = GO`；总聚合
  `GATE-P4 = GO`；`PG-8` 仍 `NO-GO`，下一前沿为独立 PG-8 复验

<!-- weline:module-doc-baseline:start -->
## 固定模块文档

- [功能现状](功能现状.md)：当前版本、代码能力面、主要入口与未验证边界。
- [需求](需求.md)：已确认需求、文档基线与待确认产品语义。
- [开发日志](开发日志.md)：目标版本进度、证据和交付状态。
<!-- weline:module-doc-baseline:end -->
