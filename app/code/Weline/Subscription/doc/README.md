# Weline_Subscription

Subscription Provider、Customer ownership、周期身份、取消 CAS 和后续
scheduler/migration 的 owning module。

- 已验收：`TASK-P4B-001`（durable model、原子 create、ownership/cancel CAS）
- 已验收：`TASK-P4B-002`（durable scheduler、Order/Payment、Queue）
- 已验收：`TASK-MIG-P4B`（full clone、checkpoint、period/watermark
  backfill、fresh verify、Website allowlist、mode-off rollback）
- `GATE-P4B = GO`（三个任务独立验收后完成聚合复验）
- 生产 Store：ORM；memory seam 只能显式 `forTesting()`
- 续费安全序：lease → Store guard → Subscription version fence → Attempt
  → Order → Payment；unknown 只 query，不替代重扣
- rollout：`SubscriptionRolloutGate` 持久化
  `commerce.rollout.subscription`；capability=`subscription`，默认 mode off
- 详细合同：[`subscription.md`](subscription.md)
- AI 上下文：`prepare_project` 就绪后由 `resolve_task_context` 动态返回
