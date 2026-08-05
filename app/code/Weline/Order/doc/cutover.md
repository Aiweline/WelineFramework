# Order Cutover / MIG-P2-ORDER（DEC-023）

Checkout→Order **单写切流**：机制（P2D-004）+ 实际 apply（`TASK-MIG-P2-ORDER`）。

## 组件

| 类 | 职责 |
|---|---|
| `OrderCutoverGate` | mode：`off` / `shadow` / `allowlist` / `on`；`executeCutover` 需 `production_on_token`；`cutoverApplied` 后永久禁旧 writer |
| `OrderWriterGuard` | 禁旧/禁新 writer；由 DI 共享，不保存进程级 static 可变桥 |
| `AssertLegacyCheckoutWriter` | 监听 Checkout 同步 critical 前置事件，避免 Checkout 直接引用 Order 内部 Service |
| `OrderCompatibilityReader` | 接收 caller 提供的 legacy rows/new UUIDs 做只读聚合；新 reader 非 not-found 故障不静默回旧事实 |
| `OrderShadowComparator` | 只允许 `mode=shadow`；仅调 `OrderFacade::plan`；完整 item/金额/owner/warnings diff，并消费五通道副作用快照 |
| `OrderCutoverMigrationService` | preflight / apply / verify / rollback（隔离 `mig_clone_*`） |
| `MigrateP2Order` | CLI `commerce:migrate-p2-order` |

## Legacy writer 接线

Checkout 在身份标准化后、业务校验和 `beginTransaction()` 前派发：

```text
Weline_Checkout::checkout::legacy_writer::assert
  -> delivery=sync
  -> failure=critical
  -> Weline\Order\Observer\AssertLegacyCheckoutWriter
  -> OrderWriterGuard::assertLegacyCheckoutWritable()
```

该链路不使用 `class_exists()`、不引用其他模块内部 Service，也不把 guard
保存到进程级 static。`website_id=0` 是合法 subject `website:0`；allowlist
模式缺失 subject 时 fail-closed。

## Shadow 防假绿

`OrderShadowComparator::compare()` 第三个参数必须提供 monotonic snapshot：

```php
[
    'dml' => 0,
    'lock' => 0,
    'reservation' => 0,
    'outbox' => 0,
    'cache' => 0,
]
```

快照只包围新侧 `OrderFacade::plan()`；legacy path 在快照关闭后执行。任一
新侧 delta 非零都会返回 `new_side_effect:{channel}`，不能只凭 Order 内存
行数为零签发通过。计划比对包含完整商品身份、数量、单价、行金额、配送属性、
拆单金额、运费 owner 与 warnings；仅 item 数量相同不再视为相等。

## Mode 语义

| mode | Legacy Checkout writer | OrderFacade::create | OrderFacade::plan |
|---|---|---|---|
| `off` | 允许* | 允许（构建期） | 允许 |
| `shadow` | 允许* | **禁止** | 允许（纯计算） |
| `allowlist` | 名单外允许 / 名单内禁止 | 名单内允许 | 允许 |
| `on` | **禁止** | 允许（需 prod token 设 mode） | 允许 |

\*一旦 `cutoverApplied=true`，**任何 mode 下旧 writer 均禁止**（DEC-023）。

## CLI

```bash
php bin/w commerce:migrate-p2-order help
php bin/w mig:foundation clone-create --mode=full --purpose=p2order
php bin/w commerce:migrate-p2-order preflight \
  --database=mig_clone_p2order_...
php bin/w commerce:migrate-p2-order apply \
  --database=mig_clone_p2order_... \
  --production-on-token=<one-time-token>
php bin/w commerce:migrate-p2-order verify \
  --database=mig_clone_p2order_... \
  --checkpoint=p2ord-...
php bin/w commerce:migrate-p2-order rollback \
  --database=mig_clone_p2order_... \
  --checkpoint=p2ord-...
```

规则：

- 所有动作必须指向 migration registry 已登记的完整隔离 clone；共享
  `weline`、未知 clone、指纹漂移均硬拒绝。
- preflight 直接读取带配置前缀的 Product shard、旧 Checkout Order、新
  Order 与 CheckoutGroup 物理表；Product shard 未 ready 时不得 apply。
- apply 必须显式传一次性 token；token 只用于授权，不进入输出或 journal。
- apply 生成不可变 manifest/checkpoint，记录 schema 指纹、行数、摘要和
  watermark；verify 必须在新进程用 checkpoint 重新连接数据库并复核 journal。
- rollback 默认只切 `shadow` 关闭新交易；若 checkpoint 后已有新 Order，
  `off` 必须返回 `order_cutover_rollback_would_hide_new_orders`；任何路径都
  不得恢复旧 Checkout writer。
- TEST-MIG-P2-04/05/06（真实 PostgreSQL SKU 双进程竞争 / Store copy
  库存默认 0 / registry、Website、Store 同事务回滚）必须在迁移任务中
  重新执行，不得引用历史绿灯代替当前证据。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php \
  app/code/Weline/Order/Test/Unit/Service/OrderCutoverGuardTest.php \
  app/code/Weline/Order/Test/Unit/Service/OrderCutoverMigrationServiceTest.php
```

模块版本：`2.11.6`。
