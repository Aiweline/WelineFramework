# Order Facade API（P2D-001）

跨模块唯一订单写入边界：`Weline\Order\Api\OrderFacadeInterface`。

Checkout / Payment / Inventory **不得**引用 Order 内部 Model/Service。
模块 `provides` 已绑定
`OrderFacadeInterface => Weline\Order\Service\OrderFacade`，调用方按接口注入。

## 方法

| 方法 | 语义 |
|---|---|
| `plan(CreateCheckoutGroupCommand): OrderPlan` | 纯计算；零 DML/锁/预占/outbox |
| `create(CreateCheckoutGroupCommand): CreateCheckoutGroupResult` | 唯一 writer；`idempotency_key`+`request_hash` |
| `get(orderUuid): OrderReadResult` | 按 Order UUID 只读投影 |
| `order_admin.lookupDisplayNumber(kind, number, website, store)` | backend QueryProvider kind-qualified 查号（DEC-017）；省略 kind → `display_number_kind_required` |
| `notifyOrderPaid(orderUuid, metadata)` | 从持久化 read model 构造 `OrderPaidContext` 后触发 `OrderPostPaymentHookInterface`（默认 Noop；不改资金逻辑） |

## 幂等

- 同 key + 同 hash → 回放同一 CheckoutGroup / Orders
- 同 key + 异 hash → `order_request_hash_conflict`
- DB 唯一键竞态后重新读取既有 Group：同 hash 收敛为 replay，异 hash 保持
  `order_request_hash_conflict`，不会把正常竞争伪报成提交失败。

命令在规划和写入前 fail-fast：key 长度、64 位小写 SHA-256、三位大写货币、
Scope 范围、行数/字段长度、非负 minor-unit 以及整数运算溢出均先校验。

## 拆单与运费（DEC-015）

- 按行 `split_key` 拆成多张 pending Order（同一 Group）
- 第一张 `requires_shipping=true` 的 Order 为 shipping owner，组运费 100% 计入 owner，其余 0
- Tax `none`：服务端写零税冻结快照。
- Tax `engine`：逐行结果按稳定 `line_uuid` 一一映射；Order 税额等于其
  OrderItem 税额之和，CheckoutGroup 等于所有 Order 之和。
- 缺行、重复行、多余行、Scope/规则版本无效或任一级金额不守恒均
  `order_command_invalid`，不得静默改写快照。

## Tax 冻结读取（P3B-002）

- `OrderReadResult.tax` 返回订单级完整冻结快照。
- `OrderItem.tax_amount` 与 `tax_snapshot_json` 保存逐行税额和规则事实。
- 历史订单没有 `tax_snapshot_json` 时，只按持久化 money snapshot 合成
  `engine=none` 的 `legacy_frozen` 快照；读取不调用 Tax 引擎。
- 非零新税额必须提供完整 TaxSnapshot，禁止无来源的聚合税额。

## Checkout 预占绑定（P2E-002）

- `CreateCheckoutGroupCommand.lines[*].reservation_uuid` 是 Checkout 在同一
  默认连接事务内通过 `InventoryCapabilityInterface` 获得的服务端事实
- Order Facade 将该 UUID 写入不可变 OrderItem/Fulfillment 快照；浏览器
  不能提供或覆盖预占映射
- Checkout 的外层事务顺序为 Session lock/CAS → physical Offer reserve
  → `OrderFacadeInterface::create()` → Session submitted；任一步失败不得
  留下 CheckoutGroup、Order、reservation 或半提交 Session
- 同 quote token + 同幂等键返回原 CheckoutGroup 和同一预占映射，不重复
  创建订单或推进库存

## 入口

- Interface：`Api/OrderFacadeInterface`
- Impl：`Service/OrderFacade`（默认 DB 模式：`OrmOrderFacadeStore` 事务写
  `weline_checkout_group`/`weline_order`/`weline_order_item`/
  `weline_fulfillment_unit`，展示号走 `weline_display_number_registry`；
  内部 `OrderFacadeStoreInterface` 只用于隔离持久化与竞态测试，不是跨模块
  API；`forTesting()` 内存账本）
- DTO：`Api/Data/CreateCheckoutGroupCommand` / `OrderPlan` /
  `CreateCheckoutGroupResult` / `OrderReadResult` / `OrderPaidContext`

DB `CheckoutGroup` / `FulfillmentUnit` 与不可变快照 → 见 [`model-topology.md`](model-topology.md)（P2D-002）。

Order→Invoice Tax 复制 → 见
[`invoice-fulfillment-tombstone.md`](invoice-fulfillment-tombstone.md)。

展示号 kind / Registry / post-payment hook → 见 [`display-number.md`](display-number.md)（P2D-003）。

兼容 reader / writer guard / OrderPlan shadow / 单写切流 → 见 [`cutover.md`](cutover.md)（P2D-004 + `TASK-MIG-P2-ORDER`）。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php \
  app/code/Weline/Order/Test/Unit/Service
```
