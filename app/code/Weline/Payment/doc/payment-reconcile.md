# Payment 对账 / correlation / metrics（P2F-007）

## 运行口径

对账服务直接读取当前进程所连接数据库中的 Payment ORM 事实，不使用进程内 seed 或测试态缓存。所有扫描都必须指定 `ScopeIdentity`；后台 Dashboard 使用只读 `inspect()`，CLI `dry-run` 还会把证据写入 `weline_payment_reconciliation_audit`。

correlation 链为：

`request → checkout_group → order → payment_intent → payment_attempt → provider_event → inbox/outbox → refund/invoice/fulfillment`

单次扫描最多读取 5000 条事实、返回 200 条差异。命中上限时报告 `scan_truncated=true`，不得把截断结果解释为“无异常”。

## 不变量

| code | 含义 | 自动修复 |
|---|---|---|
| `succeeded_attempt_missing_effect_outbox` | succeeded Attempt 缺后续 effect outbox | 是 |
| `paid_order_missing_invoice_effect` | paid Intent 缺 invoice effect | 是 |
| `successful_transaction_payable_not_paid` | 成功的兼容 Transaction 对应 Payable 不存在或未进入 paid | 否 |
| `inbox_received_not_applied` | inbox received 超时未 applied | 否 |
| `refund_pending_unknown_over_sla` | Refund pending/unknown 超 24 小时 | 否 |
| `attempt_reservation_lease_expired` | 非终态 Attempt 的库存预占租约已过期 | 否 |
| `outbox_pending_stale` | outbox pending 超时未处理 | 否 |
| `outbox_dead` | outbox 已进入 dead 终态 | 否 |

repair 只补 `invoice`、`fulfillment`、`notification` 三类确定性 effect。其余异常只报告、告警，由人工或对应业务流程处理。成功 Transaction 与 Payable 的一致性检查通过 `PayableResolverRegistry` 读取已发布快照；只有快照显式发布权威 `payment_status` 时才判定未支付，避免将无持久支付状态的通用 Payable 误报。Payment 不直接依赖 Order 具体类，也不会从对账流程推进业务单据状态。

## CLI

```bash
php bin/w payment:reconcile help
php bin/w payment:reconcile catalog
php bin/w payment:reconcile dry-run --scope=default.default.default
php bin/w payment:reconcile dry-run --scope=global

php bin/w payment:reconcile repair \
  --scope=default.default.default \
  --enable-repair \
  --actor-user-id=10 \
  --actor-grant-version=3 \
  --approver-user-id=11 \
  --approver-grant-version=5 \
  --approval-reference=CHG-2026-001 \
  --idempotency-key=reconcile-2026-001
```

repair 默认关闭，并按以下顺序 fail closed：

1. 禁止 Global Scope。
2. 必须显式传入 `--enable-repair`。
3. 操作者和审批者必须是两个不同且启用的后台用户。
4. 两人都必须拥有目标 Scope 的 `reconcile` 对象授权，且提交的 grant version 必须仍是当前版本。
5. 必须提供 8–128 位幂等键和外部审批引用。

服务在同一数据库事务内锁定 Attempt，并依靠确定性 effect key/唯一索引阻止重复写入。相同 Scope + 幂等键的成功请求只返回既有结果；不会重复生成 effect 或通知。

## 审计、通知与保留

- dry-run、repair 和幂等重放都保留结构化报告；修复证据保留 90 天。
- 外部审批引用只保存 SHA-256，不保存审批正文或敏感令牌。
- 每次授权判断都写安全日志，包括拒绝、操作者授权和审批者授权。
- 成功 repair 写入 urgent topic `payment.reconcile.repair`；`notify_users` 只能包含本次已重鉴权的操作者与审批者，禁止空数组广播。
- Dashboard 路由：`/{backendKey}/payment/backend/dashboard?target_scope=default.default.default`。卡片显示异常总数、逐不变量状态、correlation、扫描截断和数据源错误。

## 停用与回滚

停止所有 repair 调度，并不再传 `--enable-repair`，即可立即关闭写入能力；dry-run、Dashboard、历史审计和告警继续保留。代码回滚不得删除已经生成的审计、通知或唯一 outbox effect。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php \
  app/code/Weline/Payment/Test/Unit/Service/PaymentReconciliationServiceTest.php

php vendor/bin/phpunit --bootstrap app/code/Weline/Acl/Test/Unit/bootstrap.php \
  app/code/Weline/Acl/Test/Unit/Service/ObjectAuthorizationServiceTest.php
```
