# Weline_Database 迁移/备份系统 — 任务清单

最后更新：2026-02-24

## 核心任务

- [x] 扩展 `MigrationInterface`：新增 `getType()`、`getAffectedTables()`、`requiresBackup()`、`getBackupStrategy()`
- [x] 新建 `AbstractMigration` 抽象基类，提供所有方法的默认实现
- [x] `Migration` Model：新增 `STATUS_RUNNING`/`STATUS_MANUAL`；`recordMigration` 返回 `int`（记录 ID）；新增 `findMigrationId`
- [x] `MigrationService.upgradeMigration`：迁移前自动备份（先写 running 记录 → 备份 → install → 更新 installed）
- [x] `MigrationService.rollbackMigration`：回滚时自动恢复关联备份
- [x] `BackupService`：新增 `restoreByBackupId(int)`、`getBackupsByMigrationId(int)`
- [x] 新建 `Console/Db/Migrate/Backup.php`（db:migrate:backup）
- [x] 新建 `Console/Db/Migrate/Restore.php`（db:migrate:restore）
- [x] 新建 `Helper/MigrationHelper.php`
- [x] 新建 `Helper/BackupHelper.php`

## 文档任务

- [x] 用户手册增加备份/恢复命令与 AbstractMigration 使用说明
- [x] 创建模块 plan.md 与 task.md
- [x] 执行 `command:upgrade` 注册新命令
