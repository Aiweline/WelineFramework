# Weline_Subscription（P4B）

## 冻结

| 项 | 值 |
|---|---|
| owning module | `Weline_Subscription`（禁止塞入 Order/Payment 内部） |
| rollout | `SubscriptionRolloutGate`；capability=`subscription`；默认 **mode off** |
| website | `website_id=0`（default）合法 |
| store | Subscription 冻结 `store_id`；tombstone/disabled Store 禁止新续费 |
| 周期 Order | **每 period_key 恰一个新 Order**；禁止复用上期 Order |
| scheduler | worker lease CAS；双 worker 不重复出账 |
| Payment | 只经 `PaymentFacadeV2Interface`；sandbox；unknown 占位且只 query |
| mode off | **停新 tick**；失败 Period 仍可 `recover`；已出账 Period/Order 保留 |
| MIG | 仅 registry 登记的 `full mig_clone_*`；checkpoint 后 backfill；fresh verify 后才 allowlist |
| 账户 UI | 必须走 `account.sidebar` / `account.sidebar.content` |

## 组件

| 路径 | 职责 |
|---|---|
| `Api/SubscriptionFacadeInterface` | create/get/assertOwner/cancel 跨模块小接口 |
| `Model/Subscription` | Subscription identity、owner、Provider/plan、幂等与取消 CAS |
| `Model/SubscriptionPeriod` | period identity、状态、Order 引用与 period CAS |
| `Model/SubscriptionSchedulerLease` | 每 Subscription 唯一 lease 与 fencing token |
| `Model/SubscriptionBillingAttempt` | Attempt/Order/Payment 引用、active guard 与 CAS |
| `Model/SubscriptionMissedWatermark` | 单调 missed period 水位 |
| `Service/SubscriptionStore` | 生产 ORM Store；memory 仅允许显式 `forTesting()` |
| `Service/SubscriptionPeriodStore` | 生产 ORM Period Store；双唯一键与条件更新 |
| `Service/SubscriptionService` | 原子创建首周期、读取、ownership 与 cancel facade |
| `Api/SubscriptionOrderPortInterface` | Order 创建端口（不碰 Order 内部） |
| `Api/SubscriptionPaymentPortInterface` | Payment start/query 脱敏端口 |
| `Service/OrderFacadeSubscriptionOrderPort` | 生产 Order Facade adapter |
| `Service/PaymentFacadeSubscriptionPaymentPort` | 生产 Payment V2 adapter |
| `Service/SubscriptionStoreEligibilityService` | Store lifecycle fail-closed guard |
| `Service/SubscriptionSchedulerLeaseStore` | 生产 ORM scheduler lease |
| `Service/SubscriptionBillingAttemptStore` | 生产 ORM Attempt 日记 |
| `Service/SubscriptionMissedWatermarkStore` | 生产 ORM missed watermark |
| `Service/SubscriptionSchedulerService` | tick / recover / query reconciliation |
| `Service/SubscriptionRolloutGate` | SystemConfig 持久 mode/精确 Website allowlist |
| `Queue/SubscriptionRenewalConsumer` | Queue tick/recover adapter |
| `Service/SubscriptionShadowComparator` | MIG shadow 守恒比对 |
| `Service/SubscriptionMigrationService` | MIG-P4B cutover |
| `Console/Commerce/MigrateP4bSubscription` | CLI `commerce:migrate-p4b-subscription` |
| `view/hooks/account.sidebar*.phtml` | Customer 账户布局入口 |

## P4B-001 持久化合同

- `subscription_id` 与 `idempotency_key` 全局唯一；同一
  `(customer_id, website_id, plan_code)` 只能存在一个 Subscription。
- create 与首个 Period 共用数据库连接和事务；任一保存失败必须零部分写。
- cancel 必须以 `subscription_id + version + cas_token` 条件更新；陈旧
  actor、非 owner 和重复取消均 fail closed。
- `period_key` 与 `(subscription_id, period_index)` 双唯一；
  Period 的 Order/missed 状态更新使用 `period_version + cas_token`。
- capability mode off 禁止新写，但不阻断既有 Subscription/Period 查询。

## P4B-002 Scheduler/Payment 合同

- 新续费严格按 `mode → lease → Store lifecycle → Subscription version
  CAS → Attempt → Order → Payment` 执行；cancel 与 scheduler 共用 aggregate
  version fence，先成功的 CAS 决定当前周期是否可建新义务。
- lease、Attempt 和 missed watermark 的生产默认均为 ORM；数组实现只允许
  `forTesting()`。
- `pending/unknown` Attempt 保持 `(period_key, active_guard)` 唯一占位。
  scheduler 重入必须 query 同一 Payment Intent，不得创建替代 Order 或扣款。
- 一个周期只绑定一个 Order；不同周期必须使用不同 Order UUID。权威 failed
  可形成后续编号 Attempt，但已有 unknown 不能被 failed/retry 合并。
- Store tombstone/disabled 后不创建新 Attempt/Order/Payment；已有 billed
  Period、Order、Payment/退款义务不删除且仍可查询/推进。
- Queue consumer 只依赖 `QueueConsumerInterface` /
  `QueueTaskContextInterface`，不直接访问 Queue ORM。

## MIG-P4B Cutover 合同

- `preflight/apply/verify/allowlist/rollback` 都必须绑定 migration registry
  登记的 `full` clone；共享库、未登记 clone、schema-only clone 在写前拒绝。
- apply 在首个 rollout/Period/watermark 写前持久 checkpoint，冻结目标
  fingerprint、schema fingerprint、排序事实 hash 和预期 backfill 结果。
- `current_period_index` 是下一到期槽；只补它之前缺失的历史 Period。
  每个 gap 形成独立 `missed` Period，原因
  `migration_backfill_gap`；watermark 按 period index 单调推进。
- migration 不调用 scheduler，不创建 Order、Payment Intent 或 Payment
  Attempt。现有 Period/Order/Attempt 引用只参加守恒校验，不替换、不合并。
- apply 结束只保持 `shadow`。`allowlist` 必须携带 checkpoint 并通过 fresh
  journal/clone 重读，只能放量 checkpoint 冻结的 `website:{id}`。
- rollback 校验 checkpoint、fingerprint 和当前守恒后持久 mode off；
  它允许 allowlist 期间合法新增的交易事实存在，但切换动作本身不得改变
  Period/Order/Attempt，且既有 obligation 仍可 `recover`。
- CLI 成功返回 `0`，任何缺参、scope 漂移、事实漂移或 clone 不合格返回 `2`。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Subscription/Test/Unit/bootstrap.php \
  app/code/Weline/Subscription/Test/Unit/Service/

php bin/w setup:schema:check Weline_Subscription
php bin/w architecture:check --json
php bin/w frontend:check-section-code
php bin/w queue:collect

php bin/w commerce:migrate-p4b-subscription help
php bin/w mig:foundation clone-create --mode=full --purpose=p4bsubscription
php bin/w commerce:migrate-p4b-subscription preflight \
  --database=mig_clone_p4bsubscription_... --website=0
php bin/w commerce:migrate-p4b-subscription apply \
  --database=mig_clone_p4bsubscription_... --website=0
php bin/w commerce:migrate-p4b-subscription verify \
  --database=mig_clone_p4bsubscription_... --checkpoint=p4bsub-...
php bin/w commerce:migrate-p4b-subscription allowlist \
  --database=mig_clone_p4bsubscription_... --checkpoint=p4bsub-... --website=0
php bin/w commerce:migrate-p4b-subscription rollback \
  --database=mig_clone_p4bsubscription_... --checkpoint=p4bsub-...
```

模块版本：`2.3.0`。`TASK-P4B-001..002 = ACCEPTED`；
`TASK-MIG-P4B = ACCEPTED`；独立聚合复验后 `GATE-P4B = GO`。
