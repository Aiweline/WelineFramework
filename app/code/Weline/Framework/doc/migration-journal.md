# 迁移底座 Journal（TASK-MIG-FOUNDATION 续）

## 持久化

| 层 | 路径 | 用途 |
|---|---|---|
| 文件 Store | `var/mig/checkpoints/{checkpoint_id}.json` | 跨进程 / fresh-connection verify（默认） |
| DB Model | `weline_migration_checkpoint` | additive；同库 MIG apply 镜像（依赖 setup:upgrade） |

`MigrationCheckpointService` 挂载 `MigrationCheckpointJournalStore` 后，`checkpoint` / `appendJournal` 原子写盘。

## 隔离目标绑定

`MigrationTargetBinder::bindIsolated()` 在 clone 指纹通过后，必须同步重绑：

1. `DbManager`
2. `DbManagerFactory`
3. 直接注入给 Setup/Schema 服务的 `ConnectionFactory`

只重绑前两项会让模型访问 clone、但 SchemaDiff/Setup 仍持有源库连接。
迁移任务应在任何 schema 操作前断言直接 `ConnectionFactory` 的
`database` 等于登记 clone，并以只读 schema diff 验证目标差异。

## CLI

```bash
php bin/w mig:foundation journal-list
php bin/w mig:foundation journal-verify --checkpoint=cp-1
```

## 业务迁移与 full clone

依赖既有业务事实做 shadow replay 的迁移必须使用 registry 登记的
`clone-create --mode=full`。`schema` clone 只适用于不需要事实窗口的结构
验证，不能把空表当成成功证据。

跨进程业务迁移的标准因果顺序是：

1. `preflight` 校验 full clone、精确 Scope、事实窗口和 source fingerprint；
2. `apply` 写 immutable manifest/journal，并保持 `shadow`；
3. 独立进程 `verify` 重载文件、重算事实与报告；
4. 只有 fresh verify 通过后才允许 `allowlist`；
5. `rollback` 恢复 fail-closed 状态，但保留 checkpoint、journal 和业务 LKG。

业务配置若需要切换连接，必须创建连接级非共享模型；仅对进程共享模型调用
`setConnection()` 不能证明其已有查询状态、缓存或数据集已被清空。

### 大型 full clone 的 dump/restore

`MigrationCloneService` 不得把 `pg_dump` stdout 完整读入 PHP 字符串。真实
full clone 可能超过 CLI memory_limit；标准路径必须是：

1. `pg_dump --file {temp}` 由子进程直接流式写临时文件；
2. `psql -f {temp}` 恢复；
3. finally 删除临时文件，失败时销毁精确 clone 并保留稳定错误码。

2026-08-26 在 256MB CLI 限制下复现内存耗尽并修复；聚焦迁移底座为
14 tests / 41 assertions，随后成功创建 full clone。用于 Product 矩阵的
临时 clone 已通过 `clone-destroy` 清理。

### PostgreSQL 冷建表索引命名

冷建 `CREATE TABLE`、增量 `ADD INDEX`、Schema 回读和 normalizer 必须共用
`PgsqlIndexName::canonicalPhysical()`。旧 Create adapter 的 55+hash 截断与
新 54+hash 映射并存时，同一逻辑索引会出现两份并导致 provision
不收敛。2026-08-26 已统一 Create adapter，长 schema/table 聚焦回归
4 tests / 12 assertions，Product PostgreSQL 跨站矩阵随后 4/88 通过。

## 回滚

保留 journal 文件；具体 rollout 回退模式由业务迁移定义。所有迁移均拒绝
无 checkpoint、未知 clone、指纹漂移或共享数据库 apply；已形成新事实的
业务不得用通用 `off` 隐藏事实源或恢复旧 writer。
