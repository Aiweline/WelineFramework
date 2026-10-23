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
use Weline\Database\Model\DatabaseAdminAuditLog;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;

class Install implements \Weline\Framework\Setup\InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var ModelSetup $modelSetup */
        $modelSetup = ObjectManager::getInstance(ModelSetup::class);
        $this->createMigrationTable($modelSetup);
        $this->createMigrationBackupTable($modelSetup);
        $this->createAdminAuditLogTable($modelSetup);
    }

    private function createMigrationTable(ModelSetup $modelSetup): void
    {
        /** @var Migration $model */
        $model = ObjectManager::getInstance(Migration::class);
        $modelSetup->putModel($model);
        if ($modelSetup->tableExist()) {
            return;
        }
    }

    private function createMigrationBackupTable(ModelSetup $modelSetup): void
    {
        /** @var MigrationBackup $model */
        $model = ObjectManager::getInstance(MigrationBackup::class);
        $modelSetup->putModel($model);
        if ($modelSetup->tableExist()) {
            return;
        }
    }

    private function createAdminAuditLogTable(ModelSetup $modelSetup): void
    {
        /** @var DatabaseAdminAuditLog $model */
        $model = ObjectManager::getInstance(DatabaseAdminAuditLog::class);
        $modelSetup->putModel($model);
        if ($modelSetup->tableExist()) {
            return;
        }
    }
}
