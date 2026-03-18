<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Setup\Db\Migration;

use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Model\ModuleTable;
use Weline\ModuleManager\Model\ModuleUninstallAudit;

/**
 * weline_module_table：表策略字段；新建 weline_module_uninstall_audit 卸载/MDP 审计
 */
class AddModuleTablePolicyAndAudit20250318V102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '模块表登记扩展 table_policy/successor；模块卸载 MDP 审计表';
    }

    public function getVersion(): string
    {
        return '1.0.2';
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $mt = ObjectManager::getInstance(ModuleTable::class)->getTable();
        $hasField = $this->columnExistsFn($connection);

        $alter = $connection->alterTable()->forTable($mt, ModuleTable::schema_primary_key, '');
        if (!$hasField($mt, 'table_policy')) {
            $alter->addColumn(
                'table_policy',
                '',
                TableInterface::column_type_VARCHAR,
                '32',
                "NOT NULL DEFAULT 'owned'",
                'owned|shared|successor'
            );
        }
        if (!$hasField($mt, 'owner_module_name')) {
            $alter->addColumn(
                'owner_module_name',
                '',
                TableInterface::column_type_VARCHAR,
                '255',
                'NULL DEFAULT NULL',
                'shared 时结构 owner 模块'
            );
        }
        if (!$hasField($mt, 'successor_module_name')) {
            $alter->addColumn(
                'successor_module_name',
                '',
                TableInterface::column_type_VARCHAR,
                '255',
                'NULL DEFAULT NULL',
                '承接模块（successor 策略）'
            );
        }
        if (!$hasField($mt, 'deprecated_at')) {
            $alter->addColumn(
                'deprecated_at',
                '',
                TableInterface::column_type_VARCHAR,
                '32',
                'NULL DEFAULT NULL',
                '原模块弃用时间'
            );
        }
        $alter->alter();

        $connector = $connection->getConnector();
        $audit = ObjectManager::getInstance(ModuleUninstallAudit::class)->getTable();
        if (!$connector->tableExist($audit)) {
            $dbType = ObjectManager::getInstance(DbManager::class)->getConfig()->getDbType();
            if (str_contains(strtolower((string) $dbType), 'pgsql')) {
                $sql = "CREATE TABLE IF NOT EXISTS {$audit} (
                    module_uninstall_audit_id SERIAL PRIMARY KEY,
                    module_name VARCHAR(255) NOT NULL DEFAULT '',
                    action VARCHAR(64) NOT NULL DEFAULT '',
                    package_path TEXT NULL,
                    table_count INT NOT NULL DEFAULT 0,
                    row_count INT NOT NULL DEFAULT 0,
                    meta TEXT NULL,
                    created_at VARCHAR(32) NOT NULL DEFAULT ''
                )";
            } else {
                $sql = "CREATE TABLE IF NOT EXISTS {$audit} (
                    module_uninstall_audit_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    module_name VARCHAR(255) NOT NULL DEFAULT '',
                    action VARCHAR(64) NOT NULL DEFAULT '',
                    package_path TEXT NULL,
                    table_count INT NOT NULL DEFAULT 0,
                    row_count INT NOT NULL DEFAULT 0,
                    meta TEXT NULL,
                    created_at VARCHAR(32) NOT NULL DEFAULT ''
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            }
            $connection->query($sql)->fetch();
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /** @return callable(string,string):bool */
    private function columnExistsFn(object $connection): callable
    {
        return function (string $t, string $f) use ($connection): bool {
            if (method_exists($connection, 'hasField')) {
                return $connection->hasField($t, $f);
            }
            foreach ($connection->getTableColumns($t) as $col) {
                $name = $col['Field'] ?? $col['field'] ?? $col['column_name'] ?? '';
                if (strcasecmp((string) $name, $f) === 0) {
                    return true;
                }
            }

            return false;
        };
    }
}
