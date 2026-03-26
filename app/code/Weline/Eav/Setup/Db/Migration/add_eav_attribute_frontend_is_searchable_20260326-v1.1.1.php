<?php

declare(strict_types=1);

namespace Weline\Eav\Setup\Db\Migration;

use Weline\Database\AbstractMigration;
use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class AddEavAttributeFrontendIsSearchable20260326V111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '为 EAV 属性表增加 frontend_is_searchable 字段，支持动态 searchable 属性配置。';
    }

    public function getVersion(): string
    {
        return '1.1.1';
    }

    public function getDate(): string
    {
        return '2026-03-26';
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

        if ($this->columnExists($connection, $table, EavAttribute::schema_fields_frontend_is_searchable)) {
            return true;
        }

        $alter = $connection->alterTable()->forTable($table, EavAttribute::schema_fields_ID, '');
        $alter->addColumn(
            EavAttribute::schema_fields_frontend_is_searchable,
            EavAttribute::schema_fields_frontend_is_filterable,
            TableInterface::column_type_BOOLEAN,
            0,
            'NOT NULL DEFAULT false',
            '前端是否可搜索'
        );
        $alter->alter();

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $table = $attribute->getTable();

        if (!$this->columnExists($connection, $table, EavAttribute::schema_fields_frontend_is_searchable)) {
            return true;
        }

        $alter = $connection->alterTable()->forTable($table, EavAttribute::schema_fields_ID, '');
        $alter->deleteColumn(EavAttribute::schema_fields_frontend_is_searchable);
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
            if (strcasecmp((string) $name, $field) === 0) {
                return true;
            }
        }

        return false;
    }
}
