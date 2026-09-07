<?php

declare(strict_types=1);

namespace Weline\Eav\Setup\Db\Migration;

use Weline\Eav\Model\EavAttribute\Option;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

/**
 * Option 实例作用域：scope_instance_id=0 共享；>0 为该实体实例私有。
 */
class AddEavOptionScopeInstanceId20260904V124 extends AbstractMigration
{
    public const COLUMN = 'scope_instance_id';
    public const UNIQUE_INDEX = 'uk_eav_attribute_option_attr_code_scope';
    public const SCOPE_INDEX = 'idx_eav_option_entity_scope_attr';

    public function getDescription(): string
    {
        return '为 eav_attribute_option 增加 scope_instance_id，支持实体实例私有选项。';
    }

    public function getVersion(): string
    {
        return '1.2.4';
    }

    public function getDate(): string
    {
        return '2026-09-04';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return ['eav_attribute_option'];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $option = ObjectManager::getInstance(Option::class);
        $table = $option->getTable();
        $rawTable = $this->rawTableName($table);

        if (!$this->columnExists($connection, $table, self::COLUMN)) {
            $alter = $connection->alterTable()->forTable($table, Option::schema_fields_option_id, '');
            $alter->addColumn(
                self::COLUMN,
                Option::schema_fields_eav_entity_id,
                TableInterface::column_type_INTEGER,
                11,
                'NOT NULL DEFAULT 0',
                '实体实例 ID；0 表示共享目录选项',
            );
            $alter->alter();
        }

        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $formatted = method_exists($connector, 'formatTableName')
            ? $connector->formatTableName($rawTable)
            : $rawTable;

        // Drop legacy (attribute_id, code) unique if present under common names.
        foreach ([
            'uk_eav_attribute_option_attr_code',
            'attribute_id_code',
            'uk_attribute_id_code',
            self::UNIQUE_INDEX,
        ] as $indexName) {
            if (method_exists($connector, 'hasIndex') && $connector->hasIndex($rawTable, $indexName)) {
                if (method_exists($connector, 'buildDropIndexSql')) {
                    $connector->query($connector->buildDropIndexSql($formatted, $indexName))->fetch();
                }
            }
        }

        if (method_exists($connector, 'hasIndex') && !$connector->hasIndex($rawTable, self::UNIQUE_INDEX)) {
            $alter = $connection->alterTable()->forTable($table, Option::schema_fields_option_id, '');
            $alter->addIndex(
                TableInterface::index_type_UNIQUE,
                self::UNIQUE_INDEX,
                [
                    Option::schema_fields_attribute_id,
                    Option::schema_fields_code,
                    self::COLUMN,
                ],
                '属性+代码+实例作用域唯一',
            );
            $alter->alter();
        }

        if (method_exists($connector, 'hasIndex') && !$connector->hasIndex($rawTable, self::SCOPE_INDEX)) {
            $alter = $connection->alterTable()->forTable($table, Option::schema_fields_option_id, '');
            $alter->addIndex(
                TableInterface::index_type_MULTI,
                self::SCOPE_INDEX,
                [
                    Option::schema_fields_eav_entity_id,
                    self::COLUMN,
                    Option::schema_fields_attribute_id,
                ],
                '按实体类型+实例+属性拉取选项',
            );
            $alter->alter();
        }

        return true;
    }

    public function uninstall(): bool
    {
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

    private function rawTableName(string $table): string
    {
        return trim($table, "\"` \t\n\r\0\x0B");
    }
}