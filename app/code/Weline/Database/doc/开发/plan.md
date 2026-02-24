# Weline_Database 迁移/备份系统补齐计划

## 目标

完成企业级数据库迁移系统中尚未实现的备份与恢复能力，使其与 `docs/Weline_Database_Migration_System_Spec.md` 规范一致。

## 变更概览

| 编号 | 变更项 | 状态 |
|------|--------|------|
| 1 | 扩展 MigrationInterface（getType/getAffectedTables/requiresBackup/getBackupStrategy）+ AbstractMigration 抽象基类 | 已完成 |
| 2 | MigrationService：迁移执行前按策略自动备份；回滚时自动恢复备份 | 已完成 |
| 3 | BackupService：新增 restoreByBackupId、getBackupsByMigrationId | 已完成 |
| 4 | 新增 Console 命令 db:migrate:backup（Backup.php） | 已完成 |
| 5 | 新增 Console 命令 db:migrate:restore（Restore.php） | 已完成 |
| 6 | Helper 层（MigrationHelper + BackupHelper） | 已完成 |
| 7 | 文档更新（用户手册、plan.md、task.md） | 已完成 |
| 8 | 注册命令（command:upgrade） | 已完成 |

最后更新：2026-02-24
