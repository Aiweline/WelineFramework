<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * 表结构 DTO，用于 SchemaParser 输出与 DbSchemaReader 输出，供 SchemaDiff 比较。
 * 策略：columns() 可由 #[Col] 反射生成，本类提供 toColumnsArray() 转为 SHOW FULL COLUMNS 兼容格式。
 */
final class TableSchema
{
    /** @param list<ColumnDefinition> $columns */
    /** @param list<IndexDefinition> $indexes */
    /** @param list<ForeignKeyDefinition> $foreignKeys */
    public function __construct(
        public readonly string $tableName,
        public readonly string $comment = '',
        public readonly array $columns = [],
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
        public readonly ?string $modelClass = null,
    ) {
    }

    /**
     * 转为与 SHOW FULL COLUMNS 兼容的数组格式，供 Model::columns() 等使用。
     *
     * @return list<array<string, mixed>>
     */
    public function toColumnsArray(): array
    {
        $list = [];
        foreach ($this->columns as $col) {
            $key = $col->primaryKey ? 'PRI' : ($col->unique ? 'UNI' : '');
            $extra = $col->autoIncrement ? 'auto_increment' : '';
            $list[] = [
                'Field' => $col->name,
                'Type' => $col->length !== null ? $col->type . '(' . $col->length . ')' : $col->type,
                'Collation' => null,
                'Null' => $col->nullable ? 'YES' : 'NO',
                'Key' => $key,
                'Default' => $col->default,
                'Extra' => $extra,
                'Comment' => $col->comment,
            ];
        }
        return $list;
    }
}
