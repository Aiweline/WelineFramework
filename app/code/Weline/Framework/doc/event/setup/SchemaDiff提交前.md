# Weline_Framework_Setup::before_schema_diff_commit

在 `SchemaDiffStage::commit` 内、`SchemaMigrationExecutor::execute`（DDL）之前派发。

## 用途

owning 模块可在唯一索引 / 约束 DDL 前做幂等数据自愈（例如 `Weline_DeveloperWorkspace` 文档表去重）。

## 约定

- Observer 失败应抛出并中断升级，禁止吞异常。
- 框架不在 Executor 内硬编码业务表删行。
- 配套只读检查：`php bin/w setup:schema:check`

## 相关

- DEV 同版本 checkpoint 重绑：`php bin/w setup:upgrade --force-schema-rebind`（仅 DEV；与 `-f/--force` 无关）
