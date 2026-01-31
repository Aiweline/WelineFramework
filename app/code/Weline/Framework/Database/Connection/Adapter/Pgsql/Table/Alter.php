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
        # PostgreSQL 使用双引号而不是反引号
        # 处理表名：将反引号转换为双引号，处理 schema.table 格式
        # 在 PostgreSQL 中，如果表名包含点号，第一部分可能是数据库名（需要移除）或 schema 名
        $dbName = $this->connector->getConfigProvider()->getDatabase();
        
        if (str_contains($table_name, '.')) {
            $parts = explode('.', $table_name);
            $firstPart = trim($parts[0], '`"');
            
            // 如果第一部分是数据库名，移除它，使用 public schema
            if ($firstPart === $dbName) {
                $tableName = trim($parts[1] ?? $parts[0], '`"');
                $table_name = "public.\"{$tableName}\"";
            } else {
                // 第一部分是 schema 名，保持原样
                $parts = array_map(function($part) {
                    $part = trim($part, '`"');
                    return "\"{$part}\"";
                }, $parts);
                $table_name = implode('.', $parts);
            }
        } else {
            // 单个表名，使用 public schema
            $tableName = trim($table_name, '`"');
            $table_name = "public.\"{$tableName}\"";
        }
        
        // 处理新表名
        if ($new_table_name) {
            if (str_contains($new_table_name, '.')) {
                $parts = explode('.', $new_table_name);
                $firstPart = trim($parts[0], '`"');
                if ($firstPart === $dbName) {
                    $newTableName = trim($parts[1] ?? $parts[0], '`"');
                    $new_table_name = "public.\"{$newTableName}\"";
                } else {
                    $parts = array_map(function($part) {
                        $part = trim($part, '`"');
                        return "\"{$part}\"";
                    }, $parts);
                    $new_table_name = implode('.', $parts);
                }
            } else {
                $newTableName = trim($new_table_name, '`"');
                $new_table_name = "public.\"{$newTableName}\"";
            }
        }
        
        $this->startTable($table_name, $comment, $primary_key, $new_table_name);
        
        # 确保 $this->table 使用正确的 PostgreSQL 格式
        if (str_contains($this->table, '.')) {
            $parts = explode('.', $this->table);
            $parts = array_map(function($part) {
                $part = trim($part, '`"');
                return "\"{$part}\"";
            }, $parts);
            $this->table = implode('.', $parts);
        } else {
            $this->table = "public.\"" . trim($this->table, '`"') . "\"";
        }
        
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
        
        // PostgreSQL 中，只有某些类型支持长度参数
        // INTEGER, SMALLINT, BIGINT, REAL, DOUBLE PRECISION, TEXT, BYTEA, DATE, TIME, TIMESTAMP, JSONB 不支持长度
        // VARCHAR, CHAR, NUMERIC, DECIMAL 支持长度
        $typesWithoutLength = ['INTEGER', 'SMALLINT', 'BIGINT', 'REAL', 'DOUBLE PRECISION', 'TEXT', 'BYTEA', 'DATE', 'TIME', 'TIMESTAMP', 'JSONB', 'SERIAL', 'BIGSERIAL'];
        $type_length = $pgType;
        if ($length && !in_array(strtoupper($pgType), $typesWithoutLength)) {
            $type_length = "{$pgType}({$length})";
        }
        
        // 处理 UNSIGNED (PostgreSQL 不支持，需要移除)
        $options = preg_replace('/\bunsigned\b/i', '', $options);
        $options = trim($options);
        
        // 处理 ON UPDATE CURRENT_TIMESTAMP (PostgreSQL 不支持，需要移除)
        $options = preg_replace('/\bon\s+update\s+current_timestamp\b/i', '', $options);
        $options = trim($options);
        
        // 处理 default "" (PostgreSQL 不支持双引号空字符串，需要转换为单引号)
        $options = preg_replace('/default\s+""/i', "default ''", $options);
        
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
     * 生成 PostgreSQL 类型转换的 USING 子句
     * 
     * @param string $fieldName 字段名
     * @param string $targetType 目标类型
     * @return string USING 子句
     */
    private function generateUsingClause(string $fieldName, string $targetType): string
    {
        // 移除长度信息，只保留基础类型
        $baseType = preg_replace('/\([^)]+\)/', '', $targetType);
        $baseType = strtoupper(trim($baseType));
        
        // 根据目标类型生成 USING 表达式
        $numericTypes = ['INTEGER', 'SMALLINT', 'BIGINT', 'NUMERIC', 'DECIMAL', 'REAL', 'DOUBLE PRECISION'];
        $textTypes = ['VARCHAR', 'CHAR', 'TEXT'];
        $boolTypes = ['BOOLEAN', 'BOOL'];
        $dateTypes = ['DATE', 'TIME', 'TIMESTAMP', 'TIMESTAMPTZ'];
        
        if (in_array($baseType, $numericTypes)) {
            // 转换为数值类型：尝试将字符串转为数值
            return " USING \"{$fieldName}\"::{$baseType}";
        } elseif (in_array($baseType, $textTypes)) {
            // 转换为文本类型：直接转换
            return " USING \"{$fieldName}\"::TEXT";
        } elseif (in_array($baseType, $boolTypes)) {
            // 转换为布尔类型
            return " USING \"{$fieldName}\"::BOOLEAN";
        } elseif (in_array($baseType, $dateTypes)) {
            // 转换为日期时间类型
            return " USING \"{$fieldName}\"::{$baseType}";
        }
        
        // 默认情况：使用目标类型进行转换
        return " USING \"{$fieldName}\"::{$baseType}";
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
        // 确保索引名称去除反引号，使用双引号
        $name = trim(str_replace(['`', '"'], '', $name));
        
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
            // PostgreSQL 中，只有某些类型支持长度参数
            // INTEGER, SMALLINT, BIGINT, REAL, DOUBLE PRECISION, TEXT, BYTEA, DATE, TIME, TIMESTAMP, JSONB 不支持长度
            // VARCHAR, CHAR, NUMERIC, DECIMAL 支持长度
            $typesWithoutLength = ['INTEGER', 'SMALLINT', 'BIGINT', 'REAL', 'DOUBLE PRECISION', 'TEXT', 'BYTEA', 'DATE', 'TIME', 'TIMESTAMP', 'JSONB', 'SERIAL', 'BIGSERIAL'];
            $type_length = $pgType;
            if ($length && !in_array(strtoupper($pgType), $typesWithoutLength)) {
                $type_length = "{$pgType}({$length})";
            }
        }
        
        // 处理 UNSIGNED (PostgreSQL 不支持，需要移除)
        $options = preg_replace('/\bunsigned\b/i', '', $options);
        $options = trim($options);
        
        // 处理 ON UPDATE CURRENT_TIMESTAMP (PostgreSQL 不支持，需要移除)
        $options = preg_replace('/\bon\s+update\s+current_timestamp\b/i', '', $options);
        $options = trim($options);
        
        // 处理 default "" (PostgreSQL 不支持双引号空字符串，需要转换为单引号)
        $options = preg_replace('/default\s+""/i', "default ''", $options);
        
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
                        $this->query($sql)->fetch();  // 🔧 修复：必须调用 fetch() 才能真正执行 SQL
                    } catch (\Exception $exception) {
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                    }
                }
            }
            
            // 执行注释语句（仅在字段真实存在时执行，避免因列缺失导致升级中断）
            foreach ($commentStatements as $commentSql) {
                if ($dump_sql) {
                    $dump_sqls[] = $commentSql;
                } else {
                    // 简单从 COMMENT 语句中解析出字段名，用于存在性检测
                    $fieldName = null;
                    if (preg_match('/COMMENT\s+ON\s+COLUMN\s+.+?"([^"]+)"\s+IS\s+/i', $commentSql, $m)) {
                        $fieldName = $m[1] ?? null;
                    }

                    // 如果能解析出字段名且字段不存在，则跳过该注释语句
                    if ($fieldName) {
                        try {
                            if (!$this->connector->hasField($this->table, $fieldName)) {
                                // 字段尚未存在或添加失败，跳过注释，避免报错中断
                                continue;
                            }
                        } catch (\Throwable $e) {
                            // 检测失败时不阻断流程，继续尝试执行注释
                        }
                    }

                    try {
                        $this->query($commentSql)->fetch();
                    } catch (\Exception $exception) {
                        // 注释失败不再中断整个升级流程，仅输出提示信息
                        // 原因场景：字段已被删除或未成功添加，但不影响结构主流程
                        echo $exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $commentSql) . PHP_EOL;
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
                        // PostgreSQL 需要 USING 子句来进行类型转换
                        // 根据目标类型生成合适的 USING 表达式
                        $targetType = strtoupper($alter_field['type_length']);
                        $usingClause = $this->generateUsingClause($fieldName, $targetType);
                        $sql = "ALTER TABLE {$this->table} ALTER COLUMN \"{$fieldName}\" TYPE {$alter_field['type_length']}{$usingClause}";
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
        
        // 处理表名：移除数据库名（如果存在），使用 public schema
        $dbName = $this->connector->getConfigProvider()->getDatabase();
        $schema = 'public';
        
        if (str_contains($references_table, '.')) {
            $parts = explode('.', $references_table);
            $firstPart = trim($parts[0]);
            
            // 如果第一部分是数据库名，移除它，使用 public schema
            if ($firstPart === $dbName) {
                $tableName = trim($parts[1] ?? $parts[0]);
                $references_table = "{$schema}.\"{$tableName}\"";
            } else {
                // 第一部分是 schema 名
                $schema = $firstPart;
                $tableName = trim($parts[1] ?? $parts[0]);
                $references_table = "{$schema}.\"{$tableName}\"";
            }
        } else {
            // 单个表名，使用 public schema
            $references_table = "{$schema}.\"{$references_table}\"";
        }
        
        $this->foreign_keys[] = "ADD CONSTRAINT \"{$FK_Name}\" FOREIGN KEY (\"{$FK_Field}\") REFERENCES {$references_table} (\"{$references_field}\") {$on_delete_str} {$on_update_str}";
        return $this;
    }
}

