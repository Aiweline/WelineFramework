<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;

/**
 * 从数据库读取表结构，输出与 SchemaParser 一致的 TableSchema，供 SchemaDiff 比较。
 * 禁止在此类中写 SQL 方言；所有表/列/索引/外键读取均通过 ConnectorInterface 的
 * getTableComment / getTableColumns / getTableIndexes / getTableForeignKeys，由各适配器自行实现方言。
 */
final class DbSchemaReader
{
    /**
     * 读取单表结构；表不存在时返回 null。
     */
    public function readTable(ConnectorInterface $connector, string $tableName): ?TableSchema
    {
        $tableName = trim(str_replace(['`', '"'], '', $tableName));
        if ($tableName === '') {
            return null;
        }

        if (!$connector->tableExist($tableName)) {
            return null;
        }

        $tableComment = $connector->getTableComment($tableName);
        $columnRows = $connector->getTableColumns($tableName);
        $indexRows = $connector->getTableIndexes($tableName);
        $fkRows = $connector->getTableForeignKeys($tableName);

        $columns = [];
        foreach ($columnRows as $row) {
            $columns[] = new ColumnDefinition(
                name: (string) ($row['name'] ?? ''),
                type: (string) ($row['type'] ?? ''),
                length: array_key_exists('length', $row) ? $row['length'] : null,
                nullable: (bool) ($row['nullable'] ?? true),
                primaryKey: (bool) ($row['primary_key'] ?? false),
                autoIncrement: (bool) ($row['auto_increment'] ?? false),
                default: $row['default'] ?? null,
                comment: (string) ($row['comment'] ?? ''),
                unique: (bool) ($row['unique'] ?? false)
            );
        }

        $indexes = [];
        foreach ($indexRows as $row) {
            $indexes[] = new IndexDefinition(
                name: (string) ($row['name'] ?? ''),
                columns: array_values($row['columns'] ?? []),
                type: !empty($row['unique']) ? 'UNIQUE' : 'DEFAULT',
                comment: '',
                method: 'BTREE'
            );
        }

        $foreignKeys = [];
        foreach ($fkRows as $row) {
            $foreignKeys[] = new ForeignKeyDefinition(
                name: (string) ($row['name'] ?? ''),
                columns: array_values($row['columns'] ?? []),
                referencesTable: (string) ($row['ref_table'] ?? ''),
                referencesColumns: array_values($row['ref_columns'] ?? []),
                onDeleteCascade: (bool) ($row['on_delete_cascade'] ?? false),
                onUpdateCascade: (bool) ($row['on_update_cascade'] ?? false)
            );
        }

        return new TableSchema(
            tableName: $tableName,
            comment: $tableComment,
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            modelClass: null
        );
    }

    /**
     * 读取多表结构。
     *
     * @param list<string> $tableNames
     * @return list<TableSchema>
     */
    public function readTables(ConnectorInterface $connector, array $tableNames): array
    {
        $result = [];
        foreach ($tableNames as $name) {
            $schema = $this->readTable($connector, $name);
            if ($schema !== null) {
                $result[] = $schema;
            }
        }
        return $result;
    }

    /**
     * 批量读取多表结构，返回 [tableName => TableSchema] 映射（不存在的表不出现在结果中）。
     * 先用一次 getExistingTables() 批量检查表是否存在，再逐表读取列/索引/外键数据。
     * 相比逐表 readTable()（每表 1 次 tableExist 查询），可将 N 次 tableExist 合并为 1 次。
     *
     * @param list<string> $tableNames
     * @return array<string, TableSchema>  key = tableName
     */
    public function readTablesBatch(ConnectorInterface $connector, array $tableNames): array
    {
        if ($tableNames === []) {
            return [];
        }

        // 一次查询确认哪些表实际存在（N→1 DB round-trip）
        $existing = array_flip($connector->getExistingTables($tableNames));

        $result = [];
        foreach ($tableNames as $name) {
            $cleanName = trim(str_replace(['`', '"'], '', $name));
            if ($cleanName === '' || !isset($existing[$cleanName])) {
                continue;
            }
            $tableComment = $connector->getTableComment($cleanName);
            $columnRows   = $connector->getTableColumns($cleanName);
            $indexRows    = $connector->getTableIndexes($cleanName);
            $fkRows       = $connector->getTableForeignKeys($cleanName);

            $columns = [];
            foreach ($columnRows as $row) {
                $columns[] = new ColumnDefinition(
                    name: (string) ($row['name'] ?? ''),
                    type: (string) ($row['type'] ?? ''),
                    length: array_key_exists('length', $row) ? $row['length'] : null,
                    nullable: (bool) ($row['nullable'] ?? true),
                    primaryKey: (bool) ($row['primary_key'] ?? false),
                    autoIncrement: (bool) ($row['auto_increment'] ?? false),
                    default: $row['default'] ?? null,
                    comment: (string) ($row['comment'] ?? ''),
                    unique: (bool) ($row['unique'] ?? false)
                );
            }

            $indexes = [];
            foreach ($indexRows as $row) {
                $indexes[] = new IndexDefinition(
                    name: (string) ($row['name'] ?? ''),
                    columns: array_values($row['columns'] ?? []),
                    type: !empty($row['unique']) ? 'UNIQUE' : 'DEFAULT',
                    comment: '',
                    method: 'BTREE'
                );
            }

            $foreignKeys = [];
            foreach ($fkRows as $row) {
                $foreignKeys[] = new ForeignKeyDefinition(
                    name: (string) ($row['name'] ?? ''),
                    columns: array_values($row['columns'] ?? []),
                    referencesTable: (string) ($row['ref_table'] ?? ''),
                    referencesColumns: array_values($row['ref_columns'] ?? []),
                    onDeleteCascade: (bool) ($row['on_delete_cascade'] ?? false),
                    onUpdateCascade: (bool) ($row['on_update_cascade'] ?? false)
                );
            }

            $result[$cleanName] = new TableSchema(
                tableName: $cleanName,
                comment: $tableComment,
                columns: $columns,
                indexes: $indexes,
                foreignKeys: $foreignKeys,
                modelClass: null
            );
        }

        return $result;
    }
}
