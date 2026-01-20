<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Dialect\GenericDialectAdapter;
use Weline\Framework\Database\Connection\Api\Sql\Query;
use Weline\Framework\Database\Exception\DbException;

class PgsqlDialectAdapter extends GenericDialectAdapter
{
    public function compile(Query $query, string $action): string
    {
        // 🔧 在调用父类之前，确保有 JOIN 时主表有别名
        if (!empty($query->joins) && empty($query->table_alias)) {
            $query->table_alias = 'main_table';
        }
        
        $sql = parent::compile($query, $action);
        
        // 如果 SQL 为空，不需要转换和准备语句
        if (empty($sql)) {
            return $query->sql;
        }
        
        // 转换 SQL 中可能遗留的反引号（处理其他可能遗漏的部分，如字段列表等）
        $converted = $this->convertBackticks($sql);
        
        // 🔧 修复 PostgreSQL 中表别名引用问题
        // PostgreSQL 支持不带 AS 的别名（如：FROM table alias），但别名需要用双引号引用以确保大小写敏感
        // 匹配：FROM table AS alias 或 FROM table alias 或 FROM "table" AS alias 或 FROM "table" alias
        // 使用 (?:AS\s+)? 来匹配可选的 AS 关键字
        $beforeAliasFix = $converted;
        $converted = preg_replace_callback(
            '/FROM\s+([^`"\s]+(?:\.[^`"\s]+)?|"[^"]+"|`[^`]+`)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*|"[^"]+")(?=\s+(?:LEFT|RIGHT|INNER|OUTER)?\s*JOIN|\s+WHERE|\s*$)/i',
            function ($matches) {
                $table = $matches[1];
                $alias = $matches[2];
                
                // 移除表名上的引号（如果存在），然后重新引用
                $table = trim($table, '`"');
                if (str_contains($table, '.')) {
                    $parts = explode('.', $table);
                    $table = '"' . implode('"."', $parts) . '"';
                } else {
                    $table = '"' . $table . '"';
                }
                
                // 移除别名上的引号（如果存在），然后重新引用
                $alias = trim($alias, '"');
                
                // 别名总是需要引用（确保大小写敏感）
                // PostgreSQL 支持不带 AS 的别名，但为了清晰性和兼容性，我们统一添加 AS
                return "FROM {$table} AS \"{$alias}\"";
            },
            $converted
        );
        
        // 🔧 如果 FROM 子句没有别名，但 JOIN 条件中使用了 main_table，需要添加别名
        // 检查是否有 JOIN 且 JOIN 条件中使用了 main_table
        $hasMainTableInJoin = !empty($query->joins) && preg_match('/\bmain_table\b/i', $converted);
        $hasFromAlias = preg_match('/FROM\s+[^`"\s]+(?:\.[^`"\s]+)?|"[^"]+"|`[^`]+`\s+(?:AS\s+)?[a-zA-Z_][a-zA-Z0-9_]*/i', $converted);
        
        if ($hasMainTableInJoin && !$hasFromAlias) {
            // FROM 子句没有别名，需要添加
            $beforeAddAlias = $converted;
            $converted = preg_replace_callback(
                '/FROM\s+([^`"\s]+(?:\.[^`"\s]+)?|"[^"]+"|`[^`]+`)(?=\s+(?:LEFT|RIGHT|INNER|OUTER)?\s*JOIN|\s+WHERE|\s*$)/i',
                function ($matches) use ($query) {
                    $table = $matches[1];
                    
                    // 移除表名上的引号（如果存在），然后重新引用
                    $table = trim($table, '`"');
                    
                    // 如果包含点号，说明是 schema.table 格式
                    if (str_contains($table, '.')) {
                        $parts = explode('.', $table);
                        $table = '"' . implode('"."', $parts) . '"';
                    } else {
                        $table = '"' . $table . '"';
                    }
                    
                    // 使用查询对象的 table_alias（默认为 main_table）
                    $alias = $query->table_alias ?: 'main_table';
                    
                    // 添加别名
                    return "FROM {$table} AS \"{$alias}\"";
                },
                $converted
            );
        }
        
        // 🔧 修复：如果主表别名不是 main_table，但 SELECT 或 ORDER BY 中使用了 main_table，需要替换
        $mainTableAlias = $query->table_alias ?: 'main_table';
        if ($mainTableAlias !== 'main_table' && preg_match('/\bmain_table\b/i', $converted)) {
            $formatter = $query->getIdentifierFormatter();
            $quotedActualAlias = $formatter->quote($mainTableAlias);
            
            // 替换 SELECT 子句中的 main_table.* 或 main_table.field
            $converted = preg_replace_callback(
                '/(SELECT\s+)(.*?)(\s+FROM)/is',
                function ($matches) use ($quotedActualAlias) {
                    $selectPart = $matches[2];
                    // 替换 main_table.* 为实际主表别名（如 "a".*）
                    $selectPart = preg_replace('/([`"]?)main_table\1\.\*/i', $quotedActualAlias . '.*', $selectPart);
                    // 替换 "main_table".field 或 `main_table`.field 或 main_table.field 为实际主表别名
                    $selectPart = preg_replace('/([`"]?)main_table\1(\.)/i', $quotedActualAlias . '$2', $selectPart);
                    return $matches[1] . $selectPart . $matches[3];
                },
                $converted
            );
            
            // 替换 ORDER BY 子句中的 main_table.field
            // 直接在整个 SQL 中替换 ORDER BY 后面的 main_table（更简单可靠）
            if (preg_match('/ORDER\s+BY/i', $converted)) {
                // 找到 ORDER BY 的位置，然后替换其后面的 main_table
                $converted = preg_replace_callback(
                    '/(ORDER\s+BY\s+)(.*?)(?=\s+(?:LIMIT|OFFSET|$))/is',
                    function ($matches) use ($quotedActualAlias) {
                        $orderPart = $matches[2];
                        // 替换 "main_table".field 或 `main_table`.field 或 main_table.field
                        $orderPart = preg_replace('/([`"]?)main_table\1(\.)/i', $quotedActualAlias . '$2', $orderPart);
                        return $matches[1] . $orderPart;
                    },
                    $converted
                );
            }
        }
        
        if ($converted !== $sql) {
            $query->sql = $converted;
            // 重新准备语句（因为 SQL 已更改）
            if (!empty($converted)) {
                try {
                    $query->PDOStatement = $query->getLink()->prepare($converted);
                } catch (\Throwable $e) {
                    $query->PDOStatement = null;
                }
            } else {
                $query->PDOStatement = null;
            }
        }
        return $query->sql;
    }
    

    /**
     * 重写 buildJoins 方法，修复 JOIN 条件中 main_table 引用问题
     * 当主表别名不是 main_table 时，需要将 JOIN 条件中的 main_table 替换为实际的主表别名
     */
    protected function buildJoins(Query $query): string
    {
        $formatter = $query->getIdentifierFormatter();
        $joins = '';
        $mainTableAlias = $query->table_alias ?: 'main_table';
        
        foreach ($query->joins as $join) {
            $table = $join[0];
            $condition = $join[1];
            $type = $join[2];
            
            // 使用格式化器处理表名中的标识符（移除反引号/双引号，使用正确的格式化器）
            $table = $this->formatTableNameInJoin($table, $formatter);
            
            // 使用格式化器处理条件中的标识符
            $condition = $this->formatConditionInJoin($condition, $formatter);
            
            // 🔧 修复：如果主表别名不是 main_table，但 JOIN 条件中使用了 main_table，需要替换
            if ($mainTableAlias !== 'main_table' && preg_match('/\bmain_table\b/i', $condition)) {
                // 替换 JOIN 条件中的 main_table 为实际的主表别名
                // 处理三种情况：
                // 1. "main_table".field 或 `main_table`.field
                // 2. main_table.field（不带引号）
                // 3. "main_table" 或 `main_table`（单独出现，但这种情况较少）
                $condition = preg_replace_callback(
                    '/([`"]?)main_table\1(\.)/i',
                    function ($matches) use ($formatter, $mainTableAlias) {
                        // 无论原条件是否使用引号，都使用格式化器引用别名（确保一致性）
                        return $formatter->quote($mainTableAlias) . $matches[2];
                    },
                    $condition
                );
                
                // 处理单独出现的 main_table（不带点号的情况，虽然较少见）
                $condition = preg_replace_callback(
                    '/([^`"a-zA-Z0-9_])([`"]?)main_table\2([^`"a-zA-Z0-9_])/i',
                    function ($matches) use ($formatter, $mainTableAlias) {
                        return $matches[1] . $formatter->quote($mainTableAlias) . $matches[3];
                    },
                    $condition
                );
            }
            
            $joins .= " {$type} JOIN {$table} ON {$condition} ";
        }
        return $joins;
    }

    protected function buildUpdate(Query $query, string $wheres): string
    {
        $formatter = $query->getIdentifierFormatter();
        $identity_values = array_column($query->updates, $query->identity_field);
        if ($identity_values) {
            $identity_values_str = '';
            foreach ($identity_values as $key => $identityValue) {
                $identity_values_key = ':' . md5('update_identity_values_key' . $key);
                $identity_values_str .= $identity_values_key . ',';
                $query->bound_values[$identity_values_key] = (string)$identityValue;
            }
            $identity_values_str = rtrim($identity_values_str, ',');
            $identity_field_quoted = $formatter->quote($query->identity_field);
            $wheres .= ($wheres ? ' AND ' : 'WHERE ') . "{$identity_field_quoted} IN ($identity_values_str)";
        }

        if (empty($wheres)) {
            throw new DbException(__('请设置更新条件：第一种方式，->where($condition)设置，第二种方式，更新数据中包含条件值（默认为字段id,可自行设置->update($arg1,$arg2)第二参数指定根据数组中的某个字段值作为依赖条件更新。）'));
        }

        $updates = '';
        if ($query->dec_inc_updates) {
            foreach ($query->dec_inc_updates as $dec_inc_update_field => $dec_inc_update_value) {
                $field_quoted = $formatter->quote($dec_inc_update_field);
                $updates .= "{$field_quoted} = {$field_quoted} {$dec_inc_update_value},";
            }
        }
        if ($query->updates) {
            if ($identity_values) {
                $keys = array_keys(current($query->updates));
                $identity_field_quoted = $formatter->quote($query->identity_field);
                foreach ($keys as $column) {
                    $column_quoted = $formatter->quote($column);
                    $updates .= sprintf("%s = CASE %s \n", $column_quoted, $identity_field_quoted);
                    foreach ($query->updates as $update_key => $line) {
                        $update_key += 1;
                        $identity_field_column_key = ':' . md5("{$query->identity_field}_{$column}_key_{$update_key}");
                        $query->bound_values[$identity_field_column_key] = (string)$line[$query->identity_field];
                        $identity_field_column_value = ':' . md5("update_{$column}_value_{$update_key}");
                        $value = $line[$column];
                        // 🔧 PostgreSQL CASE 表达式类型处理：
                        // PostgreSQL 在 CASE 表达式中要求所有分支返回相同类型
                        // 如果值是整数或数字字符串，需要在 CASE 表达式中使用 CAST 显式转换
                        // 因为 PostgreSQL 无法自动将字符串转换为 smallint/integer
                        if (is_bool($value)) {
                            $query->bound_values[$identity_field_column_value] = $value ? '1' : '0';
                            // 布尔值转换为字符串后，在 CASE 表达式中使用 CAST 转换为 INTEGER
                            $updates .= sprintf('WHEN %s THEN CAST(%s AS INTEGER) ', $identity_field_column_key, $identity_field_column_value);
                        } elseif (is_int($value) || (is_string($value) && is_numeric($value) && !str_contains($value, '.'))) {
                            // 整数或数字字符串（不包含小数点），保持原样绑定，但在 CASE 表达式中使用 CAST
                            // 使用 INTEGER 类型，PostgreSQL 可以隐式转换为 smallint
                            $query->bound_values[$identity_field_column_value] = $value;
                            $updates .= sprintf('WHEN %s THEN CAST(%s AS INTEGER) ', $identity_field_column_key, $identity_field_column_value);
                        } else {
                            // 其他类型（字符串、浮点数等），转换为字符串
                            $query->bound_values[$identity_field_column_value] = (string)$value;
                            $updates .= sprintf('WHEN %s THEN %s ', $identity_field_column_key, $identity_field_column_value);
                        }
                    }
                    $updates .= 'END,';
                }
            } else {
                if (1 < count($query->updates)) {
                    throw new \Exception(__('更新条数大于一条时请使用示例更新：$query->table("demo")->identity("id")->update(["id"=>1,"name"=>"测试1"])->update(["id"=>2,"name"=>"测试2"])或者update中指定条件字段id：$query->table("demo")->update([["id"=>1,"name"=>"测试1"],["id"=>2,"name"=>"测试2"]],"id")'));
                }
                foreach ($query->updates[0] as $update_field => $field_value) {
                    $update_key = ':' . md5($update_field);
                    $update_field_quoted = $formatter->quote($update_field);
                    // 处理数组值：序列化为 JSON
                    if (is_array($field_value)) {
                        $field_value = json_encode($field_value, JSON_UNESCAPED_UNICODE);
                    }
                    $query->bound_values[$update_key] = $field_value === null ? null : (string)$field_value;
                    $updates .= "{$update_field_quoted} = $update_key,";
                }
            }
        }
        if ($query->single_updates) {
            foreach ($query->single_updates as $update_field => $update_value) {
                $update_field_quoted = $formatter->quote($update_field);
                $update_key = ':' . md5($update_field);
                // 处理数组值：序列化为 JSON
                if (is_array($update_value)) {
                    $update_value = json_encode($update_value, JSON_UNESCAPED_UNICODE);
                }
                $query->bound_values[$update_key] = $update_value === null ? null : (string)$update_value;
                $updates .= "{$update_field_quoted}=$update_key,";
            }
        }
        if (!$updates) {
            throw new \Exception(__('无法解析更新数据！多记录更新数据：%{1}，单记录更新数据：%{2}', [var_export($query->updates, true), var_export($query->single_updates, true)]));
        }
        $updates = rtrim($updates, ',');
        
        $sql = "UPDATE {$query->table} SET {$updates} {$wheres} {$query->additional_sql} ";
        
        // 🔧 为 PostgreSQL 的 UPDATE 语句添加 RETURNING 子句，以便获取受影响的行数
        // 这样可以避免 fetchAll() 返回空数组导致 (bool)[] 为 false 的问题
        if ($query->identity_field) {
            $identity_field_quoted = $formatter->quote($query->identity_field);
            // 检查 SQL 中是否已经包含 RETURNING 子句
            if (stripos($sql, 'RETURNING') === false) {
                // 移除末尾的分号（如果存在）
                $sql = rtrim(trim($sql), ';');
                // 添加 RETURNING 子句，返回主键值（用于确认更新成功）
                $sql .= " RETURNING {$identity_field_quoted}";
            }
        }
        
        return $sql;
    }

    /**
     * 重写 buildInsert，为 PostgreSQL 使用 ON CONFLICT 实现真正的 upsert
     * 当指定了 insert_update_where_fields 时，使用 PostgreSQL 的 ON CONFLICT 语法
     */
    protected function buildInsert(Query $query): string
    {
        $formatter = $query->getIdentifierFormatter();
        $insert_items = $query->insert['insert'] ?? [];
        $insert_or_update_items = $query->insert['i_o_u'] ?? [];
        $insert_origin = $query->insert['origin'] ?? [];
        
        // 🔧 修复：如果有 insert_update_where_fields，将 insert['origin'] 中的数据也视为需要 insertOrUpdate 处理
        // 这样可以自动检测冲突并更新，而不需要先查询数据库
        if (!empty($insert_origin) && !empty($query->insert_update_where_fields)) {
            // 将 origin 中的数据合并到 insert_or_update_items 中
            if (empty($insert_or_update_items)) {
                $insert_or_update_items = [];
            }
            // 处理 origin 数据（可能是单个数组或多个数组）
            if (isset($insert_origin[0]) && is_array($insert_origin[0])) {
                // 多个记录（数组的数组）
                $insert_or_update_items = array_merge($insert_or_update_items, $insert_origin);
            } elseif (is_array($insert_origin) && !empty($insert_origin)) {
                // 单个记录（关联数组）
                // 检查是否是关联数组（有字符串键）还是索引数组
                if (is_string(array_key_first($insert_origin))) {
                    // 关联数组，是单个记录
                    $insert_or_update_items[] = $insert_origin;
                } else {
                    // 索引数组，可能是多个记录
                    $insert_or_update_items = array_merge($insert_or_update_items, $insert_origin);
                }
            }
        }
        
        // 保存 insert 数据的副本，以便在需要时回退到父类逻辑
        $insert_origin_backup = $query->insert['origin'] ?? null;
        $insert_update_where_fields_backup = $query->insert_update_where_fields ?? [];
        $insert_update_fields_backup = $query->insert_update_fields ?? [];
        
        unset($query->insert['i_o_u'], $query->insert['origin'], $query->insert['insert']);
        
        // 如果有 insert_update_where_fields，尝试使用 PostgreSQL 的 ON CONFLICT 语法实现真正的 upsert
        // 注意：如果字段没有唯一约束，ON CONFLICT 会失败（42P10），此时需要回退到父类逻辑
        if (!empty($insert_or_update_items) && !empty($query->insert_update_where_fields)) {
            // 构建 INSERT ... ON CONFLICT ... DO UPDATE SET 语句
            $sql = $this->buildInsertWithOnConflict($query, $insert_or_update_items, $formatter);
            
            // 如果还有普通的 insert_items，需要合并处理
            if (!empty($insert_items)) {
                // 对于普通的 insert_items，也使用 ON CONFLICT（如果有唯一字段）
                $normalInsertSql = $this->buildNormalInsert($query, $insert_items, $formatter);
                if (!empty($normalInsertSql)) {
                    // 合并 SQL（用分号分隔，但注意 PostgreSQL prepared statement 不支持多个命令）
                    // 所以我们需要分别处理
                    $sql = $normalInsertSql . ($sql ? '; ' . $sql : '');
                }
            }
            
            // 设置 SQL 和备份数据（用于回退）
            // 使用数组存储备份数据，避免 PHP 8.2+ 动态属性警告
            $query->sql = $sql;
            if (!isset($query->_fallback_data)) {
                $query->_fallback_data = [];
            }
            $query->_fallback_data['insert_origin'] = $insert_origin_backup;
            $query->_fallback_data['insert_update_where_fields'] = $insert_update_where_fields_backup;
            $query->_fallback_data['insert_update_fields'] = $insert_update_fields_backup;
            
            // 准备语句
            if (!empty($sql)) {
                try {
                    $query->PDOStatement = $query->getLink()->prepare($sql);
                } catch (\Throwable $e) {
                    $query->PDOStatement = null;
                }
            } else {
                $query->PDOStatement = null;
            }
            
            return $sql;
        }
        
        // 否则使用父类的逻辑（先查询再更新）
        $sql = parent::buildInsert($query);
        
        // 如果 SQL 为空，直接返回
        if (empty($sql)) {
            return $sql;
        }
        
        // 🔧 修复：如果 SQL 是 UPDATE 语句（当所有记录都已存在时），不需要添加 RETURNING 子句
        // 因为 PostgreSQL 的 prepared statement 不支持多个 SQL 命令（用分号分隔）
        // 多个 UPDATE 语句需要使用 exec() 执行，而不是 prepare/execute
        // 注意：如果 SQL 包含多个 UPDATE 语句（用分号分隔），fetch 方法会使用 exec() 执行
        // 所以这里不需要特殊处理，保持原样即可
        if ($query->identity_field && !preg_match('/^\s*UPDATE/i', $sql)) {
            // 为 PostgreSQL 的 INSERT 语句添加 RETURNING 子句
            // 这样可以获取插入的 ID，即使手动指定了 ID 值
            $formatter = $query->getIdentifierFormatter();
            $identity_field_quoted = $formatter->quote($query->identity_field);
            
            // 检查 SQL 中是否已经包含 RETURNING 子句
            if (stripos($sql, 'RETURNING') !== false) {
                return $sql;
            }
            
            // 移除末尾的分号（如果存在）
            $sql = rtrim(trim($sql), ';');
            
            // 检查是否是 INSERT 语句
            if (preg_match('/^\s*INSERT\s+INTO/i', $sql)) {
                // 在语句末尾添加 RETURNING 子句
                // 注意：不添加分号，因为 PostgreSQL prepared statement 不需要末尾分号
                $sql .= " RETURNING {$identity_field_quoted}";
            }
        }
        
        return $sql;
    }
    
    /**
     * 使用 PostgreSQL 的 ON CONFLICT 语法构建 INSERT 语句
     */
    private function buildInsertWithOnConflict(Query $query, array $insert_or_update_items, $formatter): string
    {
        if (empty($insert_or_update_items)) {
            return '';
        }
        
        // 获取第一个记录来确定字段
        $firstItem = reset($insert_or_update_items);
        $fields = array_keys($firstItem);
        $fieldsQuoted = array_map(function($field) use ($formatter) {
            return $formatter->quote($field);
        }, $fields);
        $fieldsStr = '(' . implode(', ', $fieldsQuoted) . ')';
        
        // 构建 VALUES 部分
        $valuesParts = [];
        foreach ($insert_or_update_items as $item) {
            $valueParts = [];
            foreach ($fields as $field) {
                $value = $item[$field] ?? null;
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $paramKey = ':' . md5('insert_' . $field . '_' . count($valuesParts) . '_' . $item[$query->insert_update_where_fields[0] ?? '']);
                $query->bound_values[$paramKey] = $value === null ? null : (string)$value;
                $valueParts[] = $paramKey;
            }
            $valuesParts[] = '(' . implode(', ', $valueParts) . ')';
        }
        $valuesStr = implode(', ', $valuesParts);
        
        // 构建 ON CONFLICT 部分
        // PostgreSQL 的 ON CONFLICT 语法：ON CONFLICT (column_name) 或 ON CONFLICT ON CONSTRAINT constraint_name
        // 这里使用列名形式，因为 source_id 字段上有唯一约束
        $conflictFields = array_map(function($field) use ($formatter) {
            return $formatter->quote($field);
        }, $query->insert_update_where_fields);
        $conflictStr = '(' . implode(', ', $conflictFields) . ')';
        
        // 构建 DO UPDATE SET 部分
        $updateParts = [];
        $updateFields = $query->insert_update_fields ?? $fields;
        foreach ($updateFields as $field) {
            // 跳过唯一字段（ON CONFLICT 的冲突检测字段）
            if (in_array($field, $query->insert_update_where_fields, true)) {
                continue;
            }
            // 🔧 修复：跳过主键字段（identity_field），因为主键不应该在 UPDATE 时被修改
            // 主键字段可能已经在 ON CONFLICT 中，或者不应该被更新
            if ($query->identity_field && $field === $query->identity_field) {
                continue;
            }
            $fieldQuoted = $formatter->quote($field);
            $updateParts[] = "{$fieldQuoted} = EXCLUDED.{$fieldQuoted}";
        }
        
        // 如果没有要更新的字段，尝试更新所有字段（除了唯一字段和主键字段）
        if (empty($updateParts)) {
            foreach ($fields as $field) {
                // 跳过唯一字段（ON CONFLICT 的冲突检测字段）
                if (in_array($field, $query->insert_update_where_fields, true)) {
                    continue;
                }
                // 🔧 修复：跳过主键字段（identity_field），因为主键不应该在 UPDATE 时被修改
                if ($query->identity_field && $field === $query->identity_field) {
                    continue;
                }
                $fieldQuoted = $formatter->quote($field);
                $updateParts[] = "{$fieldQuoted} = EXCLUDED.{$fieldQuoted}";
            }
        }
        
        // 构建完整的 INSERT ... ON CONFLICT 语句
        $sql = "INSERT INTO {$query->table} {$fieldsStr} VALUES {$valuesStr}";
        
        // 如果没有要更新的字段，使用 DO NOTHING（避免语法错误）
        if (empty($updateParts)) {
            $sql .= " ON CONFLICT {$conflictStr} DO NOTHING";
        } else {
            $updateStr = implode(', ', $updateParts);
            $sql .= " ON CONFLICT {$conflictStr} DO UPDATE SET {$updateStr}";
        }
        
        // 添加 RETURNING 子句
        if ($query->identity_field) {
            $identity_field_quoted = $formatter->quote($query->identity_field);
            $sql .= " RETURNING {$identity_field_quoted}";
        }
        
        return $sql;
    }
    
    /**
     * 构建普通的 INSERT 语句（没有 upsert）
     */
    private function buildNormalInsert(Query $query, array $insert_items, $formatter): string
    {
        if (empty($insert_items)) {
            return '';
        }
        
        // 获取第一个记录来确定字段
        $firstItem = reset($insert_items);
        $fields = array_keys($firstItem);
        $fieldsQuoted = array_map(function($field) use ($formatter) {
            return $formatter->quote($field);
        }, $fields);
        $fieldsStr = '(' . implode(', ', $fieldsQuoted) . ')';
        
        // 构建 VALUES 部分
        $valuesParts = [];
        foreach ($insert_items as $item) {
            $valueParts = [];
            foreach ($fields as $field) {
                $value = $item[$field] ?? null;
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $paramKey = ':' . md5('insert_' . $field . '_' . count($valuesParts));
                $query->bound_values[$paramKey] = $value === null ? null : (string)$value;
                $valueParts[] = $paramKey;
            }
            $valuesParts[] = '(' . implode(', ', $valueParts) . ')';
        }
        $valuesStr = implode(', ', $valuesParts);
        
        // 构建 INSERT 语句
        $sql = "INSERT INTO {$query->table} {$fieldsStr} VALUES {$valuesStr}";
        
        // 添加 RETURNING 子句
        if ($query->identity_field) {
            $identity_field_quoted = $formatter->quote($query->identity_field);
            $sql .= " RETURNING {$identity_field_quoted}";
        }
        
        return $sql;
    }



    /**
     * 重写 buildWheres 方法，确保 PostgreSQL 中整数类型字段使用整数类型参数
     * 修复 PostgreSQL 查询时整数字段与字符串参数类型不匹配的问题
     */
    protected function buildWheres(Query $query): string
    {
        $formatter = $query->getIdentifierFormatter();
        $wheres = '';
        if (!$query->wheres) {
            return $wheres;
        }
        $wheres .= ' WHERE ';
        $logic = 'AND ';
        foreach ($query->wheres as $key => $where) {
            // 检查是否已经引用（包含引号或反引号）
            $isQuoted = str_contains((string)$where[0], '`') || 
                       str_contains((string)$where[0], '"') || 
                       str_contains((string)$where[0], "'");
            if (!$isQuoted) {
                if (str_contains($where[0], '.')) {
                    $where0items = explode('.', $where[0]);
                    $where[0] = $formatter->quoteQualified(...$where0items);
                } else {
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $where[0])) {
                        $where[0] = $formatter->quote($where[0]);
                    }
                }
            }
            $key += 1;
            if (isset($where[3])) {
                $logic = array_pop($where) . ' ';
            }
            switch (count($where)) {
                case 1:
                    $wheres .= $where[0] . " {$logic} ";
                    break;
                default:
                    if ($where[2] === null) {
                        $wheres .= '(' . $where[0] . ') ' . $logic;
                        break;
                    }
                    // 规范化字段名用于生成参数名（移除所有引用符号）
                    $normalized_field = $formatter->normalize($where[0]);
                    // 确保移除所有引号（包括反引号和双引号），避免参数名包含非法字符
                    $normalized_field = str_replace(['`', '"'], '', $normalized_field);
                    $param = ':' . str_replace([' ', '(', ')', ','], '_', $normalized_field);
                    $param = str_replace('.', '__', $param) . $key;
                    $skip_implode = false;
                    switch (strtolower($where[1])) {
                        case 'in':
                        case 'not in':
                        case 'find_in_set':
                            $set_where = '(';
                            if (is_array($where[2])) {
                                foreach ($where[2] as $in_where_key => $item) {
                                    if (is_string($in_where_key)) {
                                        $in_where_key = preg_replace('/[^A-Za-z_]/', '', $in_where_key);
                                    }
                                    $set_where_key_param = $param . '_' . $in_where_key . '_' . str_replace(' ', '_', $where[1]);
                                    // 🔧 PostgreSQL 类型处理：对于整数或布尔值，保持为整数类型
                                    if (is_bool($item)) {
                                        $query->bound_values[$set_where_key_param] = $item ? 1 : 0;
                                    } elseif (is_int($item) || (is_string($item) && is_numeric($item) && !str_contains((string)$item, '.'))) {
                                        $query->bound_values[$set_where_key_param] = (int)$item;
                                    } else {
                                        $query->bound_values[$set_where_key_param] = (string)$item;
                                    }
                                    $set_where .= $set_where_key_param . ',';
                                }
                                $where[2] = rtrim($set_where, ',') . ')';
                                break;
                            }
                        case 'like':
                        case 'not like':
                            // LIKE 和 NOT LIKE 不需要等号，直接使用字段 LIKE 参数
                            $value = $where[2];
                            if (is_bool($value)) {
                                $value = $value ? 1 : 0;
                            } elseif (is_int($value) || (is_string($value) && is_numeric($value) && !str_contains((string)$value, '.'))) {
                                $value = (int)$value;
                            } else {
                                $value = (string)$value;
                            }
                            $query->bound_values[$param] = $value;
                            // 直接构建 WHERE 子句，不使用 implode，避免添加等号
                            $wheres .= '(' . $where[0] . ' ' . strtoupper($where[1]) . ' ' . $param . ') ' . $logic;
                            $skip_implode = true;
                            break;
                        // no break
                        default:
                            // 🔧 PostgreSQL 类型处理：对于整数或布尔值，保持为整数类型而不是字符串
                            // 这确保 PostgreSQL 能够正确匹配 smallint/integer 类型的字段
                            $value = $where[2];
                            if (is_bool($value)) {
                                // 布尔值转换为整数
                                $query->bound_values[$param] = $value ? 1 : 0;
                            } elseif (is_int($value)) {
                                // 已经是整数，直接使用
                                $query->bound_values[$param] = $value;
                            } elseif (is_string($value) && is_numeric($value) && !str_contains($value, '.')) {
                                // 数字字符串（整数），转换为整数类型
                                $query->bound_values[$param] = (int)$value;
                            } else {
                                // 其他类型（浮点数、字符串等），转换为字符串
                                $query->bound_values[$param] = (string)$value;
                            }
                            $where[2] = $param;
                    };
                    if (!$skip_implode) {
                        $wheres .= '(' . implode(' ', $where) . ') ' . $logic;
                    }
            }
        }

        $wheres = rtrim($wheres, $logic);
        
        // 🔧 修复：如果主表别名不是 main_table，但 WHERE 条件中使用了 main_table，需要替换
        $mainTableAlias = $query->table_alias ?: 'main_table';
        if ($mainTableAlias !== 'main_table' && preg_match('/\bmain_table\b/i', $wheres)) {
            // 替换 WHERE 条件中的 main_table 为实际的主表别名
            // 处理带引号的情况："main_table".field 或 `main_table`.field
            $wheres = preg_replace_callback(
                '/([`"]?)main_table\1(\.)/i',
                function ($matches) use ($formatter, $mainTableAlias) {
                    return $formatter->quote($mainTableAlias) . $matches[2];
                },
                $wheres
            );
        }
        
        return $wheres;
    }

    private function convertBackticks(string $sql): string
    {
        return preg_replace_callback(
            '/`([^`]+)`/',
            static function (array $matches): string {
                $identifier = $matches[1];
                if (str_contains($identifier, '.')) {
                    $parts = explode('.', $identifier);
                    return '"' . implode('"."', $parts) . '"';
                }
                return '"' . $identifier . '"';
            },
            $sql
        );
    }
}

