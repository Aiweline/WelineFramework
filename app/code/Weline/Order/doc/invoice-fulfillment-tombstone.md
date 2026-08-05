# Invoice / Fulfillment / Tombstone / 账户汇总（P2F-006）

## Invoice

- 一张 paid Order **最多一张**最小 Invoice；数据库同时唯一约束
  `order_id` 与 `effect_key`。
- 发票由支付 succeeded 事务内已经持久化的唯一 effect outbox 驱动：
  `attempt:{attempt_code}:invoice:create:v1`，Order 不复制 Payment 内部表模型。
- `PaymentEffectOutboxProcessorInterface::process()` 锁定 pending outbox，
  在同一个 default connector 写事务内调用 `InvoiceService`，最后才将 outbox
  标记为 `done`。处理器抛错时 Invoice 与 outbox 终态一起回滚。
- 发票号由 effect key 确定性生成，金额只读冻结的
  `money_snapshot_json.grand_total_minor`，不使用 float 或当前商品价格。
- Invoice 同时复制 Order 的 `tax_amount_minor` 与完整
  `tax_snapshot_json`，并校验 Tax 总额等于冻结 money snapshot 的税额；
  开票路径不调用 Tax 引擎。
- 历史 Order 没有 Tax JSON 时，从冻结 money snapshot 合成
  `engine=none` 的 `legacy_frozen` 快照；P2 `tax_engine=none` 零税订单
  永远保持零税。
- 重试/重放必须返回同一 Invoice TaxSnapshot；已有 Invoice 的 Tax 或
  amount 与 Order 冻结事实冲突时 fail closed。
- 重试、重放和并发都回读同一张 Invoice；入口：
  `OrderPaymentEffectConsumer` → `PaymentEffectConsumer` →
  `InvoiceService::ensureFromPaymentEffect()`。

## Fulfillment

- 支付 effect `attempt:{attempt_code}:fulfillment:action:v1` 只创建唯一
  `FulfillmentAction(status=pending)`。
- “准备履约”不等于“已经发货”：此入口不创建 Shipment、不把 Order 推进到
  shipped/fulfilled；真实仓、包裹和发货状态仍由独立履约流程拥有。
- `FulfillmentAction.effect_key` 是数据库唯一键；重放只返回既有动作。

## Tombstone 历史白名单

`TombstoneHistoricalResourcePolicy` 只通过 Websites 公开
`StoreCatalogInterface` 读取不可变 `StoreSummary`。Store tombstone 后，
历史义务返回 `resource_mode=historical_only`；每个 allow/deny 决策按
Store、action、correlation key 确定性写入 `HistoricalResourceAudit`。

| 允许 | 拒绝（urgent） |
|---|---|
| refund / invoice / fulfillment / payment_query / payment_reconcile / webhook_verify | index / SEO / catalog_write / new_trade / config_distribute |

拒绝不会删除历史事实；它以稳定错误
`tombstone_historical_action_denied` 返回，并留下 `urgent=1` 审计记录。

## 顾客账户

- 必须使用 `Weline_Customer` `account.sidebar` / `account.sidebar.content`。
- Loader 只读当前 `CheckoutGroup`、`Order`、`RefundCase`、`OrderInvoice` 与
  `FulfillmentAction`，不再用旧 `OrderRefund` 伪造退款状态。
- `AccountCheckoutGroupPresenter` 默认展示 Group 汇总；退款、发票、履约或
  Order 状态出现 partial/分叉时展开子 Order。
- 未知内部状态只显示“状态待确认”，不把内部枚举或错误码泄漏给顾客。
- 默认模板：`view/hooks/Weline_Order/frontend/account/index/orders.phtml`。

## 验证

```bash
php bin/w framework:compile
php bin/w queue:collect

php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php \
  app/code/Weline/Order/Test/Unit/Service/PaymentEffectAndTombstoneTest.php \
  app/code/Weline/Order/Test/Unit/View/AccountSidebarHookTemplateTest.php
```

持久化验收必须在登记的 `mig_clone_p2f006_*` 隔离库上覆盖：

- 首次在 Invoice 写入后注入失败，断言 Invoice 不存在且 outbox 仍 pending；
- 重试与 8 并发后只有一张 Invoice，outbox 为 done；
- 通过真实 `Store::delete()` 进入 tombstone，逐项验证 allow/deny/audit；
- 关闭 automatic scan 后新 outbox 不自动消费，但明确指定的既有历史义务仍可重放。

回滚：高风险自动扫描可关闭；历史义务（refund/invoice/fulfillment/query）
必须继续，禁止通过恢复旧 writer 或删除历史事实回滚。
