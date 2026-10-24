<?php

declare(strict_types=1);

/**
 * Weline_Database 模块安装脚本
 *
 * 只创建仍在使用的 DatabaseAdminAuditLog；旧 Migration/MigrationBackup 账本不再由安装创建。
 */

namespace Weline\Database\Setup;

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
        $this->createAdminAuditLogTable($modelSetup);
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
