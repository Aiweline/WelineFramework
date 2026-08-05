# Weline_CustomerAsset（P4D）

## 冻结

| 项 | 值 |
|---|---|
| owning module | `Weline_CustomerAsset` |
| rollout | capability=`customer_asset`；PostgreSQL durable config；默认 **mode off** |
| namespace | `live` / `sandbox` 严格隔离 |
| ledger | PostgreSQL append-only；全局唯一 event_id + canonical request hash |
| account | Customer/Website/asset/namespace 唯一；version + CAS token |
| reservation | reserve/release/commit/partial-return 单事务状态机 |
| mode off | 禁新 tender；既有 commit/release/refund-return 义务继续收敛 |
| account UI | 官方 Customer account layout Hook；服务端只读投影 |

## 组件

| 路径 | 职责 |
|---|---|
| `Model/AssetAccount` | 持久余额、identity unique 与 CAS |
| `Model/AssetLedger` | 不可变 event/hash 事实 |
| `Model/AssetReservation` | 预占与互斥终态 |
| `Service/CustomerAssetService` | 事务、幂等、余额守恒、bounded read 与 committed return |
| `Service/CustomerAssetRolloutGate` | Website 精确 allowlist 的 PostgreSQL/Env 持久门禁 |
| `Service/CustomerAssetMigrationService` | full clone 事实重放、checkpoint、fresh verify 与 mode-off rollback |
| `Console/Commerce/MigrateP4dCustomerAsset` | `commerce:migrate-p4d-customer-asset` CLI |
| `Api/CustomerAssetFacadeInterface` | Payment 资产分配与服务端只读投影契约 |
| `Api/CustomerAssetConflictInterface` | 跨模块只读冲突码/上下文契约；Service 异常类型保持模块内部 |
| `Service/AccountAssetPresenter` | 从 Customer-owned projection 读取账户与最近账本 |
| `view/hooks/account.sidebar*.phtml` | 官方账户中心资产导航与内容卡片 |

## P4D-002 编排边界

1. Payment 在创建现金 Attempt 前调用 CustomerAsset `reserve()`，并持久化
   `PaymentAllocation`；reserve 失败时现金 Attempt 为零。
2. Payment 成功/失败终态通过独立 effect 调用 `commit()` / `release()`；
   effect 重试不重复扣款，也不会再次调用 Provider。
3. 退款只允许对已 committed reservation 调用
   `returnCommitted(reservation, amount, event_id)`。该操作按 reservation
   累计返还并写唯一 `return` ledger，不能退超原 commit。
4. rollout mode off 仅阻止新的 credit/reserve tender，不阻断已存在的
   commit/release/refund-return。
5. 账户中心不新增 Controller 或浏览器业务 API。Hook 只消费
   `AccountSidebarProjectionProviderInterface::forSections('assets')` 的
   Customer/Website 上下文，服务端输出 bounded account/ledger。

Order 不可变 allocation snapshot、cash/asset 退款拆分和独立 outbox 由
`Weline_Order` 所有；CustomerAsset 不读取 Order 或 Payment ORM。

## MIG-P4D 迁移工程

### 数据库与命令边界

- 正式运行、迁移和验收均为 PostgreSQL；SQLite 仅允许隔离开发或可移植性
  辅助，不构成验收证据。
- 迁移目标必须由 `mig:foundation` registry 登记，名称为
  `mig_clone_*`，类型为 PostgreSQL，模式为 `full`。共享库、默认开发库、
  schema-only clone 和未登记 clone 在绑定前拒绝。
- 实际命令名是 `commerce:migrate-p4d-customer-asset`，与同目录
  P4A/P4B/P4C 命名约定一致。

### 守恒重放

对每个 `account_id`，ledger 的 `account_version` 必须从 1 连续增长，
并按以下确定性规则从 `(available=0,reserved=0)` 重放：

| event | available | reserved |
|---|---:|---:|
| credit | `+ amount` | 不变 |
| reserve | 不变 | `+ amount`，且不超过可预占余额 |
| release | 不变 | `- amount` |
| commit | `- amount` | `- amount` |
| return | `+ amount` | 不变 |

每条 ledger 的 after-balance 必须等于重放结果；末条余额和版本必须等于
`AssetAccount`。reservation 的 reserve/terminal/return ledger、identity、
request hash、金额上界和 active reservation 总额也必须一致。任一缺口、
漂移、负数、重复 identity 或未知状态均 fail closed。

### 状态机

1. `preflight`：只读重放，`business_writes=0`。
2. `apply`：在首个 rollout 写之前固化 manifest/journal，只进入
   `shadow`，不改三张业务表。
3. `verify`：从新 journal Store 重载 checkpoint，重算 schema/count/hash
   与守恒。
4. `allowlist`：只允许 checkpoint 冻结的单一 Website；不进入
   production `on`。
5. `rollback`：切回 `off`，禁止新 credit/reserve；ledger 与 reservation
   identity 不删除，已存在的 release/commit/refund-return 继续前向收敛。

## 验证

```bash
php bin/w mig:foundation clone-create --mode=full --purpose=p4dasset

php bin/w commerce:migrate-p4d-customer-asset preflight \
  --database="<registered full mig_clone_*>" --website=0
php bin/w commerce:migrate-p4d-customer-asset apply \
  --database="<registered full mig_clone_*>" --website=0
php bin/w commerce:migrate-p4d-customer-asset verify \
  --database="<registered full mig_clone_*>" --checkpoint="<apply checkpoint>"
php bin/w commerce:migrate-p4d-customer-asset allowlist \
  --database="<registered full mig_clone_*>" \
  --checkpoint="<apply checkpoint>" --website=0
php bin/w commerce:migrate-p4d-customer-asset rollback \
  --database="<registered full mig_clone_*>" --checkpoint="<apply checkpoint>"

WELINE_CUSTOMER_ASSET_TEST_DATABASE="<registered mig_clone_* database>" \
php vendor/bin/phpunit --bootstrap app/code/Weline/CustomerAsset/Test/Unit/bootstrap.php \
  app/code/Weline/CustomerAsset/Test/Unit/Service \
  app/code/Weline/CustomerAsset/Test/Integration

php bin/w mig:foundation clone-destroy --database="<registered mig_clone_* database>"
php bin/w setup:schema:check -m Weline_CustomerAsset --json
php bin/w architecture:check --allow-legacy --json
```

PostgreSQL integration 强制要求 migration registry 登记的
`mig_clone_*`；父进程与两个并发 PHP 子进程必须绑定同一克隆，未传
`WELINE_CUSTOMER_ASSET_TEST_DATABASE` 时不会误用默认开发库充当验收。
当前 MIG-P4D 固定证据为 `31 tests / 243 assertions`，覆盖 schema 唯一守卫、
fresh-service 持久重放、ledger 失败整事务回滚、live/sandbox 数据库
隔离、两个独立 PHP 进程竞争同一余额，以及真实 PostgreSQL 上
CustomerAsset → Payment → Order 混合退款的首败重试与行级守恒。
另有真实 full clone CLI 证据：`1/2/1` account/ledger/reservation 在
preflight、apply、fresh verify 中 hash 不变；新 PHP 进程读取 allowlist
并完成 `200/100` tender，rollback 在事实扩展到 `2/4/2` 后仍保留全部
ledger/obligation identity，随后另一新进程的新 tender 被 mode off 拒绝。
schema-only clone 明确退出 `2`，错误为
`mig_p4d_customer_asset_full_clone_required`；最终 fixture 行数为 `0/0/0`，
migration registry clone 数为 `0`。
`frontend:check-section-code --json` violation 为 `0`；i18n
zh/en 各 `65` 个唯一 key 且完全等键无重复。
官方 Customer account `#assets` 定向 Chromium 与 Codex Browser 均验证
`credit`、余额 `1,200 / 300 / 900`、两条账本和 console error/warn 为空；
测试通过显式 WLS Session 夹具建立登录态，不修改或绕过 CAPTCHA 登录流程。

模块版本：`1.3.0`。`TASK-P4D-001 = ACCEPTED`；`TASK-P4D-002`
`= ACCEPTED`；`TASK-MIG-P4D = ACCEPTED`；`GATE-P4D = GO`。
独立 Gate 复验后的专用 WLS 已停止、端口已释放、E2E fixture 与
migration registry clone 数均为 `0`；下一串行前沿为 `GATE-P4`。
