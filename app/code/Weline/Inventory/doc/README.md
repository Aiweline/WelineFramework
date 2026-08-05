# Weline_Inventory

Store 逻辑库存：不可变 ledger、四策略可售、预占租约/CAS/Cron（P2B-001 + P2B-002）。

## 概念

- 库存键：`(website_id, store_id, offer_id)`；`website_id/store_id >= 0`（含零号站）
- 数量：正整数 **minor**（禁止小数）
- 投影表：`weline_inventory_stock`（`stock_version` CAS）
- 账本：`weline_inventory_ledger`（append-only；`event_uuid` 与
  `(idempotency_key,event_type)` 唯一）
- 每条 ledger event 同时保存结果策略、oversell/preorder allowance，
  因此数量与可售策略都具备重建投影所需事实；公开读取按 `ledger_id`
  升序返回。
- 预占：`weline_inventory_reservation`（state 单调；lease
  owner/start/queued/version/expires/max）

## P2B-001 写入不变量

- `setOnHand` 的 projection CAS 与 ledger append 在同一个 Framework DML
  transaction 中提交；CAS 失败最多重试 8 次，绝不接受“有 ledger 无投影”。
- basic `reserve` 的 stock CAS、ledger append、reservation insert 原子提交；
  任一写失败必须整体回滚，不得泄漏 `reserved_minor`。
- Checkout P2E-002 通过公开 `InventoryCapabilityInterface` 对每条需配送
  Offer 执行幂等预占，并把 `reservation_uuid` 写入 Order 行快照；
  外层默认连接事务同时覆盖预占、Order Facade 与 CheckoutSession 状态，
  注入失败时三者全部回滚。虚拟行不得预占。
- 同 `(idempotency_key,event_type)` + 同 request hash + 同请求负载只重放，
  不再次推进 `stock_version`；hash、Store/Offer 或数量漂移均 fail closed。
- strategy、on-hand、reserved、allowance 全部使用非负整数 minor；
  overlength command identity 和 signed integer overflow 在写入前拒绝。
- `website_id=0`、`store_id=0` 都是合法 Scope，不得当作空值。

## 策略

| strategy | available |
|---|---|
| `strict` | `max(0, on_hand - reserved)` |
| `oversell` | `max(0, on_hand + oversell_allowance - reserved)` |
| `preorder` | `max(0, on_hand + preorder_allowance - reserved)` |
| `unlimited` | `PHP_INT_MAX`（仍累计 reserved） |

## Lease（DEC-012）

| 规则 | 说明 |
|---|---|
| 初租/续租 | `expires = min(now+30m, lease_max_expires_at)` |
| 硬上限 | `lease_max_expires_at = attempt_started_at + 2h`，不可延长 |
| CAS | 匹配 `state=reserved` + owner + version + 未过期 |
| 排队 Order | `queued_order` 持久化；不续租；轮到前重新 `availability` / `reserve` |
| 达上限 | future start 拒绝；已达上限不新占用，返回 `inventory_lease_reconciliation_required` |
| 状态推进 | commit/release/expire 的 stock、ledger、Reservation state 在同一 DML transaction，state 使用 CAS |
| Cron | 每批最多 500 条，按 expires/ID 升序；expire 绑定扫描时 version/cutoff，续租获胜则跳过 |
| 时间 | lease 时间统一按 UTC `Y-m-d H:i:s` 持久化和比较 |

## API

`InventoryCapabilityInterface` / `InventoryService`：

- `transactional(callable)`：把同一业务目标的一组库存命令合并为一个
  原子单元；memory 模式恢复完整账本，durable 模式加入 Framework
  transaction context。回调内禁止 DDL 与跨库副作用。
- `getAvailability` / `reserve` / `release` / `commit` / `expire`
- `setOnHand` / `ensureStock`

支付回调提交公开端口：

- `InventoryReservationCommitCapabilityInterface`：只公开
  `transactional()` 与 `commit()`；Payment Consumer 通过该接口加入当前
  default connector 事务，不依赖 Inventory 内部 Service/Model。
- `commit()` 继续以 `(idempotency_key,event_type)` 唯一 ledger 保证重放
  无重复；支付侧不得另建 inventory-command outbox。

退款返库公开端口：

- `InventoryRefundCapabilityInterface`：只暴露退款编排需要的
  `transactional()` 与 `returnCommitted()`。
- `returnCommitted()` 只接受服务端判定为未发货的实物行，以稳定
  `idempotency_key` 写 `refund_return` ledger；重复消费只返回既有结果，
  不会二次增加 on-hand。
- Order 只依赖该公开端口，不得引用 Inventory Model/内部 Service；
  已发货行和虚拟行不得自动返库。

目录复制公开端口：

- `InventoryCatalogCopyCapabilityInterface`：仅暴露目录复制需要的
  `transactional/getAvailability/ensureStock/setOnHand`
- `InventoryCatalogCopyCapability`：Inventory 所属模块内的公开门面；
  Product 等调用方不依赖 `Service/InventoryService` 内部实现

编排层：

- `ReservationService`：reserve→assignLease、renew、commit/release/expire
- `LeaseCoordinator`：owner/version CAS、硬上限、排队禁续
- `Cron/ReservationExpiry`：`*/5 * * * *` 扫描过期 lease

## P3A Warehouse current source

- `Warehouse`、`WarehousePool`、`WarehouseQuota` 是 additive schema；默认逻辑仓以
  nullable unique guard 保证同 Website+mode 最多一个。
- `WarehouseStoreAuthorization` 持久化 Store↔Warehouse 授权和 Store 默认仓；
  生产授权只信任 `StoreCatalogInterface`，不信任调用方传入的 `store_mode`；
  `writer_enabled` 默认关闭，只有 MIG-P3A fresh verify 后才按 Website 开启。
- Store environment 映射：`normal→normal`、`dev|test→test`；未知 mode、跨 Website、
  disabled/tombstone Store、disabled Warehouse 和跨环境绑定全部 fail closed，失败不留行。
- `DefaultLogicalWarehouseResolver` 先读 Store 精确默认绑定，再读 Website+environment
  默认逻辑仓；fresh resolver 可恢复相同结果。`website_id=0` 始终合法。
- `DefaultWarehouseResolverInterface` 是 Order 等调用方唯一允许依赖的默认仓读取端口。
- `WarehouseInventoryCapabilityInterface` 对 Reservation warehouse assignment
  和原 WarehouseQuota 退款回库提供幂等、授权、CAS + ledger 原子写入；
  `InventoryLedger.warehouse_id` 保存原仓证据。
- 旧 `InventoryRefundCapabilityInterface` ABI 不变；mode-off 新单的
  `legacy_default` 仍走 P2 Store logical inventory，已有 `warehouse` 事实才走原仓端口。
- `commerce:migrate-p3a-warehouse` 只接受 registry 登记的隔离 PostgreSQL
  clone；apply 在 checkpoint 后锁表重验并保持 mode off，verify 必须显式
  checkpoint，allowlist 写 durable flag，rollback 只关 flag、不删事实。

模块版本：`2.5.5`。

## 验证

```bash
php bin/w setup:upgrade --module=Weline_Inventory
php bin/w phpunit:run --name=ReservationLeaseTest
php bin/w phpunit:run --name=InventoryServiceTest
php bin/w phpunit:run --name=InventoryServiceIntegrationTest
php bin/w phpunit:run --module=Weline_Inventory
php bin/w setup:di:compile
```

`InventoryServiceTest` 覆盖 `TEST-P2B-01/02` 的策略、重放、payload
绑定与溢出边界；`InventoryServiceIntegrationTest` 使用真实 SQLite 模型、
唯一索引和 Framework transaction runner，验证持久化重放、Store 隔离
以及 ledger 写失败时 projection/reservation 的整体回滚；
`ReservationLeaseTest` 与集成测试共同覆盖 owner/queued/start replay、
lease CAS/硬上限、Cron/renew 竞争，以及 commit/release 失败回滚。
