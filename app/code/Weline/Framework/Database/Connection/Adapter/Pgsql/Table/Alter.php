<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Table;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\AbstractTable;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;

class Alter extends AbstractTable implements AlterInterface
{
    public string $additional = ';';
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

    public function forTable(string $table_name, string $primary_key, string $comment = '', string $new_table_name = ''): AlterInterface
    {
        # 开始表操作
        $this->startTable($table_name, $comment, $primary_key, $new_table_name);
        return $this;
    }

    /**
     * @DESC          # 添加字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:31
     * 参数区：
     *
     * @param string $field_name 字段名
     * @param string $after_column PostgreSQL 不支持 AFTER，此参数将被忽略
     * @param string $type 字段类型
     * @param string|int $length 长度
     * @param string $options 配置
     * @param string $comment 字段注释
     *
     * @return AlterInterface
     */
    public function addColumn(string $field_name, string $after_column, string $type, string|int $length, string $options, string $comment): AlterInterface
    {
        // PostgreSQL 类型映射
        $type = strtolower($type);
        $pgType = $this->mapTypeToPostgres($type, $length);
        
        $type_length = $length ? "{$pgType}({$length})" : $pgType;
        
        // PostgreSQL 使用双引号
        $field_name = str_replace(['`', '"'], '', $field_name);
        
        // PostgreSQL 不支持 AFTER，字段总是添加到最后
        $fieldDef = "ADD COLUMN \"{$field_name}\" {$type_length} {$options}";
        $this->fields[] = $fieldDef;
        
        // 注释单独处理
        if ($comment) {
            $this->fields[] = "COMMENT ON COLUMN {$this->table}.\"{$field_name}\" IS '{$comment}';";
        }
        
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

    /**
     * @DESC          # 删除字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 16:09
     * 参数区：
     *
     * @param string $field_name
     *
     * @return AlterInterface
     */
    public function deleteColumn(string $field_name): AlterInterface
    {
        $field_name = str_replace(['`', '"'], '', $field_name);
        $this->delete_fields[$field_name] = $field_name;
        return $this;
    }

    /**
     * @DESC         |添加索引
     *
     * 参数区：
     *
     * @param string $type
     * @param string $name
     * @param array|string $column
     *
     * @return AlterInterface
     */
    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = 'BTREE'): AlterInterface
    {
        $name = \Weline\Framework\Database\Helper\Standar::getIndexName($this->table, $name);
        $type = strtoupper($type);
        $index_method = $index_method ? "USING {$index_method}" : '';
        
        if (is_string($column)) {
            $column = explode(',', $column);
        }
        
        // 处理字段名
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
                $this->indexes[] = "CREATE INDEX \"{$name}\" ON {$this->table} USING GIN (to_tsvector('english', {$column_str}));";
                break;
            case self::index_type_SPATIAL:
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

    /**
     * @DESC         |建表附加
     *
     * 参数区：
     *
     * @param string $additional_sql
     *
     * @return AlterInterface
     */
    public function addAdditional(string $additional_sql = ''): AlterInterface
    {
        $this->additional = $additional_sql;
        return $this;
    }

    /**
     * @DESC         |表约束
     *
     * 参数区：
     *
     * @param string $constraints
     *
     * @return AlterInterface
     */
    public function addConstraints(string $constraints = ''): AlterInterface
    {
        $this->constraints = $constraints;
        return $this;
    }

    public function alterColumn(string $old_field, string $field_name, string $after_field = '', string $type = '', string|int $length = '', string $options = '', string $comment = ''): AlterInterface
    {
        $old_field = str_replace(['`', '"'], '', $old_field);
        $field_name = str_replace(['`', '"'], '', $field_name);
        
        $not_need_length_types = [
            'text', 'mediumtext', 'longtext', 'tinytext', 'mediumblob', 'longblob', 'tinyblob', 'blob',
        ];
        
        if (in_array(strtolower($type), $not_need_length_types)) {
            $type_length = $this->mapTypeToPostgres(strtolower($type), null);
        } else {
            $pgType = $this->mapTypeToPostgres(strtolower($type), $length);
            $type_length = $length ? "{$pgType}({$length})" : $pgType;
        }
        
        $this->alter_fields[$old_field] = [
            'field_name' => $field_name, 
            'after_field' => $after_field, 
            'type_length' => $type_length, 
            'options' => $options, 
            'comment' => $comment
        ];

        return $this;
    }

    public function alter(bool $dump_sql = false): bool
    {
        if ($dump_sql) {
            $dump_sqls = [];
        }
        
        # --如果存在删除数组中则先删除字段
        foreach ($this->delete_fields as $delete_field) {
            $sql = "ALTER TABLE {$this->table} DROP COLUMN \"{$delete_field}\"";
            if ($dump_sql) {
                $dump_sqls[] = $sql;
            } else {
                try {
                    $this->query($sql)->fetch();
                } catch (\Exception $exception) {
                    exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                }
            }
        }
        
        # --如果存在要新增的字段
        if ($this->fields) {
            // 分离 ALTER TABLE 语句和 COMMENT 语句
            $alterStatements = [];
            $commentStatements = [];
            
            foreach ($this->fields as $field) {
                if (str_starts_with($field, 'COMMENT ON')) {
                    $commentStatements[] = $field;
                } else {
                    $alterStatements[] = $field;
                }
            }
            
            if (!empty($alterStatements)) {
                $fields = implode(', ', $alterStatements);
                $sql = "ALTER TABLE {$this->table} {$fields}";
                if ($dump_sql) {
                    $dump_sqls[] = $sql;
                } else {
                    try {
                        $this->query($sql);
                    } catch (\Exception $exception) {
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                    }
                }
            }
            
            // 执行注释语句
            foreach ($commentStatements as $commentSql) {
                if ($dump_sql) {
                    $dump_sqls[] = $commentSql;
                } else {
                    try {
                        $this->query($commentSql)->fetch();
                    } catch (\Exception $exception) {
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $commentSql) . PHP_EOL);
                    }
                }
            }
        }
        
        try {
            # 检测更新表注释
            if ($this->comment) {
                $sql = "COMMENT ON TABLE {$this->table} IS '{$this->comment}'";
                if ($dump_sql) {
                    $dump_sqls[] = $sql;
                } else {
                    try {
                        $this->query($sql)->fetch();
                    } catch (\Exception $exception) {
                        exit(__('更新表注释错误：%{1}', $exception->getMessage()) . PHP_EOL);
                    }
                }
            }
            
            $table_fields = $this->getTableColumns();
            # 字段编辑
            foreach ($table_fields as $table_field) {
                $fieldName = $table_field['Field'] ?? $table_field['column_name'] ?? null;
                if (!$fieldName) {
                    continue;
                }
                
                # --如果存在修改数组中则修改
                if (isset($this->alter_fields[$fieldName]) && $alter_field = $this->alter_fields[$fieldName]) {
                    // 字段重命名
                    if ($fieldName !== $alter_field['field_name']) {
                        $sql = "ALTER TABLE {$this->table} RENAME COLUMN \"{$fieldName}\" TO \"{$alter_field['field_name']}\"";
                        if ($dump_sql) {
                            $dump_sqls[] = $sql;
                        } else {
                            try {
                                $this->query($sql)->fetch();
                            } catch (\Exception $exception) {
                                exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                            }
                        }
                        $fieldName = $alter_field['field_name'];
                    }
                    
                    // 修改字段类型
                    $currentType = $table_field['Type'] ?? $table_field['data_type'] ?? '';
                    if ($alter_field['type_length'] && !str_contains(strtolower($currentType), strtolower($alter_field['type_length']))) {
                        $sql = "ALTER TABLE {$this->table} ALTER COLUMN \"{$fieldName}\" TYPE {$alter_field['type_length']}";
                        if ($dump_sql) {
                            $dump_sqls[] = $sql;
                        } else {
                            try {
                                $this->query($sql)->fetch();
                            } catch (\Exception $exception) {
                                exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                            }
                        }
                    }
                    
                    // 修改字段选项（NOT NULL, DEFAULT 等）
                    if ($alter_field['options']) {
                        // 这里可以添加更复杂的选项处理
                        // PostgreSQL 需要分别处理 NOT NULL, DEFAULT 等
                    }
                    
                    // 更新字段注释
                    if ($alter_field['comment']) {
                        $sql = "COMMENT ON COLUMN {$this->table}.\"{$fieldName}\" IS '{$alter_field['comment']}'";
                        if ($dump_sql) {
                            $dump_sqls[] = $sql;
                        } else {
                            try {
                                $this->query($sql)->fetch();
                            } catch (\Exception $exception) {
                                exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                            }
                        }
                    }
                }
            }

            # 是否修改表名
            if ($this->new_table_name) {
                $sql = "ALTER TABLE {$this->table} RENAME TO {$this->new_table_name}";
                if ($dump_sql) {
                    $dump_sqls[] = $sql;
                } else {
                    try {
                        $this->query($sql)->fetch();
                    } catch (\Exception $exception) {
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                    }
                }
            }
        } catch (\Exception $exception) {
            exit($exception->getMessage());
        }
        
        if ($dump_sql) {
            dd($dump_sqls);
        }

        return true;
    }

    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): AlterInterface
    {
        $on_delete_str = $on_delete ? 'ON DELETE CASCADE' : '';
        $on_update_str = $on_update ? 'ON UPDATE CASCADE' : '';
        
        $FK_Field = str_replace(['`', '"'], '', $FK_Field);
        $references_table = str_replace(['`', '"'], '', $references_table);
        $references_field = str_replace(['`', '"'], '', $references_field);
        
        if (!str_contains($references_table, '.')) {
            $references_table = "public.\"{$references_table}\"";
        } else {
            $parts = explode('.', $references_table);
            $references_table = "\"{$parts[0]}\".\"{$parts[1]}\"";
        }
        
        $this->foreign_keys[] = "ADD CONSTRAINT \"{$FK_Name}\" FOREIGN KEY (\"{$FK_Field}\") REFERENCES {$references_table} (\"{$references_field}\") {$on_delete_str} {$on_update_str}";
        return $this;
    }
}

