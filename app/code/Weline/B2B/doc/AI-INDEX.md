# Weline_B2B — AI INDEX

- Docs：[`b2b.md`](b2b.md)
- Api：`B2BPriceCandidateInterface`、`B2BCheckoutRecheckInterface`
- Models（P4C-001）：`CustomerGroup`、`PriceList`、
  `CustomerGroupRecord`、`CustomerGroupMembershipRecord`、
  `PriceListRecord`、`PriceListItemRecord`
- Models（P4C-002）：`B2BQuoteToken`、`B2BOrderPriceSnapshot`、
  `B2BQuoteTokenRecord`、`B2BOrderPriceSnapshotRecord`
- Service（P4C-001）：`CustomerGroupStore`、`PriceListStore`、
  `B2BRolloutGate`、`B2BPriceEngine`、`B2BShadowComparator`、`B2BService`
- Service（P4C-002）：`B2BQuoteTokenStore`、`B2BAclGuard`、`B2BCheckoutRecheckService`、`B2BOrderSnapshotStore`
- Service（MIG）：`B2BMigrationService`
- Query（TEST-P4C-01）：`B2BQueryHarnessCatalog` +
  `B2BQueryProvider`（只读 `b2b.resolve`；夹具只能由测试进程准备/清理）
- Console：`commerce:migrate-p4c-b2b`（`Console/Commerce/MigrateP4cB2b`）
- Rollout capability：`b2b`
- Tests：TEST-P4C-01…05 / MIG-P4C；E2E
  `Test/e2e/frontend/plan-p4c01-b2b-retail-candidate.spec.js`、
  `Test/e2e/frontend/plan-p4c03-05-b2b-submit-snapshot.spec.js`
- Status：`TASK-P4C-001..002`、`TASK-MIG-P4C = ACCEPTED`；
  `GATE-P4C = GO`
- Gate：`GATE-P4C = GO`；总聚合 `GATE-P4 = GO`；下一前沿为独立
  `PG-8` 复验，当前 `PG-8 = NO-GO`
