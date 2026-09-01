<?php

declare(strict_types=1);

namespace Weline\Eav\Setup\Db\Migration;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

/**
 * 商品级「自由属性」：属性组/属性可绑定 scope_product_id，仅对该商品实例可见。
 */
class AddEavScopeProductId20260826V121 extends AbstractMigration
{
    public const COLUMN = 'scope_product_id';

    public function getDescription(): string
    {
        return '为 EAV 属性组/属性表增加 scope_product_id，支持商品实例级自由属性。';
    }

    public function getVersion(): string
    {
        return '1.2.1';
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
        return [Group::schema_table, EavAttribute::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $this->ensureColumn(
            $connection,
            ObjectManager::getInstance(Group::class)->getTable(),
            Group::schema_fields_group_id,
            Group::schema_fields_name,
        );
        $this->ensureColumn(
            $connection,
            ObjectManager::getInstance(EavAttribute::class)->getTable(),
            EavAttribute::schema_fields_ID,
            EavAttribute::schema_fields_eav_entity_id,
        );

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        foreach ([Group::class, EavAttribute::class] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $table = $model->getTable();
            $pk = $modelClass === Group::class
                ? Group::schema_fields_group_id
                : EavAttribute::schema_fields_ID;
            if (!$this->columnExists($connection, $table, self::COLUMN)) {
                continue;
            }
            $alter = $connection->alterTable()->forTable($table, $pk, '');
            $alter->deleteColumn(self::COLUMN);
            $alter->alter();
        }

        return true;
    }

    private function ensureColumn(object $connection, string $table, string $primaryKey, string $afterColumn): void
    {
        if ($this->columnExists($connection, $table, self::COLUMN)) {
            return;
        }

        $alter = $connection->alterTable()->forTable($table, $primaryKey, '');
        $alter->addColumn(
            self::COLUMN,
            $afterColumn,
            TableInterface::column_type_INTEGER,
            11,
            'NULL DEFAULT NULL',
            '商品实例 ID；NULL 表示全局共享定义',
        );
        $alter->alter();
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
