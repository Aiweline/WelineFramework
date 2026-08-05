# Weline_Tax（P3B）

## 冻结（开卡）

| 项 | 值 |
|---|---|
| 管辖区键 | `country\|region`（例 `CN\|`、`US\|CA`） |
| 舍入 | `half_up`：按行算税再汇总；单项舍入误差 ≤1 minor |
| 失败 | **禁止**回 `tax=0` 软成功；抛 `TaxConflictException` |
| LKG | 仅同 typed Scope + `rule_schema_version` + `rule_set_hash` 且已验证可读 |

## 组件

| 路径 | 职责 |
|---|---|
| `Model/TaxClass` / `TaxRule` | exact-Website additive current source |
| `Model/TaxRuleSetLkg` | 持久化、可重放规则集快照 |
| `Api/TaxEngineInterface` | 算税契约 |
| `Api/TaxShadowQuoteSourceInterface` | 只读、规范化、去身份的 Checkout shadow 事实源契约 |
| `Service/TaxScopeConfig` | SystemConfig typed Scope adapter |
| `Service/TaxEngine` | ORM production engine；memory/frozen snapshot 仅显式测试 |
| `Service/TaxShadowComparator` | ORM current source vs frozen snapshot 观察窗（TEST-P3B-01） |
| `Service/TaxLkgStore` | Scope + Schema + rule hash 的持久规则集 LKG |
| `Service/CheckoutTaxAdvisor` | Checkout 可靠回退、稳定行映射和 Quote 固定版本校验 |
| `Service/TaxRolloutGate` | Env lock / global SystemConfig durable rollout；精确三元组 allowlist |
| `Service/TaxMigrationService` | checkpoint / shadow / allowlist / verify / rollback |
| `Console/Commerce/MigrateP3bTax` | CLI `commerce:migrate-p3b-tax` |
| `extends/.../Config/backend/tax.phtml` | SystemConfig Scope 字段 |

## MIG-P3B

`TASK-MIG-P3B` 已完成工程验收，但生产 rollout 默认仍为 `off`，且本任务不
授权生产 `on`。所有动作必须指向 registry 登记的 `mig_clone_*` **full
clone**，并使用同一个精确 `(website_id, store_id, channel_id)`：

1. `preflight` 从持久 CheckoutSession 读取至少 100 条唯一、去身份报价事实；
2. `apply` 写 immutable checkpoint，在 frozen rule snapshot 上完成完整
   shadow 观察窗，要求未分类差异为 0、逐行舍入不超过 1 minor，并保持
   `shadow`；
3. 独立 CLI 进程 `verify` 重新加载并校验 checkpoint、clone 指纹、事实
   集、report hash 与 exact-Scope verified LKG；
4. `allowlist` 内部先 fresh verify，再只写入目标三元组；不会写 `on`；
5. `rollback` 切回 `off` 并清空 allowlist，保留 LKG、旧 TaxSnapshot、
   checkpoint 与 journal。

```bash
php bin/w commerce:migrate-p3b-tax help
php bin/w mig:foundation clone-create --mode=full --purpose=p3btax
php bin/w commerce:migrate-p3b-tax preflight --database=mig_clone_... --website=0 --store=1 --channel=1
php bin/w commerce:migrate-p3b-tax apply --database=mig_clone_... --website=0 --store=1 --channel=1
php bin/w commerce:migrate-p3b-tax verify --database=mig_clone_... --checkpoint=p3btax-...
php bin/w commerce:migrate-p3b-tax allowlist --database=mig_clone_... --checkpoint=p3btax-... --website=0 --store=1 --channel=1
php bin/w commerce:migrate-p3b-tax rollback --database=mig_clone_... --checkpoint=p3btax-...
```

## 验证

```bash
php bin/w setup:schema:check -m Weline_Tax
php bin/w phpunit:run --name=TaxEngineAndShadowTest
php bin/w phpunit:run --name=TaxCurrentSourceDatabaseIntegrationTest
php bin/w phpunit:run --module=Weline_Tax
```

Checkout/Order/Invoice 接入由 **TASK-P3B-002** 实现：

- `off` 与 `shadow` 只冻结 `mode=none`、`engine=none`、零税快照，不改变成交金额；
- `allowlist` 命中或 `on` 才将 Tax 引擎结果写入成交链路；
- 每行必须使用稳定且唯一的 `line_uuid`；
- 引擎失败时先按请求生成精确 `scope_key + schema + rule_set_hash`，读取已验证 LKG 后用 `TaxEngine::fromSnapshot()` 重放原请求；
- 提交在当前 Worker 重新构造请求并比较 Quote 固定版本，规则变化返回重新报价冲突；
- Order、OrderItem、Invoice 复制冻结快照，任何读路径和开票路径都不得重算。

浏览器税额字段仍直接拒绝。完成 `MIG-P3B` 不等于生产 Tax 已切流；默认
`off` 与显式生产 `on` 授权边界保持不变。

模块版本：`2.1.3`（保留既有 Schema checkpoint，并加入 durable
shadow/cutover 边界）。生产 apply 前必须由真实只读报价事实提供观察样本；
CLI 空样本、schema clone、未知 clone 或缺 checkpoint 均 fail closed。
