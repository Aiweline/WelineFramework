<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * MySQL 8.0+ DDL 编译
 *
 * @deprecated 方言已下沉至 Mysql Connector，使用 Connector::getDefaultTableAdditional()
 * @since 1.0.0
 */
final class MysqlSchemaCompiler implements SchemaCompilerInterface
{
    public function getDefaultTableAdditional(): string
    {
        return 'ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;';
    }

    public function compileColumn(Column $column): string
    {
        $parts = ['`' . $column->name . '`', $column->type];
        if ($column->length !== null) {
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
        if ($column->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }
        if ($column->comment !== '') {
            $parts[] = "COMMENT '" . addslashes($column->comment) . "'";
        }
        if ($column->after !== null) {
            $parts[] = 'AFTER `' . $column->after . '`';
        }
        return implode(' ', $parts);
    }

    public function getDriverType(): string
    {
        return 'mysql';
    }

    public function getSinceVersion(): string
    {
        return '8.0';
    }
}
