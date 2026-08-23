# Weline_Subscription — AI INDEX

- Docs：[`subscription.md`](subscription.md)
- Api：`SubscriptionFacadeInterface`、`SubscriptionProviderInterface`、
  `SubscriptionOrderPortInterface`、`SubscriptionPaymentPortInterface`
- Models：`Subscription`、`SubscriptionPeriod`、`SubscriptionState`、
  `SubscriptionSchedulerLease`、`SubscriptionBillingAttempt`、
  `SubscriptionMissedWatermark`
- Service（P4B-001）：`IntervalSubscriptionProvider`、`SubscriptionProviderRegistry`、`SubscriptionStore`、`SubscriptionPeriodStore`、`SubscriptionOwnershipService`、`SubscriptionCancelCasService`、`SubscriptionService`
- Service（P4B-002）：`OrderFacadeSubscriptionOrderPort`、
  `PaymentFacadeSubscriptionPaymentPort`、`SubscriptionStoreEligibilityService`、
  `SubscriptionSchedulerLeaseStore`、`SubscriptionBillingAttemptStore`、
  `SubscriptionMissedWatermarkStore`、`SubscriptionSchedulerService`
- Queue：`SubscriptionRenewalConsumer`
- Service（MIG）：`SubscriptionRolloutGate`、`SubscriptionMigrationService`、
  `SubscriptionShadowComparator`
- Console：`commerce:migrate-p4b-subscription`（`Console/Commerce/MigrateP4bSubscription`）
- Hooks：`account.sidebar`、`account.sidebar.content`、`Weline_Subscription::frontend::account::index::subscriptions`
- Rollout：capability=`subscription`；持久配置
  `commerce.rollout.subscription`；allowlist subject=`website:{id}`
- Tests：P4B-001 durable/CAS（`SubscriptionDurableModelCasIntegrationTest`）；
  P4B-002 fresh-instance/unknown（`SubscriptionSchedulerPersistenceIntegrationTest`）、
  scheduler matrix、Order/Payment adapter 和 Queue contract；
  MIG checkpoint/backfill/fresh verify/rollback（`SubscriptionMigrationServiceTest`）
- Status：`TASK-P4B-001..002 = ACCEPTED`；`TASK-MIG-P4B = ACCEPTED`
- Gate：`GATE-P4B = GO`；总聚合 `GATE-P4 = GO`；下一前沿为独立
  `PG-8` 复验，当前 `PG-8 = NO-GO`

<!-- weline:module-doc-baseline:start -->
## 固定模块文档

- [功能现状](功能现状.md)：当前版本、代码能力面、主要入口与未验证边界。
- [需求](需求.md)：已确认需求、文档基线与待确认产品语义。
- [开发日志](开发日志.md)：目标版本进度、证据和交付状态。
<!-- weline:module-doc-baseline:end -->
