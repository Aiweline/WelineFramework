<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Table;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Connection\Api\Sql\AbstractTable;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\Helper\Standar;

class Create extends AbstractTable implements CreateInterface
{
    public array $index_outs = [];
    public const init_vars = [
        self::table_TABLE => '',
        self::table_COMMENT => '',
        self::table_FIELDS => [],
        self::table_ALERT_FIELDS => [],
        self::table_DELETE_FIELDS => [],
        self::table_INDEXS => [],
        self::table_FOREIGN_KEYS => [],
        self::table_CONSTRAINTS => '',
        self::table_ADDITIONAL => ';',
    ];

    public function reset(): void
    {
        $this->fields = [];
        $this->indexes = [];
        $this->foreign_keys = [];
        $this->constraints = '';
        $this->additional = ';';
        $this->primary_key = '';
        $this->comment = '';
        $this->new_table_name = '';
        $this->index_outs = [];
        foreach (self::init_vars as $key => $init_var) {
            $this->$key = $init_var;
        }
    }

    public function createTable(string $table, string $comment = ''): CreateInterface
    {
        # 开始表操作
        $this->reset();
        $this->startTable($table, $comment);
        return $this;
    }

    public function addColumn(string $field_name, string $type, int|string|null $length, string $options, string $comment): CreateInterface
    {
        // PostgreSQL 类型映射
        $type = strtolower($type);
        $pgType = $this->mapTypeToPostgres($type, $length);
        
        // 处理 AUTO_INCREMENT (PostgreSQL 使用 SERIAL)
        if (str_contains(strtolower($options), 'auto_increment')) {
            $options = str_replace('auto_increment', '', strtolower($options));
            if ($type === TableInterface::column_type_INTEGER || $type === TableInterface::column_type_SMALLINT) {
                $pgType = 'SERIAL';
                $options = str_replace('primary key', '', strtolower($options));
                if (!str_contains(strtolower($options), 'primary key')) {
                    $options .= ' PRIMARY KEY';
                }
            }
        }
        
        // PostgreSQL 使用双引号
        $field_name = str_replace('`', '', $field_name);
        $field_name = str_replace('"', '', $field_name);
        
        // 注释使用 COMMENT ON COLUMN
        $commentSql = '';
        if ($comment) {
            $commentSql = "COMMENT ON COLUMN {$this->table}.\"{$field_name}\" IS '{$comment}';";
        }
        
        $type_length = $length ? "{$pgType}({$length})" : $pgType;
        $this->fields[$field_name] = [
            'definition' => "\"{$field_name}\" {$type_length} {$options}",
            'comment' => $commentSql
        ];

        return $this;
    }

    /**
     * 映射 MySQL 类型到 PostgreSQL 类型
     */
    private function mapTypeToPostgres(string $type, int|string|null $length): string
    {
        $type = strtolower($type);
        $mapping = [
            'tinyint' => 'SMALLINT',
            'smallint' => 'SMALLINT',
            'mediumint' => 'INTEGER',
            'int' => 'INTEGER',
            'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'decimal' => 'NUMERIC',
            'numeric' => 'NUMERIC',
            'char' => 'CHAR',
            'varchar' => 'VARCHAR',
            'text' => 'TEXT',
            'tinytext' => 'TEXT',
            'mediumtext' => 'TEXT',
            'longtext' => 'TEXT',
            'blob' => 'BYTEA',
            'tinyblob' => 'BYTEA',
            'mediumblob' => 'BYTEA',
            'longblob' => 'BYTEA',
            'date' => 'DATE',
            'time' => 'TIME',
            'datetime' => 'TIMESTAMP',
            'timestamp' => 'TIMESTAMP',
            'year' => 'INTEGER',
            'json' => 'JSONB',
            'enum' => 'VARCHAR',
            'set' => 'VARCHAR',
        ];
        
        return $mapping[$type] ?? strtoupper($type);
    }

    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = ''): CreateInterface
    {
        $name = Standar::getIndexName($this->table, $name);
        $type = strtoupper($type);
        $index_method = $index_method ? "USING {$index_method}" : '';
        
        if (is_string($column)) {
            $column = explode(',', $column);
        }
        
        // 处理字段名，去除反引号，添加双引号
        $column = array_map(function($item) {
            $item = trim(str_replace(['`', '"'], '', $item));
            return "\"{$item}\"";
        }, $column);
        $column_str = implode(',', $column);
        
        switch ($type) {
            case self::index_type_DEFAULT:
            case self::index_type_KEY:
                $this->indexes[] = "CREATE INDEX \"{$name}\" ON {$this->table} ({$column_str}) {$index_method};";
                break;
            case self::index_type_UNIQUE:
                $this->indexes[] = "CREATE UNIQUE INDEX \"{$name}\" ON {$this->table} ({$column_str}) {$index_method};";
                break;
            case self::index_type_FULLTEXT:
                // PostgreSQL 使用 GIN 索引实现全文搜索
                $this->indexes[] = "CREATE INDEX \"{$name}\" ON {$this->table} USING GIN (to_tsvector('english', {$column_str}));";
                break;
            case self::index_type_SPATIAL:
                // PostgreSQL 使用 GIST 索引实现空间搜索
                $this->indexes[] = "CREATE INDEX \"{$name}\" ON {$this->table} USING GIST ({$column_str});";
                break;
            case self::index_type_MULTI:
                if (!is_array($column)) {
                    throw new Exception(self::index_type_MULTI . __('：此索引的column需要array类型'));
                }
                $this->indexes[] = "CREATE INDEX \"{$name}\" ON {$this->table} ({$column_str}) {$index_method};";
                break;
            default:
                throw new Exception(__('未知的索引类型：') . $type);
        }

        return $this;
    }

    public function addAdditional(string $additional_sql = ''): CreateInterface
    {
        // PostgreSQL 不需要 ENGINE 等选项
        $this->additional = $additional_sql ?: ';';
        return $this;
    }

    public function addConstraints(string $constraints = ''): CreateInterface
    {
        $this->constraints = $constraints;
        return $this;
    }

    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): CreateInterface
    {
        $on_delete_str = $on_delete ? 'ON DELETE CASCADE' : '';
        $on_update_str = $on_update ? 'ON UPDATE CASCADE' : '';
        
        // PostgreSQL 使用双引号
        $FK_Field = str_replace(['`', '"'], '', $FK_Field);
        $references_table = str_replace(['`', '"'], '', $references_table);
        $references_field = str_replace(['`', '"'], '', $references_field);
        
        // 如果表名包含 schema，处理它
        if (!str_contains($references_table, '.')) {
            $references_table = "public.\"{$references_table}\"";
        } else {
            $parts = explode('.', $references_table);
            $references_table = "\"{$parts[0]}\".\"{$parts[1]}\"";
        }
        
        $this->foreign_keys[] = "CONSTRAINT \"{$FK_Name}\" FOREIGN KEY (\"{$FK_Field}\") REFERENCES {$references_table} (\"{$references_field}\") {$on_delete_str} {$on_update_str}";
        return $this;
    }

    public function create(): mixed
    {
        // 字段
        $fieldDefinitions = [];
        $fieldComments = [];
        
        foreach ($this->fields as $fieldName => $fieldData) {
            if (is_array($fieldData)) {
                $fieldDefinitions[] = $fieldData['definition'];
                if (!empty($fieldData['comment'])) {
                    $fieldComments[] = $fieldData['comment'];
                }
            } else {
                $fieldDefinitions[] = $fieldData;
            }
        }
        
        // 如果没有 create_time 和 update_time，添加它们
        $hasCreateTime = false;
        $hasUpdateTime = false;
        foreach ($fieldDefinitions as $def) {
            if (str_contains(strtolower($def), 'create_time')) {
                $hasCreateTime = true;
            }
            if (str_contains(strtolower($def), 'update_time')) {
                $hasUpdateTime = true;
            }
        }
        
        if (!$hasCreateTime) {
            $create_time_comment_words = __('创建时间');
            $fieldDefinitions[] = "\"create_time\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
            $fieldComments[] = "COMMENT ON COLUMN {$this->table}.\"create_time\" IS '{$create_time_comment_words}';";
        }
        if (!$hasUpdateTime) {
            $update_time_comment_words = __('更新时间');
            $fieldDefinitions[] = "\"update_time\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
            $fieldComments[] = "COMMENT ON COLUMN {$this->table}.\"update_time\" IS '{$update_time_comment_words}';";
        }
        
        $fields_str = implode(',' . PHP_EOL . '    ', $fieldDefinitions);
        
        // 外键
        $foreign_key_str = '';
        if (!empty($this->foreign_keys)) {
            $foreign_key_str = ',' . PHP_EOL . '    ' . implode(',' . PHP_EOL . '    ', $this->foreign_keys);
        }
        
        // 约束
        $constraints_str = '';
        if (!empty($this->constraints)) {
            $constraints_str = ',' . PHP_EOL . '    ' . $this->constraints;
        }
        
        // 表注释
        $table_comment = '';
        if ($this->comment) {
            $table_comment = "COMMENT ON TABLE {$this->table} IS '{$this->comment}';";
        }
        
        $fieldCommentsStr = !empty($fieldComments) ? implode(PHP_EOL, $fieldComments) : '';
        $indexesStr = !empty($this->indexes) ? implode(PHP_EOL, $this->indexes) : '';
        
        $sql = <<<createSQL
CREATE TABLE {$this->table}(
    {$fields_str}{$foreign_key_str}{$constraints_str}
);
{$table_comment}
{$fieldCommentsStr}
{$indexesStr}
createSQL;
        
        try {
            $result = $this->query($sql)->fetch();
        } catch (\Exception $exception) {
            throw new Exception(__('创建表失败，' . PHP_EOL . PHP_EOL . 'SQL：%{1} ' . PHP_EOL . PHP_EOL . 'ERROR：%{2}', [$sql, $exception->getMessage()]));
        }
        return $result;
    }
}

