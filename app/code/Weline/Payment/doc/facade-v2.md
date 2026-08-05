# Payment Facade V2

## 目标

`TASK-P2F-001` / REQ-011：版本化 `start` / `resume` / `query`；金额与商户账户由服务端 Payable 快照冻结；旧 `PaymentFacadeInterface::tryCreatePayment()` 保持 ABI。

## 契约

| API | 说明 |
|---|---|
| `PaymentFacadeV2Interface::start(PaymentStartCommand)` | 仅 payable/method/idempotency/hash/Actor/Scope/白名单 return URL |
| `resume(PaymentResumeCommand)` | 同 Intent 恢复观测（Provider 出站归 P2F-002） |
| `query(PaymentQueryCommand)` | 按 intent 或 payable 查询 |

禁止调用方传入：`amount*`、`currency*`、`merchant_account`、Provider reference。

## CustomerAsset 分配（P4D-002）

`PaymentStartCommand` 可携带可选 `asset_requests`，但金额、币种、payer 和
scope 仍以服务端 `PayableSnapshot` 为准。执行顺序固定为：

```text
validate snapshot/payer/scope/policy
  -> CustomerAsset reserve
  -> durable PaymentAllocation
  -> cash-tail PaymentIntent/Attempt
```

- 任一资产 reserve 或分配校验失败时，现金 `PaymentAttempt` 必须为零。
- 混合支付只向 Provider 提交 `payable - asset allocation`。
- 全资产支付持久化 zero-amount intent 与 asset commit effect，不创建
  Provider Attempt/command。
- 成功终态发出 `asset:commit:v1`，失败/取消终态发出
  `asset:release:v1`；effect outbox 独立重试且不能重复调用 Provider。
- committed allocation 退款使用累计目标调用 CustomerAsset
  `returnCommitted()`；相同 effect replay 不重复写 return ledger。
- CustomerAsset 是 Composer `suggest` 可选能力；Payment 仅捕获
  `CustomerAsset\Api\CustomerAssetConflictInterface`，不依赖其 Service
  内部异常类型。未安装能力时零资产支付不受影响，有资产请求时 fail closed。
- Payment 只发布 `OrderAssetAllocationSnapshotSinkInterface`，由 Order 提供
  可选实现，避免 Payment 反向依赖 Order。

## 入口门控

默认 `entryEnabled=false`（回滚：关闭支付入口，订单保持 unpaid）。
P2F-002 已把生产 DI 接到持久 `PaymentIntentOrchestrator`；
`setEntryEnabled(true)` 仍只用于当前验收，尚未执行生产支付 cutover。
若编排器依赖缺失，`start` / `resume` / `query` 仍统一返回
`payment_orchestrator_unavailable` 的结构化 `PaymentOperationResult`，
不会抛内部运行时异常，也不会把不可用误报为支付终态。

`start()` 不直接信任调用方的 `request_hash`。Facade 会把调用方 hash 与
payable/method、冻结 Scope、Actor、return URL 白名单和服务端 snapshot
version 共同计算成有效请求哈希；同 key 改 return URL、请求体或服务端快照
均稳定返回 `payment_idempotency_conflict`。

## Order Payable

`payable_type=weline_order` →
`Order/extends/module/Weline_Payment/PayableResolver/OrderPayableResolver.php`，
由 `PayableResolverRegistry` 的真实 extends 索引自动发现。Resolver 的
`OrderFacadeInterface` 是必需依赖；`OrderReadResult.customerId` 保留 owner，
客户订单必须由同一 customer Actor 支付。

## 与 P2F-002

P2F-002 已交付 nullable guards、持久 Intent/Attempt/idempotency、带 claim
租约的 command outbox、事务外 Provider、第二事务 CAS、稳定 ledger/effect
outbox，以及跨 PHP 进程回读。`PaymentIntentOrchestrator::forTesting()` 只提供
纯内存单元测试双；生产容器解析到持久编排器。

本阶段不消费 webhook 或最终 Order/Group 副作用，这些由 P2F-004 完成；
生产入口继续默认关闭。
设计见 [`payment-state.md`](payment-state.md)。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php app/code/Weline/Payment/Test/Unit/Service/PaymentFacadeV2Test.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php app/code/Weline/Payment/Test/Unit/Service/AssetPaymentStartTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php app/code/Weline/Order/Test/Unit/Service/OrderPayableResolverTest.php
php bin/w framework:compile
php bin/w setup:di:compile
```

当前 P4D-002 Payment Unit 固定证据：`61 tests / 395 assertions`。
