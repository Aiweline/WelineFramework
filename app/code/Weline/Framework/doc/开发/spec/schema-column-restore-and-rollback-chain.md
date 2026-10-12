---
status: ready-for-implement
work_kind: feature
feature_slug: schema-column-restore-and-rollback-chain
module: Weline_Framework
related_modules: [Weline_Database, Weline_DeploySchemaDemo, Weline_Deploy]
session: deploy-schema-release-e2e
updated: 2026-10-12
architecture_meeting: dev/team/deploy-schema-release-e2e/meetings/2026-10-12-architecture-scheme.md
---

# Schema 列恢复与回滚链（规格）

## 背景

声明式 SchemaDiff 在 `DROP_COLUMN`/`MODIFY_COLUMN` 前由 `BackupService` 写入 `MigrationBackup`。  
波1 真机证明：前向 DROP 的备份为 `backup_scope=upgrade`；`restorePreviouslyRolledBackColumnData` 只识别 `STATUS_ROLLED_BACK` + `SCOPE_ROLLBACK`，导致「删列再加列」不会自动捞回。  
同期 `SchemaRollbackService::verifyCheckpointChain` 用未归一的表名字面键比对 format-2 checkpoint；setup 成功后 `operation_id` 仍占用，阻断正式 `module:rollback` 换绑。

## 方案（摘要）

完整机制 / owning_module / not_to_do 见团队架构方案会议纪要。本规格钉对外不变式与 UC。

1. **R2 自动恢复**：ADD/MODIFY 成功后，除 R1（rolled_back）外，须捞同列最近合格的 UPGRADE-scope DROP 列备份并 conflict-safe restore。
2. **回滚链表身份**：checkpoint 与 migration `schema_table_name` 比较前须经 `SchemaCheckpointIdentity`（format-2）归一；新写入规范化。
3. **operation_id**：setup 成功收尾释放本 setup 绑定；`failed_recovered` 释放门禁不变。
4. **升级规则 profile**：schema-only stage 不跑 `category=frontend`；完整 upgrade 仍致命校验 Theme 布局归属。
5. **命名**：`DeploySiteBackupService`（整站文件）≠ `BackupService`（列/表 MigrationBackup）。

## 交叉链（C4 话术）

| 文档 | 用途 |
|------|------|
| [Deploy README「备份边界」](../../../../Deploy/README.md) | 发布侧分栏入口；Deploy harness PASS ≠ 列自动恢复 |
| [Deploy 发布管理-需求说明 §4.4.5](../../../../Deploy/doc/发布管理-需求说明.md) | 正式站整站备份 vs Schema 列边界 |
| [Database 模块代码与数据库一致性回滚 · 备份边界](../../../../Database/doc/开发/模块代码与数据库一致性回滚.md) | 回滚编排侧 MigrationBackup 责任与分栏 |
| `dev/team/deploy-schema-release-e2e/contracts.md` C4 | 团队冻结不变式（非模块耐久正文） |

验收报告必须分栏：**Deploy 整站文件备份** vs **Schema 列 MigrationBackup**；禁止用 Deploy harness 绿冒充 UC-R2 / `module:rollback` PASS。

## EARS

1. WHEN 同列经历前向 `DROP_COLUMN`（已产生 `TYPE_COLUMN`+`SCOPE_UPGRADE` 备份）且随后 `ADD_COLUMN` 成功，系统 SHALL 在主键匹配且目标值为空时自动恢复备份值。
2. WHEN 目标列已有非空值，系统 SHALL 记录 conflict 且 SHALL NOT 覆盖。
3. WHEN Schema rollback 预检比对 checkpoint 与 migration 表名，系统 SHALL 使用与 format-2 相同的逻辑表身份，忽略仅部署限定符/引号风格差异。
4. WHEN 非 hot `setup:upgrade` 成功结束，系统 SHALL 释放该次 setup `operation_id` 在已安装 schema_diff 记录上的绑定。
5. WHEN ModuleVersionOperation 状态为 `failed_recovered`，系统 SHALL 允许既有 recovered 绑定释放；OTHER 状态 SHALL NOT 滥用该释放。
6. WHEN `setup:upgrade` 以 schema-only stage profile 运行，系统 SHALL NOT 因 `frontend-theme-layout-widget-owner` 失败而阻断 SchemaDiff。
7. WHEN 完整 `setup:upgrade` 运行，系统 SHALL 仍对 Theme 布局部件归属规则致命失败。

## Use cases

| ID | 场景 | 期望 |
|----|------|------|
| UC-R2-1 | Demo 列 DROP→ADD（经 setup 或同源执行器） | 无显式 restore API 调用即列值恢复 |
| UC-R2-2 | ADD 后目标已非空 | conflicts>0，值不变 |
| UC-RB-1 | createPlan 跨版本 | 无表身份字面 mismatch blocker |
| UC-RB-2 | setup 成功后 module rollback | 能 bind 新 operation_id 并执行 |
| UC-TH-1 | schema stage + 存在 Theme 违规 | SchemaDiff 可完成 |
| UC-TH-2 | full upgrade + 同违规 | 失败并点名规则 |
| UC-DOC-1 | 验收报告 | Deploy 备份与 Schema 列备份分栏 |

## 非目标

- 不合并 Deploy 整站备份与 Framework 列备份服务。
- 不删除 frontend Theme 布局规则。
- 不在 Deploy 编排硬编码业务表。
- 不把站/主题种子写入 `app/code` 模块。

## 验收命令（实现后）

```bash
# Schema / Database 聚焦（具体用例由测试席钉路径）
php bin/w phpunit:run --module=Weline_Framework --filter=ColumnRestore --no-coverage
php bin/w phpunit:run --module=Weline_Database --filter=SchemaRollback --no-coverage

# 真机 Demo（全语种/Browser N/A；CLI+DB）
php bin/w setup:upgrade -m Weline_DeploySchemaDemo -y
# schema profile 变体以实现后 contracts 为准
php bin/w frontend:check-theme-layout-widgets
```
