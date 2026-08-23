# Payment Intent / Attempt / Outbox（P2F-002）

## 不变量

| Guard | 值 | 唯一索引 |
|---|---|---|
| `PaymentIntent.active_guard` | active / `NULL` | `(environment, payable_type, payable_id, active_guard)` |
| `PaymentAttempt.nonterminal_guard` | open / `NULL` | `(intent_code, nonterminal_guard)` |
| `PaymentAttempt.provider_reference_guard` | `sha256(environment\|provider_code\|merchant_account\|provider_reference)` / `NULL` | `(provider_reference_guard)` |
| Provider reference | 非空后唯一 | `(merchant_account, environment, provider_reference)` |

## 入口协调与两事务

0. `PaymentLock` 是第一事务前的短期竞争协调条件；数据库 nullable unique guard 才是最终一致性约束。锁不放进业务事务，避免唯一键竞争把 PostgreSQL 事务标记为 rollback-only。
1. **第一事务**（`PaymentIntentOrchestrator::beginStart`）：原子写 idempotency + Intent + Attempt + `PaymentProviderCommandOutbox`；**不**调用 Provider。同 key/body 回放绑定的同一 Intent/Attempt，同 key/不同有效请求哈希返回 conflict。
2. **事务外**（`PaymentProviderCommandConsumer`）：事务内用 claim token 领取 command；30 秒 claim 租约内拒绝再次出站，过期后才允许恢复。Provider 始终收到 `provider_request_key=attempt_code:submit:v1`。
3. **第二事务**：以 `attempt_code + version + old cas_token` 条件更新，再以新 writer token 回读证明 CAS 成功；同事务更新 Intent、完成 command outbox，并按 Attempt 派生稳定 ledger/effect key。成功或失败清 `nonterminal_guard`，unknown 继续占用。

Provider reference 非空时同时写规范化 guard 和原始三元组唯一键，防止跨大小写账户表示或不同写入口重复归属。

## ORM 新鲜读取

支付状态实体每次通过非共享 Model 实例读取；CAS/直接更新后的验证使用
`where(...)->find()->fetch()`，不使用业务键 `load()` 的 identity-map 缓存。
否则重试可能覆盖旧 Attempt，或把旧 command/lock 状态误当成当前事实。

## 租约

- 检查窗 5 分钟；延长至 `min(now+30m, started+2h)`。
- 硬上限后禁止续租，走 query/reconcile（禁止永久占库）。
- Provider command claim 租约固定 30 秒；与库存 reservation lease 分开。

## 当前阶段边界

- 生产 DI 已注入 `PaymentIntentPersistenceService`，但支付入口仍默认关闭。
- `PaymentIntentOrchestrator::forTesting()` 继续保留纯内存单元测试双，不是生产事实源。
- P2F-004 负责 verified webhook 消费、Inventory/Order/Group 最终状态和 effect outbox 消费；持久模式当前对 webhook transition 固定 fail-closed。

## 回滚

`setNewAttemptsEnabled(false)`：停新 Attempt；保留 query / 已提交 outbox 消费。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php \
  app/code/Weline/Payment/Test/Unit
P2F002_TEST_DATABASE=mig_clone_p2f002_... php \
  迁移前历史任务记录（已清理）
php bin/w setup:schema:check -m Weline_Payment
```
