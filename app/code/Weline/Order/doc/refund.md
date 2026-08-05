# 退款 RefundCase / PaymentRefund（P2F-005）

## 生产编排

| 模块 | 持久事实与职责 |
|---|---|
| `Weline_Order` `RefundCase` | 顾客意图、服务端重算的金额/数量、顾客投影、版本与后处理进度 |
| `Weline_Payment` `PaymentRefund` | 退款额度占用、渠道细状态、Provider 幂等键、unknown/迟到成功事实 |
| `Weline_Order` `RefundOutbox` | Provider 提交/查询、库存返还、资产返还、通知与人工复核任务 |
| `Weline_Order` `OrderAssetAllocationSnapshot` | 支付成功时冻结的不可变资产 tender 分配 |
| `Weline_Inventory` `InventoryLedger` | 未发货实物行的幂等 `refund_return` 返库事实；P3A 可携原 `warehouse_id` |

生产入口是 `OrderRefundCoordinator`，队列入口是
`OrderRefundOutboxConsumer`。正式路径只使用 ORM、Framework transaction
和 `Weline_Queue`；`RefundCoordinatorHarnessCatalog` 仅供显式单元测试，
不得作为 HTTP、跨 Worker 或生产状态存储。

## 事务与外部调用

1. 第一事务锁定 Order、OrderItem、RefundCase、PaymentRefund，服务端重算
   可退数量/金额并持久占额，同时写 `refund_provider_submit` outbox。
2. Consumer 以 claim/lease 领取 outbox，提交事务后才调用 Provider。
3. 第二事务单调写入渠道结果和顾客投影；`pending/unknown` 继续排队查询，
   `succeeded` 生成确定性的库存/资产/通知 outbox。
4. 每个副作用使用稳定幂等键；进程在任意提交边界崩溃后均可安全重放。

P3A-002 下，RefundCase 创建时由 `OriginalWarehouseLocator` 读取
FulfillmentUnit 的 Offer allocation：`legacy_default` 仍回 P2 Store logical
stock；`warehouse` 调用公开 `WarehouseInventoryCapabilityInterface` 回原
WarehouseQuota。来源缺失或多仓歧义固定 `BLOCKED_AUTHORIZATION`，禁止猜仓。

Provider 网络调用绝不能包在数据库事务内。现金退款 ledger、库存 ledger
和后处理 outbox 都以业务幂等键去重。

## CustomerAsset 退款拆分（P4D-002）

Payment 资产 commit 成功时，Order 通过
`OrderAssetAllocationSnapshotService` 按 allocation code 保存不可变原分配。
`RefundCase` 为每次退款冻结：

- `amount_minor = cash_amount_minor + asset_amount_minor`；
- `asset_allocations_json` 中的本次 delta 与累计目标；
- 原 reservation/customer/website/namespace/currency/precision。

部分退款按原订单总额与原资产 tender 比例计算累计 minor-unit 目标，再按
稳定 allocation code 顺序分摊。本次 delta 由“新累计目标 - 已冻结累计目标”
得到，因此请求切块和重试不会改变最终结果。

- 全现金：保留现有 provider refund outbox。
- 混合：Provider 只退 cash tail；成功后资产返还走独立
  `asset_return` outbox。
- 全资产：不创建 Provider refund command，RefundCase 直接进入持久化
  post-cash-equivalent outbox。
- 资产返还首败只重试 `asset_return`，已成功的现金 Provider outbox 保持
  `done`，不能再次调用。

## 额度不变量（minor unit）

`succeeded + submitted + pending + unknown + current <= captured_amount`

- `submitted/pending/unknown` 持续占额。
- 仅权威 terminal `failed` 释放。
- Provider 重试必须复用已持久化的 `provider_idempotency_key`。
- 释放后迟到 `success` 进入 `refund_late_success_review`：写
  `external_observed` ledger、冻结该 Order 新退款，并生成 urgent 人工复核。
- 请求金额、数量、运费均由服务端 Order/OrderItem 快照重算，不信任客户端
  汇总值；未发货实物可返库，已发货行不可自动返库。

## 顾客展示与权限

| 渠道状态 | `customer_view` |
|---|---|
| `submitted` / `pending` / `unknown` | `processing` |
| `succeeded` | `succeeded` |
| `failed` | `failed` |

前端 `refund` QueryProvider 只发布
`customerView(refund_case_uuid)`，要求 `auth=customer`、`mode=read`，并再次
校验 RefundCase 所属 Order 的 `customer_id`。退款申请、Provider 回写、
占额查询和测试造数均不是浏览器操作。

## 回滚

设置环境变量 `WELINE_ORDER_NEW_REFUNDS_ENABLED=0` 后只停止创建新退款；
既有 `submitted/pending/unknown` 的 Provider 查询、对账、后处理和顾客只读
查询必须继续运行。不要通过停 Consumer 或删除 outbox 回滚。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php \
  app/code/Weline/Order/Test/Unit/Service/OrderRefundCoordinatorTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php \
  app/code/Weline/Order/Test/Unit/Query/OrderRefundQueryProviderAuthorizationTest.php
WELINE_CUSTOMER_ASSET_TEST_DATABASE=<registered-migration-clone> php \
  vendor/bin/phpunit \
  --bootstrap app/code/Weline/CustomerAsset/Test/Unit/bootstrap.php \
  app/code/Weline/CustomerAsset/Test/Integration/CustomerAssetPaymentRefundPostgresqlIntegrationTest.php
P2F005_TEST_DATABASE=<registered-migration-clone> php \
  dev/ai/codex/tasks/2026-07-27/2026-07-27-1112-p2f-005-persistent-refund-orchestration/verify-persistent-refund.php
php bin/w e2e:run \
  app/code/Weline/Order/Test/e2e/frontend/plan-refund02-pending-customer-view.spec.js \
  --project=chromium --headless
```

隔离数据库脚本覆盖 TEST-REFUND-01～06：20 并发占额、pending/unknown
顾客投影、现金与库存/资产副作用重放、部分退款重算、崩溃/查询故障注入、
Provider 幂等键稳定及迟到成功。E2E 只验证正式 HTTP 暴露面和认证边界，
不会通过前端接口改写退款事实。

P4D-002 PostgreSQL 跨模块用例固定验证 `200 = 140 cash + 60 asset`，
资产返还首败/重试后 Provider outbox 仍为 `done/attempt_count=1`，并核对
CustomerAsset ledger/reservation、PaymentAllocation、Order snapshot 与
RefundCase 行级守恒。

模块：`Weline_Order` `2.12.4`。
