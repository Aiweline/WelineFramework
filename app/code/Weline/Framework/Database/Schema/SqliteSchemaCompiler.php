<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * SQLite 3.45+ DDL 编译
 *
 * @deprecated 方言已下沉至 Sqlite Connector
 * @since 1.0.0
 */
final class SqliteSchemaCompiler implements SchemaCompilerInterface
{
    public function getDefaultTableAdditional(): string
    {
        return '';
    }

    public function compileColumn(Column $column): string
    {
        $type = $column->type;
        if ($column->type === 'INTEGER' && $column->primaryKey && $column->autoIncrement) {
            $type = 'INTEGER';
        }
        $parts = ['"' . $column->name . '"', $type];
        if ($column->length !== null && !($column->primaryKey && $column->autoIncrement)) {
            $parts[] = '(' . $column->length . ')';
        }
        if (!$column->nullable) {
            $parts[] = 'NOT NULL';
        }
        if ($column->default !== null) {
            $parts[] = 'DEFAULT ' . (is_string($column->default) ? "'" . addslashes((string)$column->default) . "'" : (string)$column->default);
        }
        if ($column->primaryKey) {
            $parts[] = 'PRIMARY KEY';
        }
        if ($column->autoIncrement && $column->primaryKey) {
            $parts[] = 'AUTOINCREMENT';
        }
        return implode(' ', $parts);
    }

    public function getDriverType(): string
    {
        return 'sqlite';
    }

    public function getSinceVersion(): string
    {
        return '3.45';
    }
}
