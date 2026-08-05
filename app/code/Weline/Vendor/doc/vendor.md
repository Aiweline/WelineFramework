# Weline_Vendor（P4A）

## 冻结

| 项 | 值 |
|---|---|
| owning module | `Weline_Vendor`（禁止塞入 Payment/Order 内部） |
| rollout | capability=`vendor`；默认 **mode off** |
| environment | `sandbox` \| `live` 硬隔离 |
| website | `website_id=0`（default）合法 |
| 分账 | 快照不可变；`vendor_share + platform_share = gross`；旧单不按新规则回算 |
| mode off | **停新规则/新快照**；既有快照的 payout / refund reversal **继续** |
| MIG | `apply` 必须 `mig_clone_*`；shadow 守恒失败 fail closed |

## 组件

| 路径 | 职责 |
|---|---|
| `Model/VendorIdentity` / `VendorSplitSnapshot` | 身份常量 / 不可变分账 DTO |
| `Model/VendorRecord` | durable Vendor identity；code 唯一、environment 固化 |
| `Model/VendorWebsiteAuthorizationRecord` | durable Website 授权/撤销及单调 `grant_version` |
| `Model/VendorStoreAccountBindingRecord` | durable Store 账户绑定；仅保存非凭证 account reference 与 hash |
| `Model/VendorProductBindingRecord` | durable Vendor↔Website↔Store↔Product identity 绑定 |
| `Model/VendorSplitRuleRecord` | durable Store-scoped 分账规则、版本与 CAS token |
| `Model/VendorSplitSnapshotRecord` | write-once `vendor.split.v2` 结算快照及 payload hash |
| `Model/VendorPayoutRecord` | durable payout ledger、幂等 request hash、单调版本与 CAS |
| `Model/VendorRefundReversalRecord` | append-only refund reversal journal |
| `Service/VendorRegistryStore` | 生产默认 ORM；`forTesting()` 才使用进程内存 |
| `Service/VendorAuthorizationService` | Website 隔离授权；`website_id=0` 合法 |
| `Service/VendorStoreAccountBindingService` | 使用公开 Store catalog 验证状态、归属、mode 与 environment |
| `Service/VendorProductBindingService` | 只通过 Product 公开 identity resolver 绑定 canonical identity |
| `Service/VendorEligibilityService` / `VendorAclGuard` / `VendorService` | 交易资格、ACL default deny 与 facade |
| `Service/VendorSettlementService` 等 | P4A-002 durable 规则/快照/payout/reversal/report |
| `Service/VendorRolloutGate` | clone-bound durable mode/精确 Website+Store allowlist |
| `Service/VendorMigrationService` | registered-full-clone checkpoint/fresh-verify cutover |
| `Service/VendorShadowComparator` | shadow 守恒观察 |
| `Console/Commerce/MigrateP4aVendor` | CLI `commerce:migrate-p4a-vendor` |

## P4A-001 身份与授权约束

- 生产身份、Website 授权、Store 账户绑定和 Product 绑定均写入 ORM；
  测试内存实现只能通过显式 `forTesting()` 启用。
- Website 授权按 `(vendor_id, website_id)` 隔离；撤销不会删除历史行，
  而是提升 `grant_version` 并变更状态。
- Store 必须由 `Weline_Websites` 的公开 `StoreCatalogInterface` 解析；
  Store 必须活动且属于目标 Website。
- `dev` / `test` Store 只能绑定 `sandbox` 账户，Vendor、账户及请求
  environment 必须一致。账户引用必须带 environment 前缀，并且不得保存凭证。
- Product 绑定必须指定 Store，并通过
  `Weline\Product\Api\ProductIdentityResolverInterface` 解析真实 SKU；
  Vendor 不读取 Product 私有 Model/Repository。
- capability=`vendor` mode off 时禁止新交易写入；既有结算义务由
  `TASK-P4A-002` 单独验收。

## P4A-002 结算约束

- 生产 split rule、snapshot、payout 与 reversal 均默认写 ORM；memory seam
  只能显式 `forTesting()` 启用。
- `vendor.split.v2` 以 Vendor + Website + Store + Order + Payment 识别
  payable，并冻结 Store-scoped account、法律实体、commission、currency、
  environment、Store mode 与 Checkout group reference。同一 Group 可包含
  多个 Vendor；同一 Vendor/Store/payable 不得重算。
- 快照 write-once 且带稳定 payload hash；后续规则或账户绑定变化不得回写
  旧单。minor-unit 分账使用安全整数算法，并强制 currency 与规则一致。
- payout 与 reversal 的相同 idempotency request 可确定性重放，不同 payload
  使用同 key 必须 fail closed。reversal journal 与 payout CAS 更新同事务，
  journal 写入失败时 payout 回滚。
- reconciliation 必须显式按 Vendor、environment 与 Store mode 隔离并产生
  稳定 scope hash；normal/live 报表不得包含 test/sandbox。
- capability mode off 停止新 rule/snapshot；旧快照的 payout/reversal 在
  fresh service instance 中仍继续。

## 迁移

```bash
php bin/w commerce:migrate-p4a-vendor help
php bin/w mig:foundation clone-create --mode=full --purpose=p4avendor
php bin/w commerce:migrate-p4a-vendor preflight --database=mig_clone_p4avendor_... --website=0 --store=1
php bin/w commerce:migrate-p4a-vendor apply --database=mig_clone_p4avendor_... --website=0 --store=1
php bin/w commerce:migrate-p4a-vendor verify --database=mig_clone_p4avendor_... --checkpoint=p4avendor-...
php bin/w commerce:migrate-p4a-vendor allowlist --database=mig_clone_p4avendor_... --checkpoint=p4avendor-... --website=0 --store=1
php bin/w commerce:migrate-p4a-vendor rollback --database=mig_clone_p4avendor_... --checkpoint=p4avendor-...
```

- 生产动作只接受 migration registry 中的 `full` clone；shared、schema-only
  或未登记目标在首写前拒绝。
- `preflight` 只读排序后的 durable Vendor 事实；`apply` 先冻结 manifest/
  checkpoint，再进入 shadow，不造业务数据。
- `verify` 必须从新进程重读 journal/clone 并复算 schema、逐表计数/行 hash
  与守恒报告；任一漂移 fail closed。
- `allowlist` 只能在 fresh verify 后持久化单一 dev/test Website+Store；
  `rollback` 按 checkpoint 回到 mode off，保留既有结算义务并可幂等重放。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Vendor/Test/Unit/bootstrap.php \
  app/code/Weline/Vendor/Test/Unit/Service/
php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php \
  app/code/Weline/Order/Test/Unit/Service/CheckoutGroupTopologyTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php \
  app/code/Weline/Product/Test/Unit/Service/SkuRegistryServiceTest.php \
  app/code/Weline/Product/Test/Unit/Service/SkuRegistryServiceIntegrationTest.php
php bin/w setup:schema:check Weline_Product Weline_Vendor
```

`TASK-MIG-P4A` 当前验收证据：真实 full clone 上完成
preflight→checkpoint→apply(shadow)→fresh verify→精确 allowlist→
mode-off rollback；checkpoint `p4avendor-20260728043510-c2aade`，
初始守恒 `9000 - 100 = 8900`。rollback 后 fresh runtime 继续旧 payout，
追加第二笔 50-minor reversal 后仍守恒 `9000 - 150 = 8850`，同时阻止
新 split 与未授权 Vendor。克隆已销毁且 registry count=`0`。

Vendor `25 tests / 209 assertions`，Order Checkout group topology `4/36`，
PHP lint `37/37`，Vendor schema 无漂移，目标 architecture finding=`0`，
两份 i18n CSV 各 `89` 条唯一记录。专用 WLS
`ai-test-mig-p4a-20260728-1242:19878` 达到 `6/6`；首页/API 文档 HTTP
`200`、未登录后台 `302`，Browser 可见内容正确且 console
warn/error=`[]`。验收后标签页、WLS、端口与 clone 均已清理。

模块版本：`1.5.0`。`TASK-P4A-001..002`、`TASK-MIG-P4A = ACCEPTED`。
独立 Gate 当前代码复验再次取得 Vendor `25/209`、Product identity
`12/56`、Order group `4/36`；checkpoint journal fresh verify 通过且
clone count=`0`，Schema/i18n/局部 architecture/WLS/Browser 与清理均成立。
因此 `GATE-P4A = GO`。本结论不签署 `GATE-P4` 或 `PG-8`；P4B/P4C/P4D
build 前沿已解除依赖，串行执行下一项为 `TASK-P4B-001`。
