<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * PostgreSQL 16+ DDL 编译
 * @since 1.0.0 支持 PostgreSQL 16+
 */
final class PgsqlSchemaCompiler implements SchemaCompilerInterface
{
    public function getDefaultTableAdditional(): string
    {
        return '';
    }

    public function compileColumn(Column $column): string
    {
        $type = $column->type;
        if ($column->type === 'INTEGER' && $column->autoIncrement) {
            $type = 'SERIAL';
        }
        if ($column->type === 'BIGINT' && $column->autoIncrement) {
            $type = 'BIGSERIAL';
        }
        $parts = ['"' . $column->name . '"', $type];
        if ($column->length !== null && $type !== 'SERIAL' && $type !== 'BIGSERIAL') {
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
        if ($column->comment !== '') {
            $parts[] = "/* COMMENT '" . addslashes($column->comment) . "' */";
        }
        return implode(' ', $parts);
    }

    public function getDriverType(): string
    {
        return 'pgsql';
    }

    public function getSinceVersion(): string
    {
        return '16.0';
    }
}
