# Payment 兼容迁移（MIG-P2-PAYMENT）

历史 `PaymentTransaction` → 只读兼容 `PaymentIntent` /
`PaymentAttempt` reader。生产命令不使用进程内 seed；所有数据库动作都读取
migration registry 登记的隔离 clone。

## 安全边界

- `compat_intent_*` / `compat_attempt_*` 由 `transaction_no` 的稳定 hash
  生成，同一历史行重放不会产生第二份 reader。
- apply 前必须完成 clone 指纹校验、Schema/行数/摘要/水位 checkpoint 和
  journal；写入发生在一个数据库事务内。
- **禁止**删除或改写历史 Transaction，**禁止**调用 Provider，**禁止**
  生成 Payment business outbox。
- 未登记 clone、共享 `weline`、不确定环境/商户、无效金额、未知状态、
  成功终态缺 Provider reference、既有 reader 不一致或 Provider reference
  重复归属都会 fail closed。
- 冲突报告最多返回 100 条；任何冲突存在时 apply 为零业务写、退出码 `2`。
- rollback 只追加 `mode=off` 审计并 continue-forward；历史与已映射 reader、
  refund/inbox/outbox/ledger/reconciliation 全部保留。

## 历史字段来源

旧表直接字段作为金额、币种、状态、订单、支付方式和 scope 的权威来源。
`environment`、`provider_code`、`merchant_account`、
`provider_reference`、`payable_type` 从 request/response/callback JSON
按固定优先级提取；无法唯一确定时不得猜测。

旧 Transaction 金额固定按 precision `2` 用字符串算法转 minor unit，禁止
float。成功/退款终态必须保留 Provider reference；失败/未完成交易可为空。

## CLI

```bash
php bin/w commerce:migrate-p2-payment help
php bin/w mig:foundation clone-create --mode=schema --purpose=p2payment
php bin/w commerce:migrate-p2-payment preflight \
  --database=mig_clone_p2payment_...
php bin/w commerce:migrate-p2-payment apply \
  --database=mig_clone_p2payment_...
php bin/w commerce:migrate-p2-payment verify \
  --database=mig_clone_p2payment_... \
  --checkpoint=p2pay-...
php bin/w commerce:migrate-p2-payment rollback \
  --database=mig_clone_p2payment_... \
  --checkpoint=p2pay-...
php bin/w mig:foundation clone-destroy \
  --database=mig_clone_p2payment_...
```

`verify` 必须由新的 PHP/CLI 进程执行，并只依赖 `--database` 与
`--checkpoint`。成功输出必须包含 `diff_count=0`、
`history_retained=true`、`provider_calls=0`、`outbox_delta=0`。

## 状态映射

| Transaction | Intent | Attempt |
|---|---|---|
| success | paid | succeeded |
| failed | failed | failed |
| refunded | refunded | succeeded |
| pending | pending | created |
| processing | processing | provider_pending |
| unknown | processing | processing（nonterminal） |
| 其他 | conflict / zero write | conflict / zero write |

## 守恒与水位

fresh verify 重新连接 clone，并逐项检查：

- Transaction 行数与摘要必须和 checkpoint 完全一致。
- Intent/Attempt 的新增量必须等于 journal 的 `mapped`，既有精确 reader
  计为 `already`。
- amount minor、币种、environment、Payable、scope、终态和 Provider
  reference 必须与确定性计划一致。
- `(merchant_account, environment, provider_reference)` 不得重复归属。
- refund、inbox、business outbox、ledger、reconciliation 的行数和摘要
  必须保持 checkpoint 值，证明迁移没有产生支付业务副作用。
- Schema fingerprint 和 clone connector fingerprint 必须保持不变。

## 自动验证

```bash
php vendor/bin/phpunit \
  --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php \
  app/code/Weline/Payment/Test/Unit/Service/PaymentCompatibilityMigrationServiceTest.php

php vendor/bin/phpunit \
  --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php \
  app/code/Weline/Payment/Test/Unit
```

真实验收还必须使用两个一次性 clone：一个验证成功、幂等、fresh verify 和
mode-off；另一个注入歧义历史行，验证 preflight/apply 都以退出码 `2`
阻断且 Intent/Attempt 仍为 `0`。完成后销毁 clone 并确认 registry 清零。
