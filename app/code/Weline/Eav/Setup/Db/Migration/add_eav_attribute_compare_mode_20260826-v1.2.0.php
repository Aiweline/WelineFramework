<?php

declare(strict_types=1);

namespace Weline\Eav\Setup\Db\Migration;

use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

class AddEavAttributeCompareMode20260826V120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '为 EAV 属性表增加 compare_mode 字段，支持商品对比优劣方向配置。';
    }

    public function getVersion(): string
    {
        return '1.2.0';
    }

    public function getDate(): string
    {
        return '2026-08-26';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return [EavAttribute::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $table = $attribute->getTable();

        if ($this->columnExists($connection, $table, EavAttribute::schema_fields_compare_mode)) {
            return true;
        }

        $alter = $connection->alterTable()->forTable($table, EavAttribute::schema_fields_ID, '');
        $alter->addColumn(
            EavAttribute::schema_fields_compare_mode,
            EavAttribute::schema_fields_frontend_is_searchable,
            TableInterface::column_type_VARCHAR,
            32,
            "NOT NULL DEFAULT 'none'",
            '商品对比模式：none/diff/higher_better/lower_better',
        );
        $alter->alter();

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $table = $attribute->getTable();

        if (!$this->columnExists($connection, $table, EavAttribute::schema_fields_compare_mode)) {
            return true;
        }

        $alter = $connection->alterTable()->forTable($table, EavAttribute::schema_fields_ID, '');
        $alter->deleteColumn(EavAttribute::schema_fields_compare_mode);
        $alter->alter();

        return true;
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (method_exists($connection, 'hasField')) {
            return $connection->hasField($table, $field);
        }

        foreach ($connection->getTableColumns($table) as $column) {
            $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? '';
            if (strcasecmp((string)$name, $field) === 0) {
                return true;
            }
        }

        return false;
    }
}
