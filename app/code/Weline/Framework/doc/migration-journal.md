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

## 回滚

保留 journal 文件；具体 rollout 回退模式由业务迁移定义。所有迁移均拒绝
无 checkpoint、未知 clone、指纹漂移或共享数据库 apply；已形成新事实的
业务不得用通用 `off` 隐藏事实源或恢复旧 writer。
