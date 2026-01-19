<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Dialect;

use PDO;
use Weline\Framework\Database\Connection\Api\Sql\Query;
use Weline\Framework\Database\Exception\DbException;

/**
 * 迁移自旧版 prepareSql 的通用实现，逐步可被各驱动替换。
 */
class GenericDialectAdapter implements DialectAdapterInterface
{
    public function compile(Query $query, string $action): string
    {
        if ($query->table === '') {
            throw new DbException(__('没有指定table表名！'));
        }

        $this->reorderWhereByIndexes($query);
        $alias = $query->table_alias ? 'AS ' . $query->table_alias : '';
        $joins = $this->buildJoins($query);
        $wheres = $this->buildWheres($query);
        $order = $this->buildOrder($query);

        $sql = '';
        switch ($action) {
            case 'insert':
                $sql = $this->buildInsert($query);
                break;
            case 'delete':
                $sql = "DELETE FROM {$query->table} {$wheres} {$query->additional_sql}";
                break;
            case 'update':
                $sql = $this->buildUpdate($query, $wheres);
                break;
            case 'find':
            case 'select':
            default:
                $sql = "SELECT {$query->fields} FROM {$query->table} {$alias} {$joins} {$wheres} {$query->group_by} {$query->having} {$query->additional_sql} {$order} {$query->limit}";
                break;
        }

        $query->sql = $sql;
        
        // 如果 SQL 为空，不准备语句（例如：insert 时没有数据需要插入）
        if (!empty($sql)) {
            $query->PDOStatement = $query->getLink()->prepare($query->sql);
        } else {
            $query->PDOStatement = null;
        }
        return $query->sql;
    }

    private function reorderWhereByIndexes(Query $query): void
    {
        if (empty($query->_index_sort_keys)) {
            return;
        }
        $formatter = $query->getIdentifierFormatter();
        foreach ($query->_index_sort_keys as &$index_sort_key) {
            $index_sort_key = $formatter->normalize($index_sort_key);
        }
        $_index_sort_keys_wheres = [];
        foreach ($query->wheres as $where_key => $where) {
            $where_field = $where[0];
            if (str_contains($where_field, '.')) {
                $where_field_arr = explode('.', $where_field);
                $where_field = array_pop($where_field_arr);
            }
            $where_field = $formatter->normalize($where_field);
            if (in_array($where_field, $query->_index_sort_keys, true)) {
                $_index_sort_keys_wheres[$where_field][] = $where;
                unset($query->wheres[$where_key]);
            }
        }
        if ($_index_sort_keys_wheres) {
            foreach (array_reverse($query->_index_sort_keys) as $filed_key) {
                if (isset($_index_sort_keys_wheres[$filed_key])) {
                    array_unshift($query->wheres, ...$_index_sort_keys_wheres[$filed_key]);
                }
            }
        }
    }

    /**
     * 构建 JOIN 语句
     * 使用格式化器处理表名和条件中的标识符，确保跨数据库兼容性
     */
    protected function buildJoins(Query $query): string
    {
        $formatter = $query->getIdentifierFormatter();
        $joins = '';
        foreach ($query->joins as $join) {
            $table = $join[0];
            $condition = $join[1];
            $type = $join[2];
            
            // 使用格式化器处理表名中的标识符（移除反引号/双引号，使用正确的格式化器）
            $table = $this->formatTableNameInJoin($table, $formatter);
            
            // 使用格式化器处理条件中的标识符
            $condition = $this->formatConditionInJoin($condition, $formatter);
            
            $joins .= " {$type} JOIN {$table} ON {$condition} ";
        }
        return $joins;
    }
    
    /**
     * 格式化 JOIN 中的表名
     * 处理表名和别名，使用格式化器确保跨数据库兼容
     * 
     * 支持格式：
     * - table（简单表名）
     * - `table` 或 "table"（带引号的表名）
     * - schema.table（带 schema 的表名）
     * - `schema`.`table` 或 "schema"."table"（带引号的 schema 和表名）
     * - table `alias` 或 table "alias"（表名和别名）
     * - `table` `alias` 或 "table" "alias"（带引号的表名和别名）
     * - schema.table `alias`（带 schema 的表名和别名）
     */
    protected function formatTableNameInJoin(string $table, $formatter): string
    {
        $table = trim($table);
        
        // 分离表名和别名（通过空格分隔）
        // 匹配：表名部分 + 空格 + 别名部分（别名可能带引号）
        if (preg_match('/^(.+?)\s+([`"]?)(\w+)\2$/i', $table, $matches)) {
            $tablePart = trim($matches[1]); // 表名部分（可能包含 schema）
            $alias = $matches[3]; // 别名（已去除引号）
            
            // 格式化表名部分（可能包含 schema.table）
            $formattedTable = $this->formatQualifiedTableName($tablePart, $formatter);
            
            // 格式化别名
            $formattedAlias = $formatter->quote($alias);
            
            return "{$formattedTable} {$formattedAlias}";
        }
        
        // 没有别名，只格式化表名
        return $this->formatQualifiedTableName($table, $formatter);
    }
    
    /**
     * 格式化限定表名（可能包含 schema.table）
     * 
     * @param string $tableName 表名（可能包含 schema，可能带引号）
     * @param mixed $formatter 格式化器
     * @return string 格式化后的表名
     */
    protected function formatQualifiedTableName(string $tableName, $formatter): string
    {
        // 移除所有引号
        $tableName = trim($tableName, '`"');
        
        // 如果包含点号，说明是限定名（schema.table）
        if (str_contains($tableName, '.')) {
            $parts = explode('.', $tableName);
            // 使用 quoteQualified 格式化每个部分
            return $formatter->quoteQualified(...$parts);
        }
        
        // 简单表名
        return $formatter->quote($tableName);
    }
    
    /**
     * 格式化 JOIN 条件中的标识符
     * 解析条件字符串，将反引号/双引号标识符转换为使用格式化器的格式
     * 
     * 支持格式：
     * - `identifier` 或 "identifier"（简单标识符，带引号）
     * - `table`.`field` 或 "table"."field"（限定名，每个部分单独引号）
     * - `table.field` 或 "table.field"（限定名，整体引号）
     * - table.field（限定名，不带引号）
     * - identifier（简单标识符，不带引号）
     */
    protected function formatConditionInJoin(string $condition, $formatter): string
    {
        // 第一步：处理带引号的标识符（反引号或双引号）
        $condition = preg_replace_callback(
            '/([`"])([^`"]+)\1(?:\.([`"])([^`"]+)\3)?/',
            function ($matches) use ($formatter) {
                $firstPart = $matches[2]; // 第一部分标识符
                
                // 情况1：限定名格式 `table`.`field` 或 "table"."field"
                if (isset($matches[4]) && !empty($matches[4])) {
                    $secondPart = $matches[4];
                    return $formatter->quoteQualified($firstPart, $secondPart);
                }
                
                // 情况2：整体限定名格式 `table.field` 或 "table.field"
                if (str_contains($firstPart, '.')) {
                    $parts = explode('.', $firstPart);
                    return $formatter->quoteQualified(...$parts);
                }
                
                // 情况3：简单标识符 `identifier` 或 "identifier"
                return $formatter->quote($firstPart);
            },
            $condition
        );
        
        // 第二步：处理不带引号的限定名（如：table.field）
        // 只匹配有效的标识符格式（字母、数字、下划线），避免误匹配操作符、函数等
        // 使用负向前瞻和回顾，确保不是字符串的一部分
        $condition = preg_replace_callback(
            '/(?<![`"a-zA-Z0-9_])([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)(?![`"a-zA-Z0-9_])/',
            function ($matches) use ($formatter) {
                // 确保这是有效的标识符对，而不是其他内容
                return $formatter->quoteQualified($matches[1], $matches[2]);
            },
            $condition
        );
        
        return $condition;
    }

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
                                    $query->bound_values[$set_where_key_param] = (string)$item;
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
                                $value = $value ? '1' : '0';
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
                            // 处理布尔值：false 转换为 0，true 转换为 1
                            // 避免空字符串导致 PostgreSQL 整数类型转换错误
                            $value = $where[2];
                            if (is_bool($value)) {
                                $value = $value ? '1' : '0';
                            } else {
                                $value = (string)$value;
                            }
                            $query->bound_values[$param] = $value;
                            $where[2] = $param;
                    };
                    if (!$skip_implode) {
                        $wheres .= '(' . implode(' ', $where) . ') ' . $logic;
                    }
            }
        }

        return rtrim($wheres, $logic);
    }

    private function buildOrder(Query $query): string
    {
        $formatter = $query->getIdentifierFormatter();
        $order = '';
        foreach ($query->order as $field => $dir) {
            // 检查是否已经引用
            $isQuoted = str_contains($field, '`') || str_contains($field, '"') || str_contains($field, "'");
            if (!$isQuoted) {
                if (str_contains($field, '.')) {
                    $fields = explode('.', $field);
                    $field = $formatter->quoteQualified(...$fields);
                } else {
                    $field = $formatter->quote($field);
                }
            }
            $order .= "$field $dir,";
        }
        $order = rtrim($order, ',');
        return $order ? 'ORDER BY ' . $order : '';
    }

    protected function buildInsert(Query $query): string
    {
        $formatter = $query->getIdentifierFormatter();
        $insert_items = $query->insert['insert'] ?? [];
        $insert_or_update_items = $query->insert['i_o_u'] ?? [];
        unset($query->insert['i_o_u'], $query->insert['origin'], $query->insert['insert']);
        $update_inserts_sql = '';
        if ($insert_or_update_items) {
            $bound_filed_values = [];
            $exist_sql = "SELECT * FROM {$query->table} WHERE ";
            foreach ($insert_or_update_items as $insert_key => $insert) {
                $exist_sql .= '(';
                foreach ($query->insert_update_where_fields as $insert_update_where_field_k => $insert_update_where_field) {
                    $insert_update_where_field_key = ':' . md5('insert_update_where_' . $insert_update_where_field . '_' . $insert_update_where_field_k . '_' . $insert_key);
                    if ($insert_update_where_field == $query->identity_field and !isset($insert[$insert_update_where_field])) {
                        continue;
                    }
                    $bound_filed_values[$insert_update_where_field_key] = $insert[$insert_update_where_field];
                    $field_quoted = $formatter->quote($insert_update_where_field);
                    $exist_sql .= "{$field_quoted} = {$insert_update_where_field_key} AND ";
                }
                $exist_sql = rtrim($exist_sql, 'AND ') . ') OR ';
            }
            $exist_sql = rtrim($exist_sql, 'OR ');
            $existsQuery = $query->getLink()->prepare($exist_sql);
            $existsQuery->execute($bound_filed_values);
            $exists = $existsQuery->fetchAll(PDO::FETCH_ASSOC);
            $insert_update_where_fields_times = count($query->insert_update_where_fields);
            
            if (count($exists) > 0) {
                foreach ($exists as $exist) {
                    foreach ($insert_items as $insert_item_key => $insert_item) {
                        $hit_times = 0;
                        foreach ($query->insert_update_where_fields as $insert_update_where_field) {
                            // 🔧 修复：规范化字段名（移除引号，统一大小写）
                            $normalized_field = strtolower(trim($insert_update_where_field, '`"'));
                            // PostgreSQL 返回的字段名可能是小写的，需要匹配
                            $exist_field_value = null;
                            foreach ($exist as $exist_key => $exist_value) {
                                if (strtolower(trim($exist_key, '`"')) === $normalized_field) {
                                    $exist_field_value = $exist_value;
                                    break;
                                }
                            }
                            $insert_field_value = $insert_item[$insert_update_where_field] ?? null;
                            if ($insert_field_value == $exist_field_value && $exist_field_value !== null) {
                                $hit_times += 1;
                            }
                        }
                        if ($hit_times == $insert_update_where_fields_times) {
                            unset($insert_items[$insert_item_key]);
                        }
                    }
                    $exist_update_value_key = '';
                    foreach ($query->insert_update_where_fields as $insert_update_where_field) {
                        $normalized_field = strtolower(trim($insert_update_where_field, '`"'));
                        $exist_field_value = null;
                        foreach ($exist as $exist_key => $exist_value) {
                            if (strtolower(trim($exist_key, '`"')) === $normalized_field) {
                                $exist_field_value = $exist_value;
                                break;
                            }
                        }
                        $exist_update_value_key .= $insert_update_where_field . '_' . ($exist_field_value ?? '') . '_';
                    }
                    $exist_update_value_key = rtrim($exist_update_value_key, '_');

                    foreach ($insert_or_update_items as $insert_key => $insert) {
                        $insert_data_value_key = '';
                        foreach ($query->insert_update_where_fields as $update_field) {
                            $insert_data_value_key .= $update_field . '_' . ($insert[$update_field] ?? '') . '_';
                        }
                        $insert_data_value_key = rtrim($insert_data_value_key, '_');
                        if ($insert_data_value_key == $exist_update_value_key) {
                            unset($insert_or_update_items[$insert_key]);
                            $exist_update_where = '';
                            foreach ($query->insert_update_where_fields as $insert_update_where_field) {
                                $field_quoted = $formatter->quote($insert_update_where_field);
                                $exist_update_where .= "{$field_quoted} = " . $query->quote((string)$insert[$insert_update_where_field]) . ' AND ';
                                unset($insert[$insert_update_where_field]);
                            }
                            $exist_update_where = rtrim($exist_update_where, 'AND ');
                            if ($insert) {
                                if (!empty($query->insert_update_fields)) {
                                    foreach ($insert as $field_key => $field_value) {
                                        if (!in_array($field_key, $query->insert_update_fields, true)) {
                                            unset($insert[$field_key]);
                                        }
                                    }
                                }
                                $insert_updates['WHERE ' . $exist_update_where] = $insert;
                            }
                        }
                    }
                }
            }
            if (count($insert_or_update_items) > 0) {
                $insert_items = array_merge($insert_items, $insert_or_update_items);
            }

            if (!empty($insert_updates ?? [])) {
                $insert_updates_index = 0;
                foreach ($insert_updates as $insert_update_where => $insert_update) {
                    $insert_updates_index++;
                    $update_inserts_sql .= "UPDATE {$query->table} SET ";
                    foreach ($insert_update as $insert_update_field => $insert_update_value) {
                        $insert_bound_key = ':' . md5("{$insert_update_field}_field_{$insert_update_where}_{$insert_updates_index}");
                        $query->bound_values[$insert_bound_key] = (string)$insert_update_value;
                        $field_quoted = $formatter->quote($insert_update_field);
                        $update_inserts_sql .= "{$field_quoted} = {$insert_bound_key}, ";
                    }
                    $update_inserts_sql = rtrim($update_inserts_sql, ', ') . ' ' . $insert_update_where . '; ';
                }
            }
            // 🔧 修复：如果所有记录都已存在（insert_items 为空），但 update_inserts_sql 不为空，应该返回 UPDATE 语句
            // 否则 UPDATE 语句会被忽略，导致数据没有真正更新到数据库
            if (empty($insert_items)) {
                // 如果有 UPDATE 语句，返回它们；否则返回空字符串
                if (!empty($update_inserts_sql)) {
                    // 移除末尾的分号，因为后续可能还会拼接其他 SQL
                    $update_inserts_sql = rtrim($update_inserts_sql, '; ');
                    return $update_inserts_sql;
                }
                $query->reset();
                $query->sql = '';
                $query->PDOStatement = null;
                return '';
            }
        }

        $identity_inserts_sql = '';
        $values = '';
        $has_identify_field_insert = false;
        $has_no_identify_field_insert = false;
        foreach ($insert_items as $insert_key => $insert) {
            $insert_key += 1;
            if ($query->identity_field && empty($insert[$query->identity_field])) {
                unset($insert[$query->identity_field]);
                $insert_fields = array_keys($insert);
                $insert_fields_quoted = array_map(fn($field) => $formatter->quote($field), $insert_fields);
                $insert_fields_str = implode(',', $insert_fields_quoted);
                $identity_inserts_sql .= "INSERT INTO {$query->table} ({$insert_fields_str}) VALUES (";
                foreach ($insert as $insert_field => $insert_value) {
                    $insert_bound_key = ':' . md5("insert_{$insert_field}_field_{$insert_key}");
                    $query->bound_values[$insert_bound_key] = (string)$insert_value;
                    $identity_inserts_sql .= "$insert_bound_key , ";
                }
                $identity_inserts_sql = rtrim($identity_inserts_sql, ', ');
                $identity_inserts_sql .= '); ';
                $has_identify_field_insert = true;
            } else {
                $values .= '(';
                foreach ($insert as $insert_field => $insert_value) {
                    $insert_bound_key = ':' . md5("insert_{$insert_field}_field_{$insert_key}");
                    // 处理数组类型的值，将其转换为 JSON 字符串
                    if (is_array($insert_value)) {
                        $query->bound_values[$insert_bound_key] = json_encode($insert_value, JSON_UNESCAPED_UNICODE);
                    } elseif (is_null($insert_value)) {
                        $query->bound_values[$insert_bound_key] = null;
                    } else {
                        $query->bound_values[$insert_bound_key] = (string)$insert_value;
                    }
                    $values .= "$insert_bound_key , ";
                }
                $values = rtrim($values, ', ');
                $values .= '),';
                $has_no_identify_field_insert = true;
            }
        }
        if ($has_identify_field_insert && $has_no_identify_field_insert) {
            throw new \Exception(__('插入的数据记录中不允许同时存在有主键和无主键的情况！'));
        }
        $values = rtrim($values, ',');
        $sql = $update_inserts_sql . $identity_inserts_sql;
        if (!empty($values)) {
            $sql .= "INSERT INTO {$query->table} {$query->fields} VALUES {$values}";
        }
        return $sql;
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
                        $query->bound_values[$identity_field_column_value] = (string)$line[$column];
                        $updates .= sprintf('WHEN %s THEN %s ', $identity_field_column_key, $identity_field_column_value);
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
                    $query->bound_values[$update_key] = (string)$field_value;
                    $updates .= "{$update_field_quoted} = $update_key,";
                }
            }
        }
        if ($query->single_updates) {
            foreach ($query->single_updates as $update_field => $update_value) {
                $update_field_quoted = $formatter->quote($update_field);
                $update_key = ':' . md5($update_field);
                $query->bound_values[$update_key] = (string)$update_value;
                $updates .= "{$update_field_quoted}=$update_key,";
            }
        }
        if (!$updates) {
            throw new \Exception(__('无法解析更新数据！多记录更新数据：%{1}，单记录更新数据：%{2}', [var_export($query->updates, true), var_export($query->single_updates, true)]));
        }
        $updates = rtrim($updates, ',');

        return "UPDATE {$query->table} SET {$updates} {$wheres} {$query->additional_sql} ";
    }
}

