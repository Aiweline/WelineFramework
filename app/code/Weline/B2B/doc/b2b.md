# Weline_B2B（P4C）

## 冻结

| 项 | 值 |
|---|---|
| owning module | `Weline_B2B`（禁止把价目逻辑塞入 Product/Cart/Order 内部） |
| rollout | `B2BRolloutGate`；capability=`b2b`；默认 **mode off**（关闭 B2B 候选；零售路径继续） |
| website | `website_id=0`（default）合法 |
| 规则栈 | Channel 覆盖 → Website 价目 → 零售回退 |
| quote / submit | 服务端 token + 重验；版本/组冲突拒绝 |
| snapshot | 下单瞬间冻结；后续价目变更不回算旧单 |
| MIG | 仅 registry 登记的 PostgreSQL `full` clone；版本映射、事实或快照不守恒 fail closed |

## 组件

| 路径 | 职责 |
|---|---|
| P4C-001 | durable CustomerGroup/Membership/PriceList/Item / B2BPriceEngine / shadow |
| P4C-002 | quote token / ACL / submit recheck / Order snapshot |
| `Service/B2BMigrationService` | MIG-P4C cutover |
| `Service/B2BShadowComparator` | shadow + mapping observe |
| `Service/B2BQueryHarnessCatalog` | TEST-P4C-01 的进程外只读 E2E fixture（`var/b2b_query_harness/`） |
| `extends/.../Query/B2BQueryProvider` | 仅发布只读 `b2b.resolve`；fixture 未准备时执行 fail closed |
| `Console/Commerce/MigrateP4cB2b` | CLI `commerce:migrate-p4c-b2b` |

## 验证

```bash
vendor/bin/phpunit --bootstrap app/code/Weline/B2B/Test/Unit/bootstrap.php \
  app/code/Weline/B2B/Test/Unit/Service/

cd tests/e2e
PLAYWRIGHT_TARGET_ORIGIN=https://127.0.0.1:{port} \
  PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_WORKERS=1 \
  PLAYWRIGHT_TEST_FILES='["app/code/Weline/B2B/Test/e2e/frontend/plan-p4c01-b2b-retail-candidate.spec.js","app/code/Weline/B2B/Test/e2e/frontend/plan-p4c03-05-b2b-submit-snapshot.spec.js"]' \
  NODE_TLS_REJECT_UNAUTHORIZED=0 \
  node node_modules/playwright/cli.js test --config=playwright.config.js \
  --project=chromium --workers=1
cd ../..

php bin/w commerce:migrate-p4c-b2b help
php bin/w mig:foundation clone-create --mode=full --purpose=p4cb2b
php bin/w commerce:migrate-p4c-b2b preflight \
  --database=mig_clone_p4cb2b_... --website=0
php bin/w commerce:migrate-p4c-b2b apply \
  --database=mig_clone_p4cb2b_... --website=0
php bin/w commerce:migrate-p4c-b2b verify \
  --database=mig_clone_p4cb2b_... --checkpoint=p4cb2b-...
php bin/w commerce:migrate-p4c-b2b allowlist \
  --database=mig_clone_p4cb2b_... --checkpoint=p4cb2b-... --website=0
php bin/w commerce:migrate-p4c-b2b rollback \
  --database=mig_clone_p4cb2b_... --checkpoint=p4cb2b-...
php bin/w mig:foundation clone-destroy --database=mig_clone_p4cb2b_...
```

## P4C-001 候选合同

- 生产 Group、Customer+Website membership、PriceList revision 与 SKU 明细
  都是 ORM 持久事实；数组实现只能显式 `forTesting()`。
- Customer group 只能从服务端 `(customer_id, website_id)` membership
  解析；请求携带 `group_id` 一律拒绝，Website `0` 合法。
- 每个 `(list_id, version)` 不可覆盖；Channel 精确命中优先，其次 Website
  revision，最后零售价。金额只使用整数 minor unit。
- candidate 层只读且永远不写 Order。零售身份声明 B2B list/version
  必须 fail closed，写入计数保持 `0`。
- Browser QueryProvider 是 TEST-P4C-01 fixture 表面：fixture 不存在时
  fail closed，浏览器不能 configure/clear harness。

## P4C-002 提交与快照合同

- production/default runtime 使用 PostgreSQL。SQLite 只用于隔离的开发
  可移植性回归，不得替代 PostgreSQL 验收。
- quote token 是 ORM 持久事实，绑定 Customer、Website、SKU、原始零售价、
  候选版本、签发时间和过期时间；token identity 不可覆盖。
- submit 必须携带稳定 `order_ref`，并以 token 保存的原始零售价重新解析
  当前候选；Customer、Website、membership、价格来源、金额、版本或 Channel
  任一漂移都在 Order snapshot 写入前拒绝。
- PostgreSQL submit 事务先对 token 做 `FOR UPDATE` 锁定读，再原子写入
  snapshot 并消费 token；重放、过期和并发提交不能产生第二个 Order。
- snapshot 对 `order_ref` 与 `token_id` 都有唯一约束，payload hash 在读取时
  重验；没有更新入口，读取必须同时匹配 Customer 与 Website。
- 浏览器 QueryProvider 仍只发布候选读取 `resolve`；quote/submit/snapshot
  是服务端接口，不通过前端测试资源暴露。

## MIG-P4C 迁移合同

- 所有动作都必须显式绑定 registry 登记的 PostgreSQL `full` clone；
  仅名字匹配 `mig_clone_*`、schema-only clone 或 fingerprint 漂移都会
  非零失败。
- `preflight` 只读目标 Website 的真实 group、membership、list
  revision/item、quote 与 Order snapshot；Website `0` 是合法值，但仍需
  `--website=0` 显式冻结。
- `apply` 首先冻结 schema/facts/version mapping/shadow sample/checkpoint，
  然后只进入 shadow，业务事实写入计数必须为 `0`，不得直接 production-on。
- `verify` 必须由新进程从 checkpoint/journal 重载并重新读取 ORM；
  `allowlist` 是 fresh verify 后的独立精确 Website 动作。
- `rollback` 绑定同一 target/checkpoint，持久回到 mode off；既有 quote
  和不可变 Order snapshot 数量、payload hash 不变，零售路径继续。
- 使用完毕必须销毁 clone，并以 `mig:foundation clone-list` 的 `count=0`
  作为清理证据。

模块版本：`2.3.0`。`TASK-P4C-001..002`、`TASK-MIG-P4C = ACCEPTED`。
MIG 聚焦测试 `6/66`、完整模块测试 `29/240`、B2B E2E `2/2`；真实
PostgreSQL full-clone apply/fresh verify/allowlist/rollback/replay 与资源
清理通过，schema drift 和 B2B-local architecture finding 均为 `0`。
独立聚合复验已签署 `GATE-P4C = GO`；下一工程前沿为
`TASK-P4D-001`，`GATE-P4D`、`GATE-P4` 与 `PG-8` 仍为 `NO-GO`。
