<?php

declare(strict_types=1);

/**
 * Weline_Database 模块安装脚本
 *
 * 创建 Migration、MigrationBackup 表；其余表结构由 SchemaDiffStage 根据 Model #[Col] 声明同步。
 */

namespace Weline\Database\Setup;

use Weline\Database\Model\Migration;
use Weline\Database\Model\MigrationBackup;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;

class Install
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var ModelSetup $modelSetup */
        $modelSetup = ObjectManager::getInstance(ModelSetup::class);
        $this->createMigrationTable($modelSetup);
        $this->createMigrationBackupTable($modelSetup);
    }

    private function createMigrationTable(ModelSetup $modelSetup): void
    {
        /** @var Migration $model */
        $model = ObjectManager::getInstance(Migration::class);
        $modelSetup->putModel($model);
        if ($modelSetup->tableExist()) {
            return;
        }
        $modelSetup->createTable('Database Migrations Table')
            ->addColumn(Migration::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Migration ID')
            ->addColumn(Migration::schema_fields_MODULE, TableInterface::column_type_VARCHAR, 255, 'not null', 'Module Name')
            ->addColumn(Migration::schema_fields_VERSION, TableInterface::column_type_VARCHAR, 50, 'not null', 'Version')
            ->addColumn(Migration::schema_fields_FILE, TableInterface::column_type_VARCHAR, 255, 'not null', 'Migration File')
            ->addColumn(Migration::schema_fields_DESCRIPTION, TableInterface::column_type_TEXT, null, 'default null', 'Description')
            ->addColumn(Migration::schema_fields_STATUS, TableInterface::column_type_VARCHAR, 50, 'not null default \'pending\'', 'Status')
            ->addColumn(Migration::schema_fields_EXECUTED_AT, TableInterface::column_type_TIMESTAMP, null, 'default null', 'Executed At')
            ->addColumn(Migration::schema_fields_ROLLBACK_AT, TableInterface::column_type_TIMESTAMP, null, 'default null', 'Rollback At')
            ->addColumn(Migration::schema_fields_DEPENDENCIES, TableInterface::column_type_TEXT, null, 'default null', 'Dependencies')
            ->addColumn(Migration::schema_fields_CHECKSUM, TableInterface::column_type_VARCHAR, 255, 'default null', 'Checksum')
            ->addColumn(Migration::schema_fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Created At')
            ->addColumn(Migration::schema_fields_UPDATED_AT, TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Updated At')
            ->create();
    }

    private function createMigrationBackupTable(ModelSetup $modelSetup): void
    {
        /** @var MigrationBackup $model */
        $model = ObjectManager::getInstance(MigrationBackup::class);
        $modelSetup->putModel($model);
        if ($modelSetup->tableExist()) {
            return;
        }
        $modelSetup->createTable('Database Migration Backups')
            ->addColumn(MigrationBackup::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Backup ID')
            ->addColumn(MigrationBackup::schema_fields_MIGRATION_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Migration ID')
            ->addColumn(MigrationBackup::schema_fields_TABLE_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', 'Table Name')
            ->addColumn(MigrationBackup::schema_fields_BACKUP_DATA, TableInterface::column_type_TEXT, null, 'default null', 'Backup Data')
            ->addColumn(MigrationBackup::schema_fields_BACKUP_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', 'Backup Type')
            ->addColumn(MigrationBackup::schema_fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Created At')
            ->create();
    }
}
