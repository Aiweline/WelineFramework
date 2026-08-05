# Order 模型与 CheckoutGroup（P2D-002）

## 拓扑

```text
CheckoutGroup (1) ──< Order (N) ──< OrderItem
                 └──< FulfillmentUnit ──< FulfillmentProgressLedger
```

## 新增 Model

| 表 | 类 |
|---|---|
| `weline_checkout_group` | `Model/CheckoutGroup` |
| `weline_fulfillment_unit` | `Model/FulfillmentUnit` |
| `weline_fulfillment_progress_ledger` | `Model/FulfillmentProgressLedger` |

## Additive 列

- `Order`：`order_uuid` / `checkout_group_uuid` / `website_id` / `store_id` / snapshot JSON / `is_shipping_charge_owner` / `split_key`
- `OrderItem`：`item_uuid` / `order_uuid` / `offer_id` / minor qty/price / line snapshot JSON
- `FulfillmentUnit`（P3A）：`warehouse_id` / `warehouse_source` /
  `allocations_json` / `fulfilled_qty_minor` / `fulfillment_version`
- `FulfillmentProgressLedger`：partial ship immutable idempotency event
  （见 [`warehouse-fulfillment.md`](warehouse-fulfillment.md)）

## 不可变快照 DTO

`MoneySnapshot` / `CatalogSnapshot` / `ScopeSnapshot` / `TaxSnapshot` / `ShippingSnapshot`

创建后写入 Group/Order；`CheckoutGroupInvariant::assertSnapshotFrozen` 禁止篡改。

## 组不变式

`Service/CheckoutGroupInvariant`：

- 金额守恒（订单合计 = 组 totals）
- 恰好一个 shipping owner，且承载 100% 组运费
- Group 状态：`pending → paid|cancelled`；`paid → completed|cancelled`

## Facade

`OrderFacade::create` memory 模式挂载快照与每个 physical split Order 的
pending `FulfillmentUnit` stub，校验不变式，注入失败整组回滚
（TEST-P2D-03）；纯数字商品 Order 不创建履约单元。

默认 DB 模式经 `OrmOrderFacadeStore` 在同一事务落库
Group+Order+Item+FulfillmentUnit；每个 Order 独立保存 Money/Catalog/Scope/
Tax/Shipping 冻结快照，展示号 registry 失败按 entity 补偿释放。

`OrderStateMachine` 使用 `state_version` CAS；同状态重试为零写 no-op，竞争
转换固定报 `order_state_transition_conflict`。`OrderService` 不允许通过
legacy update 改写新拓扑的 UUID、关系、金额、行或快照字段。

模块版本：`2.12.1`（需 `php bin/w setup:upgrade --module=Weline_Order`
扩展 `weline_fulfillment_unit` 并建立 progress ledger）。

展示号 Registry 见 [`display-number.md`](display-number.md)。
Cutover 机制见 [`cutover.md`](cutover.md)。
