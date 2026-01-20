<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

use PDO;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\SqlTrait;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Manager\ObjectManager;

abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\Query
{
    use SqlTrait;

    // 联合主键 设置联合主键可以提升查询效率
    public array $_unit_primary_keys = [];
    // 联合索引最左原则，提升查询效率
    public array $_index_sort_keys = [];

    public string $identity_field = 'id';
    public string $table = '';
    public string $table_alias = 'main_table';
    public array $insert = [];
    public string $exist_update_sql = '';
    public array $joins = [];
    public string $fields = '*';
    public array $single_updates = [];
    public array $updates = [];
    public array $wheres = [];
    public array $bound_values = [];
    public string $limit = '';
    public array $order = [];
    public string $group_by = '';
    public string $having = '';

    public ?\PDOStatement $PDOStatement = null;
    public string $sql = '';
    public string $additional_sql = '';

    public string $fetch_type = '';

    public array $pagination = ['page' => 1, 'pageSize' => 20, 'totalSize' => 0, 'lastPage' => 0];

    public string $backup_file = '';

    /**
     * 获取数据库连接
     */
    abstract public function getLink(): PDO;

    public function identity(string $field): QueryInterface
    {
        $this->identity_field = $field;
        return $this;
    }
    
    /**
     * 🔧 重写 query 方法，在执行前转换 MySQL 特有语法
     * 对于直接执行的 SQL（没有 this->sql 的情况下），需要标准化处理
     */
    public function query(string $sql): QueryInterface
    {
        // 🔧 修复：统一规范化 SQL（反引号、数据库名、MySQL 函数转换）
        $sql = $this->normalizeSql($sql);
        
        // 调用父类方法，但使用 preparePgsql 来准备语句
        $this->reset();
        $this->sql = $sql;
        $this->fetch_type = __FUNCTION__;
        $this->PDOStatement = $this->preparePgsql($sql);
        return $this;
    }


    /**
     * 🔧 重写：完全按照 PostgreSQL 语法处理插入
     */
    public function insert(array $data, array|string $update_where_fields = [], string $update_fields = '', bool $ignore_primary_key = false): QueryInterface
    {
        if (empty($data)) {
            throw new DbException('插入数据不能为空！');
        }
        
        // 处理更新字段
        if ($update_fields) {
            if (is_string($update_fields)) {
                $this->insert_update_fields = explode(',', $update_fields);
            } else {
                $this->insert_update_fields = $update_fields;
            }
        }

        // 处理更新依据条件
        if (is_string($update_where_fields) && $update_where_fields) {
            $update_where_fields = explode(',', $update_where_fields);
        }
        if (is_array($update_where_fields)) {
            $this->insert_update_where_fields = $update_where_fields;
        }
        
        // 如果没有忽略主键，则需要添加主键
        if (empty($this->insert_update_where_fields) && !$ignore_primary_key) {
            if (!in_array($this->identity_field, $this->insert_update_where_fields)) {
                $this->insert_update_where_fields[] = $this->identity_field;
            }
            if (empty($this->insert_update_where_fields)) {
                foreach ($this->_unit_primary_keys as $unit_primary_key) {
                    if (!in_array($unit_primary_key, $this->insert_update_where_fields)) {
                        $this->insert_update_where_fields[] = $unit_primary_key;
                    }
                }
            }
            $this->insert_update_where_fields = array_reverse($this->insert_update_where_fields);
        }
        
        // 处理 ON CONFLICT 语法
        if (!empty($this->insert_update_fields) && !empty($this->insert_update_where_fields)) {
            $this->exist_update_sql = 'DO UPDATE SET ';
            foreach ($this->insert_update_fields as $field) {
                $field = trim(str_replace(['`', '"'], '', $field));
                $this->exist_update_sql .= "\"{$field}\"=EXCLUDED.\"{$field}\",";
            }
            $this->exist_update_sql = trim($this->exist_update_sql, ',');
        }
        
        // 插入数据
        if (is_string(array_key_first($data))) {
            $this->insert['origin'][] = $data;
        } else {
            sort($data);
            $this->insert['origin'] = $data;
        }

        // 计算是否存在多条语句
        if (count($this->insert['origin']) > 1) {
            $this->batch = true;
        }

        // 处理插入数据分类
        if (count($this->insert)) {
            $insert_have_not_identity_fields = $this->insert_update_where_fields;
            foreach ($insert_have_not_identity_fields as $insert_have_not_identity_field_key => $insert_have_not_identity_field) {
                if ($insert_have_not_identity_field == $this->identity_field) {
                    unset($insert_have_not_identity_fields[$insert_have_not_identity_field_key]);
                    break;
                }
            }
            
            $insert_need_fields = array_merge($this->_unit_primary_keys, $this->insert_update_where_fields);
            $this->insert_need_fields = $insert_need_fields;
            
            $first_insert_item = $this->insert['origin'][0] ?? [];
            $first_insert_item_keys = array_keys($first_insert_item);
            
            if (!isset($first_insert_item[$this->identity_field]) || is_numeric($first_insert_item[$this->identity_field])) {
                foreach ($insert_need_fields as $insert_need_field_key => $insert_need_field) {
                    if ($insert_need_field == $this->identity_field) {
                        unset($insert_need_fields[$insert_need_field_key]);
                        break;
                    }
                }
            }
            
            foreach ($first_insert_item_keys as $first_insert_item_key_index => $first_insert_item_key) {
                $this->insert_need_fields[$first_insert_item_key_index] = $first_insert_item_key;
            }
            $this->insert_need_fields = array_unique($this->insert_need_fields);
            
            if (count($this->insert_need_fields) != count($first_insert_item_keys)) {
                throw new Exception(__('插入数据和更新依据字段不匹配，请检查! 所需字段：%{1}，实际字段: %{2}', [implode(',', $this->insert_need_fields), implode(',', $first_insert_item_keys)]));
            }
            
            foreach ($first_insert_item as $f => $fv) {
                if (!in_array($f, $insert_need_fields)) {
                    $insert_need_fields[] = $f;
                }
            }
            
            // 区分更新或者插入
            foreach ($this->insert['origin'] as $item) {
                $item_fields = array_keys($item);
                foreach ($insert_need_fields as $insert_need_field) {
                    if (!in_array($insert_need_field, $item_fields)) {
                        throw new Exception(__('插入数据和更新依据字段不匹配，请检查! 所需字段：%{1}，实际字段: %{2}', [implode(',', $insert_need_fields), implode(',', $item_fields)]));
                    }
                }
                
                if (!empty($this->insert_update_fields)) {
                    foreach ($this->insert_update_fields as $insert_update_field) {
                        if (!in_array($insert_update_field, $item_fields)) {
                            throw new Exception(__('检测打算要更新依据字段不匹配，请检查! 打算更新字段：%{1}，实际字段: %{2}', [implode(',', $this->insert_update_fields), implode(',', $item_fields)]));
                        }
                    }
                }
                
                if (!$insert_have_not_identity_fields) {
                    if (empty($item[$this->identity_field])) {
                        $this->insert['insert'][] = $item;
                        continue;
                    }
                }
                $this->insert['i_o_u'][] = $item;
                if (empty($this->insert_need_fields)) {
                    $this->insert_need_fields = $item_fields;
                }
            }
        }

        $this->fetch_type = __FUNCTION__;
        $this->prepareSql(__FUNCTION__);
        return $this;
    }

    public function update(array|string $field = '', int|string $value_or_condition_field = 'id'): QueryInterface
    {
        if ($field) {
            # 单条记录更新
            if (is_string($field)) {
                $this->single_updates[$field] = $value_or_condition_field;
            } else {
                // 设置数据更新依赖条件主键
                if ($this->identity_field !== $value_or_condition_field) {
                    $this->identity_field = $value_or_condition_field;
                }
                if (is_string(array_key_first($field))) {
                    $this->updates[] = $field;
                } else {
                    $this->updates = $field;
                }
            }
        }
        $this->fetch_type = __FUNCTION__;
        $this->prepareSql(__FUNCTION__);
        return $this;
    }

    public function alias(string $table_alias_name): QueryInterface
    {
        $this->table_alias = $table_alias_name;
        if ($this->fields === '*' || $this->fields === $this->table_alias . '.*' || 'main_table.*' === $this->fields) {
            $this->fields = $this->table_alias . '.*';
        }
        return $this;
    }

    /**
     * 🔧 重写：完全按照 PostgreSQL 语法处理 JOIN
     */
    public function join(string $table, string $condition, string $type = 'left'): QueryInterface
    {
        if (1 === count(func_get_args())) {
            $type = 'inner';
        }
        // 规范化表名和条件（使用双引号）
        $table = trim($table);
        $condition = trim($condition);
        
        // 确保表名和条件中的标识符使用双引号
        $table = str_replace('`', '"', $table);
        $condition = str_replace('`', '"', $condition);
        
        $this->joins[] = [$table, $condition, $type];
        return $this;
    }

    public function fields(string|array $fields): QueryInterface
    {
        // 处理数组参数（兼容性处理，虽然接口定义是string，但实际可能传入数组）
        if (is_array($fields)) {
            $fieldsStringParts = [];
            foreach ($fields as $alias => $expression) {
                if (is_string($alias) && is_string($expression)) {
                    // 关联数组格式：['alias' => 'expression'] -> 'expression AS alias'
                    $fieldsStringParts[] = $expression . ' AS ' . $alias;
                } else {
                    // 普通数组格式：['field1', 'field2'] -> 'field1,field2'
                    $fieldsStringParts[] = is_string($expression) ? $expression : (string)$expression;
                }
            }
            $fields = implode(',', $fieldsStringParts);
        }
        
        if ($this->fields === '*' || $this->fields === $this->table_alias . '.*' || 'main_table.*' === $this->fields) {
            $this->fields = $fields;
        } else {
            $this->fields = $fields . ',' . $this->fields;
            $fields = explode(',', $this->fields);
            $fields = array_unique($fields);
            $this->fields = implode(',', $fields);
        }
        return $this;
    }

    public function limit($size, $offset = 0): QueryInterface
    {
        $this->limit = " LIMIT $size OFFSET $offset";
        return $this;
    }

    public function page(int $page = 1, int $pageSize = 20): QueryInterface
    {
        $offset = 0;
        if (1 < $page) {
            $offset = $pageSize * ($page - 1);
        }
        $this->limit = " LIMIT $pageSize OFFSET $offset";
        $this->pagination['page'] = $page;
        return $this;
    }

    /**
     * 🔧 重写：完全按照 PostgreSQL 语法处理 ORDER BY
     */
    public function order(string $field = '', string $sort = 'DESC'): QueryInterface
    {
        if (empty($field)) {
            $field = $this->identity_field;
        }
        // PostgreSQL 使用双引号
        if (!str_contains($field, '"') && !str_contains($field, '`')) {
            $field = self::parserFiled($field);
        }
        // 将反引号替换为双引号（确保 $field 是字符串）
        if (is_string($field)) {
            $field = str_replace('`', '"', $field);
        } else {
            $field = (string)$field;
        }
        $this->order[$field] = $sort;
        return $this;
    }

    /**
     * 🔧 重写：完全按照 PostgreSQL 语法处理 GROUP BY
     */
    public function group(string $fields): QueryInterface
    {
        // 规范化字段列表（使用双引号）
        $fieldList = array_map('trim', explode(',', $fields));
        $formattedFields = [];
        foreach ($fieldList as $field) {
            if (!str_contains($field, '"') && !str_contains($field, '`')) {
                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $field = '"' . implode('"."', $parts) . '"';
                } else {
                    $field = '"' . $field . '"';
                }
            } else {
                if (is_string($field)) {
                    $field = str_replace('`', '"', $field);
                } else {
                    $field = (string)$field;
                }
            }
            $formattedFields[] = $field;
        }
        $this->group_by = 'GROUP BY ' . implode(', ', $formattedFields);
        return $this;
    }

    /**
     * 🔧 重写：完全按照 PostgreSQL 语法处理 HAVING
     */
    public function having(string $having): QueryInterface
    {
        // 规范化 HAVING 子句中的字段名
        $this->having = $this->normalizeSql($having);
        return $this;
    }

    /**
     * 🔧 重写：完全按照 PostgreSQL 语法处理 where 条件
     * 不使用父类方法，完全重写
     */
    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): QueryInterface
    {
        $where_logic = trim(strtoupper($where_logic));
        $condition = trim(strtoupper($condition));
        $array_where_logic_type = trim(strtoupper($array_where_logic_type));
        
        if (is_array($field)) {
            foreach ($field as $f_key => $where_array) {
                // 🔧 修复：确保 $f_key 是字符串，然后使用 PostgreSQL 双引号格式化字段名
                if (!is_string($f_key) && !is_int($f_key)) {
                    $f_key = (string)$f_key;
                }
                $f_key = self::parserFiled($f_key);
                // 将反引号替换为双引号（确保 $f_key 是字符串）
                if (is_string($f_key)) {
                    $f_key = str_replace('`', '"', $f_key);
                } else {
                    $f_key = (string)$f_key;
                }
                
                if (!is_array($where_array)) {
                    $value = $where_array;
                    $where_array = [];
                    $where_array[0] = $f_key;
                    $where_array[1] = '=';
                    $where_array[2] = $value;
                    $where_array[3] = $array_where_logic_type;
                } elseif (2 === count($where_array)) {
                    // 处理两个元素数组
                    $where_array[2] = $where_array[1];
                    $where_array[1] = '=';
                }

                // 检测条件数组
                $this->checkWhereArray($where_array, $f_key);
                $this->checkConditionString($where_array);
                
                // 确保字段名使用双引号
                if (isset($where_array[0])) {
                    if (is_string($where_array[0])) {
                        $where_array[0] = str_replace('`', '"', $where_array[0]);
                    } else {
                        $where_array[0] = (string)$where_array[0];
                    }
                }
                
                $this->wheres[] = $where_array;
            }
        } else {
            // 🔧 修复：使用 PostgreSQL 双引号格式化字段名
            $field = self::parserFiled($field);
            // 确保 $field 是字符串后再替换
            if (is_string($field)) {
                $field = str_replace('`', '"', $field);
            } else {
                $field = (string)$field;
            }
            
            if (is_array($value)) {
                if ($condition === 'IN' || $condition === 'NOT IN') {
                    if (empty($value)) {
                        throw new Exception(__('IN 条件无法匹配空值数组。数组值：[]'));
                    }
                    $where_array = [$field, $condition, $value, $where_logic];
                    $this->checkWhereArray($where_array, 0);
                    $this->checkConditionString($where_array);
                    $this->wheres[] = $where_array;
                } else {
                    $last_key = array_key_last($value);
                    foreach ($value as $kv => $item) {
                        if ($last_key === $kv) {
                            $array_where_logic_type = $where_logic;
                        }
                        $where_array = [$field, $condition, $item, $array_where_logic_type];
                        $this->checkWhereArray($where_array, 0);
                        $this->checkConditionString($where_array);
                        $this->wheres[] = $where_array;
                    }
                }
            } else {
                $where_array = [$field, $condition, $value, $where_logic];
                $this->checkWhereArray($where_array, 0);
                $this->checkConditionString($where_array);
                $this->wheres[] = $where_array;
            }
        }
        return $this;
    }

    public function find(string $find_fields = ''): QueryInterface
    {
        if ($find_fields) {
            $this->find_fields = $find_fields;
            $this->fields($find_fields);
        }
        $this->limit(1, 0);
        $this->fetch_type = __FUNCTION__;
        $this->prepareSql(__FUNCTION__);
        return $this;
    }

    public function select(string $fields = ''): QueryInterface
    {
        if ($fields) {
            $this->fields($fields);
        }
        $this->fetch_type = __FUNCTION__;
        $this->prepareSql(__FUNCTION__);
        return $this;
    }

    public function delete(): QueryInterface
    {
        $this->fetch_type = __FUNCTION__;
        $this->prepareSql(__FUNCTION__);
        return $this;
    }

    /**
     * 🔧 重写：附加的 SQL 也要标准化处理
     */
    public function additional(string $additional_sql): QueryInterface
    {
        // 🔧 修复：对附加的 SQL 进行标准化处理
        $this->additional_sql = $this->normalizeSql($additional_sql);
        return $this;
    }

    /**
     * 🔧 重写：完全按照 PostgreSQL 语法构建 SQL
     * 不使用父类的 dialectAdapter，直接构建 PostgreSQL 兼容的 SQL
     * 使用 private 确保覆盖 trait 中的 private prepareSql 方法
     */
    private function prepareSql(string $action): void
    {
        if ($this->table === '') {
            throw new DbException(__('没有指定table表名！'));
        }

        // 重新排序 where 条件（按索引优化）
        $this->reorderWhereByIndexes();

        // 格式化表名（使用 PostgreSQL 双引号）
        $table = $this->formatTableNameForPgsql($this->table);
        $alias = $this->table_alias ? 'AS "' . $this->table_alias . '"' : '';

        // 构建各个 SQL 部分
        $joins = $this->buildJoinsForPgsql();
        $wheres = $this->buildWheresForPgsql();
        $order = $this->buildOrderForPgsql();
        $groupBy = $this->group_by ? 'GROUP BY ' . $this->normalizeSql($this->group_by) : '';
        $having = $this->having ? 'HAVING ' . $this->normalizeSql($this->having) : '';

        // 根据操作类型构建 SQL
        switch ($action) {
            case 'insert':
                $this->sql = $this->buildInsertForPgsql($table);
                break;
            case 'delete':
                // 🔧 修复：additional_sql 已经在 additional() 方法中标准化了
                $this->sql = "DELETE FROM {$table} {$wheres} {$this->additional_sql}";
                break;
            case 'update':
                $this->sql = $this->buildUpdateForPgsql($table, $wheres);
                break;
            case 'find':
            case 'select':
            default:
                // 格式化字段列表
                $fields = $this->formatFieldsForPgsql($this->fields);
                $this->sql = "SELECT {$fields} FROM {$table} {$alias} {$joins} {$wheres} {$groupBy} {$having} {$this->additional_sql} {$order} {$this->limit}";
                break;
        }

        // 规范化 SQL（转换反引号、MySQL 函数等）
        $this->sql = $this->normalizeSql($this->sql);
        
        // 🔧 修复：在执行前验证并修复 WHERE 子句中的语法错误
        // 移除末尾的 " AND)" 或 " OR)" 这种语法错误
        $this->sql = preg_replace('/\s+(AND|OR)\s*\)\s*(LIMIT|ORDER|GROUP|HAVING|$)/i', ') $2', $this->sql);
        // 移除末尾的 " AND" 或 " OR"（不在括号内的情况）
        $this->sql = preg_replace('/\s+(AND|OR)(\s*)(LIMIT|ORDER|GROUP|HAVING|$)/i', ' $3', $this->sql);
        // 清理多余的空格
        $this->sql = preg_replace('/\s+/', ' ', $this->sql);
        $this->sql = trim($this->sql);

        // 如果 SQL 不为空，准备语句
        if (!empty($this->sql)) {
            $this->PDOStatement = $this->preparePgsql($this->sql);
        } else {
            $this->PDOStatement = null;
        }
    }

    /**
     * 重新排序 where 条件（按索引优化）
     */
    protected function reorderWhereByIndexes(): void
    {
        if (empty($this->_index_sort_keys)) {
            return;
        }
        foreach ($this->_index_sort_keys as &$index_sort_key) {
            $index_sort_key = $this->normalizeFieldName($index_sort_key);
        }
        $_index_sort_keys_wheres = [];
        foreach ($this->wheres as $where_key => $where) {
            $where_field = $where[0];
            if (str_contains($where_field, '.')) {
                $where_field_arr = explode('.', $where_field);
                $where_field = array_pop($where_field_arr);
            }
            $where_field = $this->normalizeFieldName($where_field);
            if (in_array($where_field, $this->_index_sort_keys, true)) {
                $_index_sort_keys_wheres[$where_field][] = $where;
                unset($this->wheres[$where_key]);
            }
        }
        if ($_index_sort_keys_wheres) {
            foreach (array_reverse($this->_index_sort_keys) as $filed_key) {
                if (isset($_index_sort_keys_wheres[$filed_key])) {
                    array_unshift($this->wheres, ...$_index_sort_keys_wheres[$filed_key]);
                }
            }
        }
    }

    /**
     * 规范化字段名（移除引号，统一格式）
     */
    protected function normalizeFieldName(string $field): string
    {
        return strtolower(trim($field, '`"'));
    }

    /**
     * 格式化表名（PostgreSQL 使用双引号）
     */
    protected function formatTableNameForPgsql(string $table): string
    {
        // 🔧 修复：检查表名是否已经格式化（包含双引号和点号）
        // 如果已经是 "schema"."table" 格式，直接返回，但需要修复可能的双引号重复问题
        if (preg_match('/^"([^"]+)"\."([^"]+)"$/', $table, $matches)) {
            // 已经是正确格式，直接返回
            return $table;
        }
        
        // 🔧 修复：处理可能已经部分格式化的表名（如 "public"."."table"）
        // 先修复 "schema"."."table" 格式（两个连续的双引号，中间有点）
        $table = preg_replace('/"([^"]+)"\."\."([^"]+)"/', '"$1"."$2"', $table);
        // 修复连续的双引号："." -> "."
        $table = preg_replace('/"\."\."/', '".', $table);
        
        // 如果修复后已经是正确格式，直接返回
        if (preg_match('/^"([^"]+)"\."([^"]+)"$/', $table)) {
            return $table;
        }
        
        // 移除所有引号，重新处理
        $table = trim($table, '`"');
        
        // 🔧 修复：如果表名为空，抛出异常
        if (empty($table)) {
            throw new DbException(__('表名不能为空'));
        }
        
        // 如果包含点号，说明是限定名（schema.table）
        if (str_contains($table, '.')) {
            $parts = explode('.', $table);
            // 🔧 修复：过滤空的部分，并去除每个部分的前后空格
            $parts = array_map('trim', $parts);
            $parts = array_filter($parts, fn($part) => !empty($part));
            $parts = array_values($parts); // 重新索引数组
            
            if (empty($parts)) {
                throw new DbException(__('表名格式错误：%{1}', [$table]));
            }
            
            // 处理数据库名 -> schema 转换
            $dbName = $this->db_name ?? 'public';
            if (count($parts) >= 2 && $parts[0] === $dbName) {
                $parts[0] = 'public';
            }
            
            // 如果只有一个部分，直接返回
            if (count($parts) === 1) {
                return '"' . $parts[0] . '"';
            }
            
            // 🔧 修复：确保所有部分都不为空后再拼接
            $formattedParts = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if (!empty($part)) {
                    $formattedParts[] = $part;
                }
            }
            
            if (empty($formattedParts)) {
                throw new DbException(__('表名格式错误：%{1}', [$table]));
            }
            
            if (count($formattedParts) === 1) {
                return '"' . $formattedParts[0] . '"';
            }
            
            return '"' . implode('"."', $formattedParts) . '"';
        }
        
        return '"' . $table . '"';
    }

    /**
     * 格式化字段列表（PostgreSQL 使用双引号）
     */
    protected function formatFieldsForPgsql(string $fields): string
    {
        if ($fields === '*' || empty($fields)) {
            return '*';
        }
        
        // 分割字段列表
        $fieldList = array_map('trim', explode(',', $fields));
        $formattedFields = [];
        
        foreach ($fieldList as $field) {
            // 如果字段包含 AS 或 as，处理别名
            if (preg_match('/^(.+?)\s+(AS|as)\s+(.+)$/i', $field, $matches)) {
                $fieldExpr = trim($matches[1]);
                $alias = trim($matches[3], '`"');
                // 格式化字段表达式
                $fieldExpr = $this->formatFieldExpression($fieldExpr);
                $formattedFields[] = "{$fieldExpr} AS \"{$alias}\"";
            } else {
                // 格式化字段表达式
                $formattedFields[] = $this->formatFieldExpression($field);
            }
        }
        
        return implode(', ', $formattedFields);
    }

    /**
     * 格式化字段表达式（处理 table.field 格式）
     * 🔧 修复：PostgreSQL 支持 "alias".* 但不支持 "alias"."*"
     */
    protected function formatFieldExpression(string $field): string
    {
        $field = trim($field);
        
        // 🔧 修复：特殊处理 alias.* 的情况（PostgreSQL 语法）
        // PostgreSQL 支持 alias.* 或 "alias".*，但不支持 "alias"."*"
        if (preg_match('/^([^.]*?)\.\*$/', $field, $matches)) {
            $alias = trim($matches[1], '`"');
            // 如果别名不为空，格式化别名并保留 .*
            if (!empty($alias)) {
                // 检查别名是否包含点号（可能是 schema.table 格式）
                if (str_contains($alias, '.')) {
                    $aliasParts = explode('.', $alias);
                    $dbName = $this->db_name ?? 'public';
                    if (count($aliasParts) >= 2 && $aliasParts[0] === $dbName) {
                        $aliasParts[0] = 'public';
                    }
                    return '"' . implode('"."', $aliasParts) . '".*';
                }
                return '"' . $alias . '".*';
            }
            // 如果别名为空，返回 *
            return '*';
        }
        
        // 如果已经包含引号，先移除
        $field = trim($field, '`"');
        
        // 如果包含点号，说明是限定名（table.field 格式）
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            // 处理数据库名 -> schema 转换
            $dbName = $this->db_name ?? 'public';
            if (count($parts) >= 2 && $parts[0] === $dbName) {
                $parts[0] = 'public';
            }
            return '"' . implode('"."', $parts) . '"';
        }
        
        return '"' . $field . '"';
    }

    /**
     * 构建 JOIN 语句（PostgreSQL 语法）
     */
    protected function buildJoinsForPgsql(): string
    {
        if (empty($this->joins)) {
            return '';
        }
        
        $joins = '';
        foreach ($this->joins as $join) {
            $table = $join[0];
            $condition = $join[1];
            $type = strtoupper($join[2] ?? 'LEFT');
            
            // 格式化表名
            $table = $this->formatTableNameForPgsql($table);
            
            // 格式化条件（处理标识符）
            $condition = $this->formatJoinCondition($condition);
            
            $joins .= " {$type} JOIN {$table} ON {$condition} ";
        }
        
        return $joins;
    }

    /**
     * 格式化 JOIN 条件中的标识符
     */
    protected function formatJoinCondition(string $condition): string
    {
        // 处理带引号的标识符
        $condition = preg_replace_callback(
            '/([`"])([^`"]+)\1(?:\.([`"])([^`"]+)\3)?/',
            function ($matches) {
                $firstPart = $matches[2];
                
                // 限定名格式 `table`.`field` 或 "table"."field"
                if (isset($matches[4]) && !empty($matches[4])) {
                    $secondPart = $matches[4];
                    return '"' . $firstPart . '"."' . $secondPart . '"';
                }
                
                // 整体限定名格式 `table.field` 或 "table.field"
                if (str_contains($firstPart, '.')) {
                    $parts = explode('.', $firstPart);
                    return '"' . implode('"."', $parts) . '"';
                }
                
                // 简单标识符
                return '"' . $firstPart . '"';
            },
            $condition
        );
        
        // 处理不带引号的限定名（如：table.field）
        $condition = preg_replace_callback(
            '/(?<![`"a-zA-Z0-9_])([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)(?![`"a-zA-Z0-9_])/',
            function ($matches) {
                return '"' . $matches[1] . '"."' . $matches[2] . '"';
            },
            $condition
        );
        
        return $condition;
    }

    /**
     * 构建 WHERE 语句（PostgreSQL 语法）
     */
    protected function buildWheresForPgsql(): string
    {
        if (empty($this->wheres)) {
            return '';
        }
        
        $wheres = ' WHERE ';
        $logic = 'AND ';
        $whereCount = count($this->wheres);
        $currentIndex = 0;
        
        foreach ($this->wheres as $key => $where) {
            $currentIndex++;
            $isLast = ($currentIndex === $whereCount);
            
            // 格式化字段名
            $field = $where[0];
            if (!str_contains($field, '"') && !str_contains($field, '`')) {
                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $field = '"' . implode('"."', $parts) . '"';
                } else {
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                        $field = '"' . $field . '"';
                    }
                }
            } else {
                // 移除反引号，使用双引号（确保 $field 是字符串）
                if (is_string($field)) {
                    $field = str_replace('`', '"', $field);
                } else {
                    $field = (string)$field;
                }
            }
            
            $key += 1;
            // 🔧 修复：获取当前条件的逻辑连接符，如果是最后一个条件则不使用
            // 先判断是否是最后一个条件，如果是最后一个，直接设置为空字符串
            if ($isLast) {
                $currentLogic = '';
            } else {
                // 不是最后一个条件，才需要设置逻辑连接符
                $currentLogic = 'AND ';
                if (isset($where[3])) {
                    $currentLogic = strtoupper(trim($where[3])) . ' ';
                } else {
                    $currentLogic = $logic;
                }
            }
            
            switch (count($where)) {
                case 1:
                    // 🔧 修复：只有一个参数时，是 SQL 字符串，需要标准化处理
                    // $where[0] 是 SQL 字符串，需要标准化
                    $sqlCondition = $this->normalizeSql($where[0]);
                    $wheres .= "({$sqlCondition})";
                    if (!$isLast) {
                        $wheres .= ' ' . $currentLogic;
                    }
                    break;
                default:
                    if ($where[2] === null) {
                        $wheres .= '(' . $field . ')';
                        if (!$isLast) {
                            $wheres .= ' ' . $currentLogic;
                        }
                        break;
                    }
                    
                    // 规范化字段名用于生成参数名
                    // 🔧 修复：PostgreSQL 参数名必须是字母数字下划线，不能包含特殊字符
                    $normalized_field = $this->normalizeFieldName($field);
                    // 移除所有引号和特殊字符，只保留字母数字下划线
                    $normalized_field = preg_replace('/[^a-zA-Z0-9_]/', '_', $normalized_field);
                    // 确保参数名以字母或下划线开头
                    if (preg_match('/^[0-9]/', $normalized_field)) {
                        $normalized_field = 'p' . $normalized_field;
                    }
                    $param = ':' . $normalized_field . '_' . $key;
                    
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
                                    // 🔧 修复：确保参数名符合 PostgreSQL 要求（只包含字母数字下划线）
                                    $in_where_key_clean = is_string($in_where_key) ? preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$in_where_key) : (string)$in_where_key;
                                    $where_condition_clean = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($where[1]));
                                    $set_where_key_param = $param . '_' . $in_where_key_clean . '_' . $where_condition_clean;
                                    // 确保参数名以字母或下划线开头
                                    if (preg_match('/^:[0-9]/', $set_where_key_param)) {
                                        $set_where_key_param = ':p' . substr($set_where_key_param, 1);
                                    }
                                    $this->bound_values[$set_where_key_param] = (string)$item;
                                    $set_where .= $set_where_key_param . ',';
                                }
                                $where[2] = rtrim($set_where, ',') . ')';
                            }
                            break;
                        case 'like':
                        case 'not like':
                            $value = $where[2];
                            if (is_bool($value)) {
                                $value = $value ? '1' : '0';
                            } else {
                                $value = (string)$value;
                            }
                            $this->bound_values[$param] = $value;
                            $wheres .= '(' . $field . ' ' . strtoupper($where[1]) . ' ' . $param . ')';
                            if (!$isLast) {
                                $wheres .= ' ' . $currentLogic;
                            }
                            $skip_implode = true;
                            break;
                        default:
                            $value = $where[2];
                            if (is_bool($value)) {
                                $value = $value ? '1' : '0';
                            } else {
                                $value = (string)$value;
                            }
                            $this->bound_values[$param] = $value;
                            $where[2] = $param;
                    }
                    
                    if (!$skip_implode) {
                        $wheres .= '(' . implode(' ', $where) . ')';
                        // 🔧 修复：只有在不是最后一个条件时才添加逻辑连接符
                        // 双重检查：确保 $isLast 为 false 且 $currentLogic 不为空
                        if (!$isLast) {
                            // 再次确认 $currentLogic 不为空（防止被意外设置为空）
                            if (!empty($currentLogic)) {
                                $wheres .= ' ' . $currentLogic;
                            }
                        }
                    }
            }
        }
        
        // 🔧 修复：移除末尾的空格和逻辑连接符（多重保险）
        $wheres = trim($wheres);
        
        // 方法1：移除末尾的 " AND)" 或 " OR)"（带右括号的情况）
        $wheres = preg_replace('/\s+(AND|OR)\s*\)\s*$/i', ')', $wheres);
        
        // 方法2：移除末尾的 " AND" 或 " OR"（不带括号的情况）
        $wheres = preg_replace('/\s+(AND|OR)(\s*)$/i', '', $wheres);
        
        // 方法3：移除括号内的 " AND)" 或 " OR)"（如 "taglib_id = :taglib_id1 AND)"）
        $wheres = preg_replace('/\s+(AND|OR)\s*\)/i', ')', $wheres);
        
        // 方法4：再次清理，确保没有残留
        $wheres = rtrim($wheres);
        
        // 方法5：如果末尾仍然有 AND 或 OR（可能紧跟在括号后），循环移除
        while (preg_match('/\s+(AND|OR)(\s*)$/i', $wheres)) {
            $wheres = preg_replace('/\s+(AND|OR)(\s*)$/i', '', $wheres);
            $wheres = rtrim($wheres);
        }
        
        // 方法5：最后检查，如果末尾是 " AND)" 或 " OR)"，移除逻辑连接符
        $wheres = preg_replace('/\s+(AND|OR)\s*\)\s*$/i', ')', $wheres);
        
        return $wheres;
    }

    /**
     * 构建 ORDER BY 语句（PostgreSQL 语法）
     */
    protected function buildOrderForPgsql(): string
    {
        if (empty($this->order)) {
            return '';
        }
        
        $order = '';
        foreach ($this->order as $field => $dir) {
            // 格式化字段名
            if (!str_contains($field, '"') && !str_contains($field, '`')) {
                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $field = '"' . implode('"."', $parts) . '"';
                } else {
                    $field = '"' . $field . '"';
                }
            } else {
                // 移除反引号，使用双引号（确保 $field 是字符串）
                if (is_string($field)) {
                    $field = str_replace('`', '"', $field);
                } else {
                    $field = (string)$field;
                }
            }
            $order .= "{$field} {$dir},";
        }
        
        $order = rtrim($order, ',');
        return $order ? 'ORDER BY ' . $order : '';
    }

    /**
     * 构建 INSERT 语句（PostgreSQL 语法，支持批量插入和 ON CONFLICT）
     */
    protected function buildInsertForPgsql(string $table): string
    {
        // 处理 insert 数据
        $insert_items = $this->insert['insert'] ?? [];
        $insert_or_update_items = $this->insert['i_o_u'] ?? [];
        unset($this->insert['i_o_u'], $this->insert['origin'], $this->insert['insert']);
        
        $update_inserts_sql = '';
        
        // 处理 insert_or_update 逻辑（需要先查询是否存在）
        if ($insert_or_update_items) {
            // PostgreSQL 使用 ON CONFLICT 语法，这里先处理需要更新的记录
            // 对于需要更新的记录，使用单独的 UPDATE 语句
            // 对于需要插入的记录，使用 INSERT ... ON CONFLICT
            // 这里简化处理：所有 i_o_u 记录都使用 INSERT ... ON CONFLICT
        }
        
        // 构建批量插入 SQL
        $identity_inserts_sql = '';
        $values = '';
        $has_identify_field_insert = false;
        $has_no_identify_field_insert = false;
        
        // 合并所有插入项
        $all_insert_items = array_merge($insert_items, $insert_or_update_items);
        
        foreach ($all_insert_items as $insert_key => $insert) {
            $insert_key += 1;
            
            if ($this->identity_field && empty($insert[$this->identity_field])) {
                unset($insert[$this->identity_field]);
                $insert_fields = array_keys($insert);
                $insert_fields_quoted = array_map(fn($field) => '"' . $field . '"', $insert_fields);
                $insert_fields_str = implode(',', $insert_fields_quoted);
                $identity_inserts_sql .= "INSERT INTO {$table} ({$insert_fields_str}) VALUES (";
                foreach ($insert as $insert_field => $insert_value) {
                    $insert_bound_key = ':' . md5("insert_{$insert_field}_field_{$insert_key}");
                    $this->bound_values[$insert_bound_key] = (string)$insert_value;
                    $identity_inserts_sql .= "$insert_bound_key , ";
                }
                $identity_inserts_sql = rtrim($identity_inserts_sql, ', ');
                $identity_inserts_sql .= '); ';
                $has_identify_field_insert = true;
            } else {
                $values .= '(';
                foreach ($insert as $insert_field => $insert_value) {
                    $insert_bound_key = ':' . md5("insert_{$insert_field}_field_{$insert_key}");
                    if (is_array($insert_value)) {
                        $this->bound_values[$insert_bound_key] = json_encode($insert_value, JSON_UNESCAPED_UNICODE);
                    } elseif (is_null($insert_value)) {
                        $this->bound_values[$insert_bound_key] = null;
                    } else {
                        $this->bound_values[$insert_bound_key] = (string)$insert_value;
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
            // 获取字段列表
            $firstInsertItem = reset($all_insert_items);
            if (!empty($firstInsertItem)) {
                $insert_fields = array_keys($firstInsertItem);
                $insert_fields_quoted = array_map(fn($field) => '"' . $field . '"', $insert_fields);
                $insert_fields_str = '(' . implode(',', $insert_fields_quoted) . ')';
                
                // PostgreSQL 批量插入语法
                $sql .= "INSERT INTO {$table} {$insert_fields_str} VALUES {$values}";
                
                // 如果有 ON CONFLICT 需求，添加 ON CONFLICT 子句
                if (!empty($this->exist_update_sql)) {
                    // 构建冲突字段列表
                    $conflictFields = [];
                    if (!empty($this->insert_update_where_fields)) {
                        foreach ($this->insert_update_where_fields as $field) {
                            $conflictFields[] = '"' . $field . '"';
                        }
                    }
                    if (!empty($conflictFields)) {
                        $sql .= ' ON CONFLICT (' . implode(', ', $conflictFields) . ') ' . $this->exist_update_sql;
                    }
                }
                
                // 如果有 identity_field，添加 RETURNING 子句
                if ($this->identity_field) {
                    $sql .= ' RETURNING "' . $this->identity_field . '"';
                }
            }
        }
        
        return $sql;
    }

    /**
     * 构建 UPDATE 语句（PostgreSQL 语法）
     */
    protected function buildUpdateForPgsql(string $table, string $wheres): string
    {
        if (empty($wheres)) {
            throw new DbException(__('请设置更新条件'));
        }
        
        $updates = '';
        
        // 处理 dec_inc_updates
        if (!empty($this->dec_inc_updates)) {
            foreach ($this->dec_inc_updates as $dec_inc_update_field => $dec_inc_update_value) {
                $field_quoted = '"' . $dec_inc_update_field . '"';
                $updates .= "{$field_quoted} = {$field_quoted} {$dec_inc_update_value},";
            }
        }
        
        // 处理批量更新（使用 CASE WHEN）
        if (!empty($this->updates)) {
            $identity_values = array_column($this->updates, $this->identity_field);
            if ($identity_values) {
                $identity_values_str = '';
                foreach ($identity_values as $key => $identityValue) {
                    $identity_values_key = ':' . md5('update_identity_values_key' . $key);
                    $identity_values_str .= $identity_values_key . ',';
                    $this->bound_values[$identity_values_key] = (string)$identityValue;
                }
                $identity_values_str = rtrim($identity_values_str, ',');
                $identity_field_quoted = '"' . $this->identity_field . '"';
                $wheres .= ($wheres ? ' AND ' : 'WHERE ') . "{$identity_field_quoted} IN ($identity_values_str)";
                
                // 使用 CASE WHEN 进行批量更新
                $keys = array_keys(current($this->updates));
                foreach ($keys as $column) {
                    if ($column === $this->identity_field) {
                        continue;
                    }
                    $column_quoted = '"' . $column . '"';
                    $updates .= sprintf("%s = CASE %s \n", $column_quoted, $identity_field_quoted);
                    foreach ($this->updates as $update_key => $line) {
                        $update_key += 1;
                        $identity_field_column_key = ':' . md5("{$this->identity_field}_{$column}_key_{$update_key}");
                        $this->bound_values[$identity_field_column_key] = (string)$line[$this->identity_field];
                        $identity_field_column_value = ':' . md5("update_{$column}_value_{$update_key}");
                        $this->bound_values[$identity_field_column_value] = (string)$line[$column];
                        $updates .= sprintf('WHEN %s THEN %s ', $identity_field_column_key, $identity_field_column_value);
                    }
                    $updates .= 'END,';
                }
            } else {
                // 单条更新
                if (count($this->updates) > 1) {
                    throw new \Exception(__('更新条数大于一条时请使用示例更新'));
                }
                foreach ($this->updates[0] as $update_field => $field_value) {
                    $update_key = ':' . md5($update_field);
                    $update_field_quoted = '"' . $update_field . '"';
                    $this->bound_values[$update_key] = (string)$field_value;
                    $updates .= "{$update_field_quoted} = $update_key,";
                }
            }
        }
        
        // 处理 single_updates
        if (!empty($this->single_updates)) {
            foreach ($this->single_updates as $update_field => $update_value) {
                $update_field_quoted = '"' . $update_field . '"';
                $update_key = ':' . md5($update_field);
                $this->bound_values[$update_key] = (string)$update_value;
                $updates .= "{$update_field_quoted}=$update_key,";
            }
        }
        
        if (!$updates) {
            throw new DbException(__('没有要更新的字段'));
        }
        
        $updates = rtrim($updates, ',');
        return "UPDATE {$table} SET {$updates} {$wheres} {$this->additional_sql}";
    }

    /**
     * 检测条件数组（从 SqlTrait 复制，确保可以访问）
     */
    protected function checkWhereArray(array $where_array, mixed $f_key): void
    {
        foreach ($where_array as $f_item_key => $f_item_value) {
            if (!is_numeric($f_item_key)) {
                $this->exceptionHandle(__('Where查询异常：%{1},%{2},%{3}', ["第{$f_key}个条件数组错误", '出错的数组：["' . implode('","', $where_array) . '"]', "示例：where([['name','like','%张三%','or'],['name','like','%李四%']])"]));
            }
        }
    }

    /**
     * 检测条件参数是否正确（从 SqlTrait 复制，确保可以访问）
     */
    protected function checkConditionString(array $where_array): string
    {
        if (in_array(strtolower($where_array[1]), $this->conditions)) {
            return $where_array[1];
        } else {
            $this->exceptionHandle(__('当前错误的条件操作符：%{1} ,当前的条件数组：%{2}, 允许的条件符：%{3}', [$where_array[1], '["' . implode('","', $where_array) . '"]', '["' . implode('","', $this->conditions) . '"]']));
        }
    }

    public function clear(string $type = ''): QueryInterface
    {
        if ($type) {
            $attr_var_name = $type;
            if (defined('DEV') && DEV && !isset(self::init_vars[$attr_var_name])) {
                $this->exceptionHandle(__('不支持的清理类型：%{1} 支持的初始化类型：%{2}', [$attr_var_name, var_export(self::init_vars, true)]));
            }
            $this->$attr_var_name = self::init_vars[$attr_var_name] ?? null;
        } else {
            $this->reset();
        }
        $this->_unit_primary_keys = [];
        return $this;
    }

    public function clearQuery(string $type = ''): QueryInterface
    {
        if ($type) {
            $attr_var_name = $type;
            if (defined('DEV') && DEV && !isset(self::init_vars[$attr_var_name])) {
                $this->exceptionHandle(__('不支持的清理类型：%{1} 支持的初始化类型：%{2}', [$attr_var_name, var_export(self::init_vars, true)]));
            }
            $this->$attr_var_name = self::init_vars[$attr_var_name] ?? null;
        } else {
            foreach (self::query_vars as $query_field => $query_var) {
                $this->$query_field = $query_var;
            }
        }
        return $this;
    }

    public function reset(): QueryInterface
    {
        foreach (self::init_vars as $init_field => $init_var) {
            $this->$init_field = $init_var;
        }
        $this->PDOStatement = null;
        return $this;
    }

    public function beginTransaction(): void
    {
        // 🔧 修复：getLink() 现在直接返回原始 PDO，不再使用包装器
        $this->getLink()->beginTransaction();
    }

    public function rollBack(): void
    {
        // 🔧 修复：getLink() 现在直接返回原始 PDO，不再使用包装器
        if($this->getLink()->inTransaction()) {
            $this->getLink()->rollBack();
        }
    }

    public function commit(): void
    {
        // 🔧 修复：getLink() 现在直接返回原始 PDO，不再使用包装器
        $this->getLink()->commit();
    }

    /**
     * 解析字段名，将反引号替换为双引号
     * PostgreSQL 使用双引号作为标识符
     */
    protected static function parserFiled(mixed &$field): mixed
    {
        if (!is_string($field)) {
            return $field;
        }
        
        // 去除反引号，添加双引号
        $field = str_replace('`', '', $field);
        // 如果包含点号，分别处理
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            $field = '"' . implode('"."', $parts) . '"';
        } else {
            $field = '"' . $field . '"';
        }
        return $field;
    }
    
    /**
     * 重写 getPrepareSql 方法，将 SQL 中的反引号替换为双引号
     * PostgreSQL 使用双引号作为标识符
     */
    public function getPrepareSql(bool $format = false): string
    {
        $sql = $this->sql;
        
        // 处理 SHOW FULL COLUMNS FROM 语法（MySQL 特有，需要转换为 PostgreSQL 兼容的查询）
        $sql = self::convertShowColumnsToInformationSchema($sql);
        
        // 将反引号替换为双引号（PostgreSQL 语法）
        // 但需要小心处理，避免替换字符串中的反引号
        // 使用正则表达式匹配标识符：`identifier` 或 `schema`.`table`
        $sql = preg_replace_callback(
            '/`([^`]+)`/',
            function($matches) {
                $identifier = $matches[1];
                // 如果包含点号，分别处理每个部分
                if (str_contains($identifier, '.')) {
                    $parts = explode('.', $identifier);
                    return '"' . implode('"."', $parts) . '"';
                }
                return '"' . $identifier . '"';
            },
            $sql
        );
        
        if ($format) {
            return \SqlFormatter::format($sql);
        }
        return $sql;
    }
    
    /**
     * 将 MySQL 的 SHOW FULL COLUMNS FROM 语法转换为 PostgreSQL 的 information_schema 查询
     * 
     * @param string $sql SQL 语句
     * @return string 转换后的 SQL
     */
    public static function convertShowColumnsToInformationSchema(string $sql): string
    {
        // 匹配 SHOW FULL COLUMNS FROM table_name 或 SHOW COLUMNS FROM table_name
        if (preg_match('/SHOW\s+(FULL\s+)?COLUMNS\s+FROM\s+([^\s;]+)/i', $sql, $matches)) {
            $tableName = trim($matches[2], '`"\'');
            
            // 解析 schema 和 table
            $schema = 'public';
            $table = $tableName;
            
            if (str_contains($tableName, '.')) {
                $parts = explode('.', $tableName);
                if (count($parts) === 2) {
                    $schema = trim($parts[0], '`"\'');
                    $table = trim($parts[1], '`"\'');
                } elseif (count($parts) >= 3) {
                    // 可能是 database.schema.table 格式，取后两部分
                    $schema = trim($parts[count($parts) - 2], '`"\'');
                    $table = trim($parts[count($parts) - 1], '`"\'');
                }
            }
            
            // 转换为 PostgreSQL information_schema 查询
            // 返回与 MySQL SHOW FULL COLUMNS 兼容的字段格式
            $sql = "SELECT 
                c.column_name AS \"Field\",
                c.data_type AS \"Type\",
                CASE WHEN c.is_nullable = 'YES' THEN 'YES' ELSE 'NO' END AS \"Null\",
                CASE WHEN c.column_default LIKE 'nextval%' THEN 'PRI' 
                     WHEN constraints.constraint_type = 'PRIMARY KEY' THEN 'PRI'
                     WHEN constraints.constraint_type = 'UNIQUE' THEN 'UNI'
                     ELSE '' END AS \"Key\",
                c.column_default AS \"Default\",
                CASE WHEN c.column_default LIKE 'nextval%' THEN 'auto_increment' ELSE '' END AS \"Extra\",
                '' AS \"Collation\",
                '' AS \"Privileges\",
                COALESCE(col_description(('{$schema}.{$table}')::regclass::oid, c.ordinal_position), '') AS \"Comment\"
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT kcu.column_name AS col_name, tc.constraint_type
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                WHERE tc.table_schema = '{$schema}' AND tc.table_name = '{$table}'
            ) constraints ON c.column_name = constraints.col_name
            WHERE c.table_schema = '{$schema}' AND c.table_name = '{$table}'
            ORDER BY c.ordinal_position";
        }
        
        return $sql;
    }
    
    /**
     * 重写 getSql 方法，确保返回的 SQL 使用双引号
     */
    public function getSql(bool $format = false): string
    {
        $sql = parent::getSql($format);
        
        // 将反引号替换为双引号（PostgreSQL 语法）
        $sql = preg_replace_callback(
            '/`([^`]+)`/',
            function($matches) {
                $identifier = $matches[1];
                if (str_contains($identifier, '.')) {
                    $parts = explode('.', $identifier);
                    return '"' . implode('"."', $parts) . '"';
                }
                return '"' . $identifier . '"';
            },
            $sql
        );
        
        return $sql;
    }
    
    /**
     * 将 SQL 中的反引号替换为双引号
     * PostgreSQL 使用双引号作为标识符
     * 这是统一的处理函数，在所有 SQL 执行前调用
     */
    public static function convertBackticksToDoubleQuotes(string $sql): string
    {
        return preg_replace_callback(
            '/`([^`]+)`/',
            function($matches) {
                $identifier = $matches[1];
                // 如果包含点号，分别处理每个部分
                if (str_contains($identifier, '.')) {
                    $parts = explode('.', $identifier);
                    return '"' . implode('"."', $parts) . '"';
                }
                return '"' . $identifier . '"';
            },
            $sql
        );
    }
    
    /**
     * 统一处理 SQL：转换反引号为双引号，并将数据库名转换为 public schema，转换 MySQL 函数为 PostgreSQL 兼容函数
     * 
     * @param string $sql SQL 语句
     * @return string 转换后的 SQL
     */
    protected function normalizeSql(string $sql): string
    {
        // 1. 转换 SHOW FULL COLUMNS FROM 语法
        $sql = self::convertShowColumnsToInformationSchema($sql);
        
        // 2. 转换反引号为双引号
        $sql = self::convertBackticksToDoubleQuotes($sql);
        
        // 3. 处理直接传入的 SQL 字符串中的表名（仅用于 query() 方法直接传入的 SQL）
        // 注意：通过 table() 方法设置的表名已经在 formatTableNameForPgsql() 中处理，不需要这里处理
        // 这里只处理直接传入的 SQL 字符串中可能包含的数据库名
        $dbName = $this->db_name ?? 'public';
        if ($dbName !== 'public') {
            // 只处理未格式化的表名（直接传入的 SQL 中可能包含 database.table 格式）
            // 替换 database.table 为 "public"."table"（无引号的情况）
            $sql = preg_replace('/\b' . preg_quote($dbName, '/') . '\.([a-zA-Z_][a-zA-Z0-9_]*)\b/', '"public"."$1"', $sql);
        }
        
        // 🔧 修复：修复可能出现的双引号重复问题（防止意外情况）
        // 修复 "schema"".""table" 格式（三个连续的双引号，中间有点）
        $sql = preg_replace('/"([^"]+)""\."([^"]+)"/', '"$1"."$2"', $sql);
        // 修复 "schema""table" 格式（两个连续的双引号，中间没有点）
        $sql = preg_replace('/"([^"]+)""([^"]+)"/', '"$1"."$2"', $sql);
        // 修复连续的三个双引号：""." -> "."
        $sql = preg_replace('/""\."/', '".', $sql);
        
        // 4. 转换 MySQL 日期函数为 PostgreSQL 兼容函数
        $sql = $this->convertMysqlFunctionsToPostgresql($sql);
        
        return $sql;
    }
    
    /**
     * 转换 MySQL 日期/时间函数为 PostgreSQL 兼容函数
     * 
     * @param string $sql SQL 语句
     * @return string 转换后的 SQL
     */
    protected function convertMysqlFunctionsToPostgresql(string $sql): string
    {
        // CURDATE() -> CURRENT_DATE
        $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql);
        
        // DATE(CURDATE()-1) -> (CURRENT_DATE - INTERVAL '1 day')
        // DATE(field) = DATE(CURDATE()-N) -> DATE(field) = (CURRENT_DATE - INTERVAL 'N day')
        $sql = preg_replace('/DATE\s*\(\s*CURRENT_DATE\s*-\s*(\d+)\s*\)/i', "(CURRENT_DATE - INTERVAL '$1 day')", $sql);
        
        // DATE_SUB(CURDATE(), INTERVAL N DAY) -> (CURRENT_DATE - INTERVAL 'N day')
        $sql = preg_replace('/DATE_SUB\s*\(\s*CURRENT_DATE\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i', "(CURRENT_DATE - INTERVAL '$1 day')", $sql);
        
        // DATE_SUB(NOW(), INTERVAL N DAY) -> (NOW() - INTERVAL 'N day')
        $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i', "(NOW() - INTERVAL '$1 day')", $sql);
        
        // DATE_SUB(NOW(), INTERVAL N WEEK) -> (NOW() - INTERVAL 'N week')
        $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+WEEK\s*\)/i', "(NOW() - INTERVAL '$1 week')", $sql);
        
        // DATE_SUB(NOW(), INTERVAL N MONTH) -> (NOW() - INTERVAL 'N month')
        $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+MONTH\s*\)/i', "(NOW() - INTERVAL '$1 month')", $sql);
        
        // DATE_SUB(NOW(), INTERVAL N QUARTER) -> (NOW() - INTERVAL 'N*3 month')
        $sql = preg_replace_callback('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+QUARTER\s*\)/i', function($matches) {
            $months = intval($matches[1]) * 3;
            return "(NOW() - INTERVAL '{$months} month')";
        }, $sql);
        
        // DATE_SUB(NOW(), INTERVAL N YEAR) -> (NOW() - INTERVAL 'N year')
        $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+YEAR\s*\)/i', "(NOW() - INTERVAL '$1 year')", $sql);
        
        // TO_DAYS(field)=TO_DAYS(NOW()) -> DATE(field) = CURRENT_DATE
        $sql = preg_replace('/TO_DAYS\s*\(\s*([^)]+)\s*\)\s*=\s*TO_DAYS\s*\(\s*NOW\s*\(\s*\)\s*\)/i', 'DATE($1) = CURRENT_DATE', $sql);
        
        // YEAR(field) -> EXTRACT(YEAR FROM field)
        $sql = preg_replace('/\bYEAR\s*\(\s*([^)]+)\s*\)/i', 'EXTRACT(YEAR FROM $1)', $sql);
        
        // QUARTER(field) -> EXTRACT(QUARTER FROM field)
        $sql = preg_replace('/\bQUARTER\s*\(\s*([^)]+)\s*\)/i', 'EXTRACT(QUARTER FROM $1)', $sql);
        
        // DATE_FORMAT(field, '%Y%m') -> TO_CHAR(field, 'YYYYMM')
        $sql = preg_replace('/DATE_FORMAT\s*\(\s*([^,]+)\s*,\s*[\'"]%Y%m[\'"]\s*\)/i', "TO_CHAR($1, 'YYYYMM')", $sql);
        
        // DATE_FORMAT(field, '%Y-%m-%d') -> TO_CHAR(field, 'YYYY-MM-DD')
        $sql = preg_replace('/DATE_FORMAT\s*\(\s*([^,]+)\s*,\s*[\'"]%Y-%m-%d[\'"]\s*\)/i', "TO_CHAR($1, 'YYYY-MM-DD')", $sql);
        
        // YEARWEEK(DATE_FORMAT(field,'%Y-%m-%d')) = YEARWEEK(NOW()) -> TO_CHAR(field, 'IYYY-IW') = TO_CHAR(NOW(), 'IYYY-IW')
        $sql = preg_replace('/YEARWEEK\s*\(\s*TO_CHAR\s*\(\s*([^,]+)\s*,\s*[\'"]YYYY-MM-DD[\'"]\s*\)\s*\)\s*=\s*YEARWEEK\s*\(\s*NOW\s*\(\s*\)\s*\)/i', 
            "TO_CHAR($1, 'IYYY-IW') = TO_CHAR(NOW(), 'IYYY-IW')", $sql);
        
        // YEARWEEK(DATE_FORMAT(field,'%Y-%m-%d')) = YEARWEEK(NOW())-N -> TO_CHAR(field, 'IYYY-IW') = TO_CHAR(NOW() - INTERVAL 'N week', 'IYYY-IW')
        $sql = preg_replace('/YEARWEEK\s*\(\s*TO_CHAR\s*\(\s*([^,]+)\s*,\s*[\'"]YYYY-MM-DD[\'"]\s*\)\s*\)\s*=\s*YEARWEEK\s*\(\s*NOW\s*\(\s*\)\s*\)\s*-\s*(\d+)/i', 
            "TO_CHAR($1, 'IYYY-IW') = TO_CHAR(NOW() - INTERVAL '$2 week', 'IYYY-IW')", $sql);
        
        // PERIOD_DIFF(DATE_FORMAT(NOW(),'%Y%m'),DATE_FORMAT(field,'%Y%m')) = N 
        // -> (EXTRACT(YEAR FROM NOW()) * 12 + EXTRACT(MONTH FROM NOW())) - (EXTRACT(YEAR FROM field) * 12 + EXTRACT(MONTH FROM field)) = N
        // 简化处理：转换为 TO_CHAR 比较
        $sql = preg_replace('/PERIOD_DIFF\s*\(\s*TO_CHAR\s*\(\s*NOW\s*\(\s*\)\s*,\s*[\'"]YYYYMM[\'"]\s*\)\s*,\s*TO_CHAR\s*\(\s*([^,]+)\s*,\s*[\'"]YYYYMM[\'"]\s*\)\s*\)\s*=\s*(\d+)/i', 
            "TO_CHAR($1, 'YYYY-MM') = TO_CHAR(NOW() - INTERVAL '$2 month', 'YYYY-MM')", $sql);
        
        return $sql;
    }
    
    /**
     * 转换参数名：PostgreSQL 要求参数名必须以字母开头
     * 如果参数名是 32 位 MD5 哈希且以数字开头，添加 'p' 前缀
     * 
     * @param string $sql SQL 语句
     * @return string 转换后的 SQL
     */
    protected function normalizeParameterNames(string $sql): string
    {
        // 🔧 修复：PostgreSQL 参数名必须是字母数字下划线，不能包含特殊字符
        // 转换 SQL 中的所有参数名，确保符合 PostgreSQL 要求
        return preg_replace_callback('/:([a-zA-Z0-9_]+)/', function($matches) {
            $paramName = $matches[1];
            // 如果参数名以数字开头，添加 'p' 前缀
            if (preg_match('/^[0-9]/', $paramName)) {
                return ':p' . $paramName;
            }
            // 移除所有非字母数字下划线的字符
            $paramName = preg_replace('/[^a-zA-Z0-9_]/', '_', $paramName);
            return ':' . $paramName;
        }, $sql);
    }
    
    /**
     * 转换参数数组：PostgreSQL 要求参数名必须以字母开头
     * 
     * @param array|null $params 参数数组
     * @return array|null 转换后的参数数组
     */
    protected function normalizeParameterArray(?array $params): ?array
    {
        if ($params === null) {
            return null;
        }
        
        $convertedParams = [];
        foreach ($params as $paramName => $value) {
            // 🔧 修复：确保参数名符合 PostgreSQL 要求（只包含字母数字下划线）
            // 移除 ':' 前缀（如果有）
            $paramNameWithoutColon = str_starts_with($paramName, ':') ? substr($paramName, 1) : $paramName;
            // 移除所有非字母数字下划线的字符
            $paramNameClean = preg_replace('/[^a-zA-Z0-9_]/', '_', $paramNameWithoutColon);
            // 如果参数名以数字开头，添加 'p' 前缀
            if (preg_match('/^[0-9]/', $paramNameClean)) {
                $paramNameClean = 'p' . $paramNameClean;
            }
            $normalizedParamName = ':' . $paramNameClean;
            // 直接使用规范化后的参数名
            $convertedParams[$normalizedParamName] = $value;
        }
        
        return $convertedParams;
    }
    
    /**
     * 包装 PDOStatement，添加 nextRowset() 支持
     * PostgreSQL 不支持多结果集，所以 nextRowset() 总是返回 false
     * 
     * @param \PDOStatement $stmt 原始 PDOStatement
     * @param string $originalQuery 原始 SQL 查询
     * @return \PDOStatement 包装后的 PDOStatement
     */
    protected function wrapPDOStatement(\PDOStatement $stmt, string $originalQuery = ''): \PDOStatement
    {
        $pdo = $this->getLink(); // 使用闭包捕获 PDO
        return new class($stmt, $originalQuery, $pdo) extends \PDOStatement {
            private \PDOStatement $stmt;
            private string $originalQuery;
            private ?\PDO $pdo;
            
            public function __construct(\PDOStatement $stmt, string $originalQuery = '', ?\PDO $pdo = null) {
                $this->stmt = $stmt;
                $this->originalQuery = $originalQuery;
                $this->pdo = $pdo;
            }
            
            /**
             * PostgreSQL 不支持多结果集，所以 nextRowset() 总是返回 false
             */
            public function nextRowset(): bool {
                return false;
            }
            
            // 代理所有其他 PDOStatement 方法
            public function __call(string $name, array $arguments) {
                return $this->stmt->$name(...$arguments);
            }
            
            // 实现 PDOStatement 接口的必要方法
            public function execute(?array $params = null): bool {
                return $this->stmt->execute($params);
            }
            
            public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
                return $this->stmt->fetch($mode, $cursorOrientation, $cursorOffset);
            }
            
            public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array {
                return $this->stmt->fetchAll($mode, ...$args);
            }
            
            public function fetchColumn(int $column = 0): mixed {
                return $this->stmt->fetchColumn($column);
            }
            
            public function bindParam(string|int $param, mixed &$var, int $type = \PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool {
                return $this->stmt->bindParam($param, $var, $type, $maxLength, $driverOptions);
            }
            
            public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool {
                return $this->stmt->bindValue($param, $value, $type);
            }
            
            public function bindColumn(string|int $column, mixed &$var, int $type = \PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool {
                return $this->stmt->bindColumn($column, $var, $type, $maxLength, $driverOptions);
            }
            
            public function rowCount(): int {
                return $this->stmt->rowCount();
            }
            
            public function errorCode(): ?string {
                return $this->stmt->errorCode();
            }
            
            public function errorInfo(): array {
                return $this->stmt->errorInfo();
            }
            
            public function setAttribute(int $attribute, mixed $value): bool {
                return $this->stmt->setAttribute($attribute, $value);
            }
            
            public function getAttribute(int $name): mixed {
                return $this->stmt->getAttribute($name);
            }
            
            public function columnCount(): int {
                return $this->stmt->columnCount();
            }
            
            public function getColumnMeta(int $column): array|false {
                return $this->stmt->getColumnMeta($column);
            }
            
            public function setFetchMode(int $mode, mixed ...$args): true {
                return $this->stmt->setFetchMode($mode, ...$args);
            }
            
            public function fetchObject(?string $class = "stdClass", array $constructorArgs = []): object|false {
                return $this->stmt->fetchObject($class, $constructorArgs);
            }
            
            public function closeCursor(): bool {
                return $this->stmt->closeCursor();
            }
            
            public function debugDumpParams(): ?bool {
                return $this->stmt->debugDumpParams();
            }
        };
    }
    
    /**
     * PostgreSQL 适配的 prepare 方法
     * 在调用 PDO prepare 之前规范化 SQL 和参数名，并包装返回的 PDOStatement
     * 
     * @param string $sql SQL 语句
     * @param array $options PDO prepare 选项
     * @return \PDOStatement|false 包装后的 PDOStatement 或 false
     */
    protected function preparePgsql(string $sql, array $options = []): \PDOStatement|false
    {
        // 🔧 修复：规范化 SQL（反引号、数据库名、MySQL 函数转换）
        $sql = $this->normalizeSql($sql);
        
        // 🔧 修复：规范化参数数组（确保参数名符合 PostgreSQL 要求）
        // 先规范化参数数组，然后根据规范化后的参数名更新 SQL
        $originalBoundValues = $this->bound_values;
        $this->bound_values = $this->normalizeParameterArray($this->bound_values);
        
        // 🔧 修复：如果参数名被规范化了，需要更新 SQL 中的参数名
        if ($originalBoundValues !== $this->bound_values && !empty($originalBoundValues)) {
            // 创建参数名映射
            $paramMapping = [];
            foreach ($originalBoundValues as $oldParam => $value) {
                $oldParamClean = str_starts_with($oldParam, ':') ? substr($oldParam, 1) : $oldParam;
                $oldParamClean = preg_replace('/[^a-zA-Z0-9_]/', '_', $oldParamClean);
                if (preg_match('/^[0-9]/', $oldParamClean)) {
                    $oldParamClean = 'p' . $oldParamClean;
                }
                $newParam = ':' . $oldParamClean;
                if (isset($this->bound_values[$newParam])) {
                    $paramMapping[$oldParam] = $newParam;
                }
            }
            // 更新 SQL 中的参数名
            foreach ($paramMapping as $oldParam => $newParam) {
                if ($oldParam !== $newParam) {
                    $sql = str_replace($oldParam, $newParam, $sql);
                }
            }
        }
        
        // 🔧 修复：转换参数名（PostgreSQL 要求参数名必须以字母开头）
        $sql = $this->normalizeParameterNames($sql);
        
        // 尝试 prepare，如果失败则检查是否是多个命令的错误
        $stmt = @$this->getLink()->prepare($sql, $options);
        if ($stmt === false) {
            $errorInfo = $this->getLink()->errorInfo();
            $errorCode = $errorInfo[0] ?? '';
            $errorMessage = $errorInfo[2] ?? '';
            
            // 检查是否是"不能插入多个命令"的错误
            if ($errorCode === '42601' && 
                (str_contains($errorMessage, 'cannot insert multiple commands') || 
                 str_contains($errorMessage, 'multiple commands'))) {
                throw new \PDOException(
                    "PostgreSQL prepared statements cannot contain multiple SQL commands. " .
                    "Use exec() for multiple statements or split them into separate calls. " .
                    "SQL preview: " . substr($sql, 0, 200),
                    (int)$errorCode
                );
            }
            return false;
        }
        
        // 🔧 修复：返回包装的 PDOStatement，实现 nextRowset() 方法
        return $this->wrapPDOStatement($stmt, $sql);
    }
    
    /**
     * PostgreSQL 适配的 exec 方法
     * 在调用 PDO exec 之前规范化 SQL
     * 
     * @param string $sql SQL 语句
     * @return int|false 受影响的行数或 false
     */
    protected function execPgsql(string $sql): int|false
    {
        // 🔧 修复：规范化 SQL（反引号、数据库名、MySQL 函数转换）
        $sql = $this->normalizeSql($sql);
        
        // 🔧 修复：execPgsql 内部已经规范化 SQL，这里直接调用原始 PDO exec
        return $this->getLink()->exec($sql);
    }
    
    /**
     * PostgreSQL 适配的 query 方法
     * 在调用 PDO query 之前规范化 SQL，并包装返回的 PDOStatement
     * 
     * @param string $sql SQL 语句
     * @param int|null $fetchMode 获取模式
     * @param mixed ...$fetchModeArgs 获取模式参数
     * @return \PDOStatement|false 包装后的 PDOStatement 或 false
     */
    protected function queryPgsql(string $sql, ?int $fetchMode = null, ...$fetchModeArgs): \PDOStatement|false
    {
        // 🔧 修复：规范化 SQL（反引号、数据库名、MySQL 函数转换）
        $sql = $this->normalizeSql($sql);
        
        $stmt = $this->getLink()->query($sql, $fetchMode, ...$fetchModeArgs);
        if ($stmt === false) {
            return false;
        }
        
        // 🔧 修复：返回包装的 PDOStatement，实现 nextRowset() 方法
        return $this->wrapPDOStatement($stmt, $sql);
    }
    
    /**
     * 检查 SQL 是否包含多个命令（用分号分隔）
     * 排除字符串中的分号（单引号、双引号中的分号）
     * PostgreSQL 的 prepared statement 不支持多个 SQL 命令
     * 
     * @param string $sql SQL 语句
     * @return bool 如果包含多个命令返回 true，否则返回 false
     */
    protected function hasMultipleSqlCommands(string $sql): bool
    {
        // 移除字符串中的分号（单引号和双引号中的分号）
        $sqlWithoutStrings = preg_replace_callback(
            '/(["\'])(?:(?=(\\\\?))\2.)*?\1/',
            function ($matches) {
                // 将字符串中的分号替换为占位符
                return str_replace(';', '___SEMICOLON_PLACEHOLDER___', $matches[0]);
            },
            $sql
        );
        
        // 计算分号数量（排除末尾的分号）
        $trimmedSql = rtrim(trim($sqlWithoutStrings), ';');
        $semicolonCount = substr_count($trimmedSql, ';');
        
        // 如果有分号，说明包含多个命令
        return $semicolonCount > 0;
    }
    
    /**
     * 重写 fetch 方法，处理 PostgreSQL 不支持多个 SQL 命令的限制
     * 如果 SQL 包含多个命令，使用 exec() 而不是 prepare/execute
     */
    public function fetch(string $model_class = ''): mixed
    {
        // 在执行前检查是否包含多个 SQL 命令
        // PostgreSQL 的 prepared statement 不支持多个 SQL 命令
        if (!empty($this->sql)) {
            $hasMultipleCommands = $this->hasMultipleSqlCommands($this->sql);
            
            // 如果是批量插入，完全在这里处理，避免父类再次执行
            if ($this->batch && $this->fetch_type == 'insert') {
                $hasReturning = stripos($this->sql, 'RETURNING') !== false;
                
                if ($hasMultipleCommands) {
                    // 多个命令，即使有 RETURNING 也不能使用 prepare/execute
                    // 使用 exec() 执行，但无法获取 RETURNING 结果
                    $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                    $result = $this->execPgsql($sqlWithBounds);
                    if ($result === false) {
                        $errorInfo = $this->getLink()->errorInfo();
                        throw new \PDOException(
                            $errorInfo[2] ?? 'SQL execution failed',
                            (int)($errorInfo[0] ?? 0)
                        );
                    }
                    // 获取最后插入的 ID
                    $lastId = $this->getLink()->lastInsertId();
                    // 创建一个假的 PDOStatement，返回插入的 ID
                    $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                        private $lastId;
                        private $identityField;
                        public function __construct($lastId, $identityField) {
                            $this->lastId = $lastId;
                            $this->identityField = $identityField;
                        }
                        public function execute(?array $params = null): bool { return true; }
                        public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                            return [[$this->identityField => $this->lastId]]; 
                        }
                        public function nextRowset(): bool { return false; }
                    };
                    // 清空 SQL，避免父类再次执行
                    $this->sql = '';
                    $this->batch = false;
                } elseif ($hasReturning) {
                    // 单个命令且有 RETURNING，尝试使用 prepare/execute
                    // 但先尝试 prepare，如果失败（可能是多个命令），回退到 exec()
                    try {
                            $testStmt = $this->preparePgsql($this->sql);
                        if ($testStmt === false) {
                            // prepare 返回 false，检查错误信息
                            $errorInfo = $this->getLink()->errorInfo();
                            if (($errorInfo[0] ?? '') === '42601') {
                                // 多个命令错误，使用 exec()
                                $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                                $result = $this->execPgsql($sqlWithBounds);
                                if ($result === false) {
                                    throw new \PDOException(
                                        $errorInfo[2] ?? 'SQL execution failed',
                                        (int)($errorInfo[0] ?? 0)
                                    );
                                }
                                $lastId = $this->getLink()->lastInsertId();
                                $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                                    private $lastId;
                                    private $identityField;
                                    public function __construct($lastId, $identityField) {
                                        $this->lastId = $lastId;
                                        $this->identityField = $identityField;
                                    }
                                    public function execute(?array $params = null): bool { return true; }
                                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                                        return [[$this->identityField => $this->lastId]]; 
                                    }
                                    public function nextRowset(): bool { return false; }
                            };
                            $this->sql = '';
                            $this->batch = false;
                        } else {
                            // 其他错误，抛出异常
                                throw new \PDOException(
                                    $errorInfo[2] ?? 'SQL preparation failed',
                                    (int)($errorInfo[0] ?? 0)
                                );
                            }
                        } else {
                            // prepare 成功，执行
                            $this->PDOStatement = $testStmt;
                            $this->PDOStatement->execute($this->bound_values);
                            // 执行成功，不需要清空 SQL，让父类处理结果
                        }
                    } catch (\PDOException $e) {
                        // 如果 prepare 抛出异常（可能是多个命令），回退到 exec()
                        if (str_contains($e->getMessage(), 'cannot insert multiple commands') || 
                            str_contains($e->getMessage(), 'multiple commands') ||
                            ($e->getCode() === '42601')) {
                            $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                            $result = $this->execPgsql($sqlWithBounds);
                            if ($result === false) {
                                $errorInfo = $this->getLink()->errorInfo();
                                throw new \PDOException(
                                    $errorInfo[2] ?? 'SQL execution failed',
                                    (int)($errorInfo[0] ?? 0),
                                    $e
                                );
                            }
                            $lastId = $this->getLink()->lastInsertId();
                            $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                                private $lastId;
                                private $identityField;
                                public function __construct($lastId, $identityField) {
                                    $this->lastId = $lastId;
                                    $this->identityField = $identityField;
                                }
                                public function execute(?array $params = null): bool { return true; }
                                public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                                    return [[$this->identityField => $this->lastId]]; 
                                }
                                public function nextRowset(): bool { return false; }
                            };
                            $this->sql = '';
                            $this->batch = false;
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    // 没有 RETURNING，但可能包含多个命令（虽然检测说没有，但作为备用）
                    // 先尝试 prepare，如果失败则使用 exec()
                    try {
                            $testStmt = $this->preparePgsql($this->sql);
                        if ($testStmt === false) {
                            $errorInfo = $this->getLink()->errorInfo();
                            if (($errorInfo[0] ?? '') === '42601') {
                                // prepare 失败，使用 exec()
                                $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                                $result = $this->execPgsql($sqlWithBounds);
                                if ($result === false) {
                                    throw new \PDOException(
                                        $errorInfo[2] ?? 'SQL execution failed',
                                        (int)($errorInfo[0] ?? 0)
                                    );
                                }
                                $lastId = $this->getLink()->lastInsertId();
                                $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                                    private $lastId;
                                    private $identityField;
                                    public function __construct($lastId, $identityField) {
                                        $this->lastId = $lastId;
                                        $this->identityField = $identityField;
                                    }
                                    public function execute(?array $params = null): bool { return true; }
                                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                                        return [[$this->identityField => $this->lastId]]; 
                                    }
                                    public function nextRowset(): bool { return false; }
                                };
                                $this->sql = '';
                                $this->batch = false;
                            }
                        }
                    } catch (\PDOException $e) {
                        if (str_contains($e->getMessage(), 'cannot insert multiple commands') || 
                            str_contains($e->getMessage(), 'multiple commands') ||
                            ($e->getCode() === '42601')) {
                            $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                            $result = $this->execPgsql($sqlWithBounds);
                            if ($result === false) {
                                $errorInfo = $this->getLink()->errorInfo();
                                throw new \PDOException(
                                    $errorInfo[2] ?? 'SQL execution failed',
                                    (int)($errorInfo[0] ?? 0),
                                    $e
                                );
                            }
                            $lastId = $this->getLink()->lastInsertId();
                            $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                                private $lastId;
                                private $identityField;
                                public function __construct($lastId, $identityField) {
                                    $this->lastId = $lastId;
                                    $this->identityField = $identityField;
                                }
                                public function execute(?array $params = null): bool { return true; }
                                public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                                    return [[$this->identityField => $this->lastId]]; 
                                }
                                public function nextRowset(): bool { return false; }
                            };
                            $this->sql = '';
                            $this->batch = false;
                        } else {
                            throw $e;
                        }
                    }
                }
                
                // 批量插入已处理，如果已经设置了 PDOStatement 且 SQL 已清空，直接调用父类处理结果
                // 否则让父类处理（父类会检查并执行）
                if ($this->PDOStatement !== null && empty($this->sql)) {
                    // 已经执行了 SQL，设置 batch = false 避免父类再次执行批量插入逻辑
                    $this->batch = false;
                    // 直接调用父类处理结果
                    return parent::fetch($model_class);
                }
            } elseif ($hasMultipleCommands) {
                // 非批量操作，但包含多个命令
                $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                
                try {
                    $result = $this->execPgsql($sqlWithBounds);
                    if ($result === false) {
                        $errorInfo = $this->getLink()->errorInfo();
                        throw new \PDOException(
                            $errorInfo[2] ?? 'SQL execution failed',
                            (int)($errorInfo[0] ?? 0)
                        );
                    }
                    
                    // 对于 INSERT 操作，获取插入的 ID
                    if ($this->fetch_type == 'insert' && $this->identity_field) {
                        $lastId = $this->getLink()->lastInsertId();
                        // 创建一个假的 PDOStatement，返回插入的 ID，以便父类可以继续处理
                        $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                            private $lastId;
                            private $identityField;
                            public function __construct($lastId, $identityField) {
                                $this->lastId = $lastId;
                                $this->identityField = $identityField;
                            }
                            public function execute(?array $params = null): bool { return true; }
                            public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                                return [[$this->identityField => $this->lastId]]; 
                            }
                            public function nextRowset(): bool { return false; }
                        };
                    } else {
                        // 创建一个假的 PDOStatement，返回空结果
                        $this->PDOStatement = new class extends \PDOStatement {
                            public function execute(?array $params = null): bool { return true; }
                            public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { return []; }
                            public function nextRowset(): bool { return false; }
                        };
                    }
                    $this->sql = ''; // 清空 SQL，避免父类再次执行
                    $this->batch = false;
                } catch (\PDOException $e) {
                    // 检查是否是"不能插入多个命令"的错误（虽然我们已经检测过了，但作为备用）
                    if (str_contains($e->getMessage(), 'cannot insert multiple commands') || 
                        str_contains($e->getMessage(), 'multiple commands')) {
                        throw new \PDOException(
                            "PostgreSQL prepared statements cannot contain multiple SQL commands. " .
                            "Use exec() for multiple statements or split them into separate calls. " .
                            "SQL preview: " . substr($this->sql, 0, 200),
                            (int)$e->getCode(),
                            $e
                        );
                    }
                    throw $e;
                }
            } else {
                // 单个命令，但在 prepare 时可能失败（作为备用检测）
                // 在父类调用 prepare 之前，先尝试 prepare 看看是否会失败
                try {
                            $testStmt = $this->preparePgsql($this->sql);
                    if ($testStmt === false) {
                        $errorInfo = $this->getLink()->errorInfo();
                        if (($errorInfo[0] ?? '') === '42601' && 
                            (str_contains($errorInfo[2] ?? '', 'cannot insert multiple commands') || 
                             str_contains($errorInfo[2] ?? '', 'multiple commands'))) {
                            // prepare 失败，说明包含多个命令，使用 exec()
                            $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                            $result = $this->execPgsql($sqlWithBounds);
                            if ($result === false) {
                                throw new \PDOException(
                                    $errorInfo[2] ?? 'SQL execution failed',
                                    (int)($errorInfo[0] ?? 0)
                                );
                            }
                            if ($this->fetch_type == 'insert' && $this->identity_field) {
                                $lastId = $this->getLink()->lastInsertId();
                                $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                                    private $lastId;
                                    private $identityField;
                                    public function __construct($lastId, $identityField) {
                                        $this->lastId = $lastId;
                                        $this->identityField = $identityField;
                                    }
                                    public function execute(?array $params = null): bool { return true; }
                                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                                        return [[$this->identityField => $this->lastId]]; 
                                    }
                                    public function nextRowset(): bool { return false; }
                                };
                                $this->sql = '';
                                $this->batch = false;
                            } else {
                                $this->PDOStatement = new class extends \PDOStatement {
                                    public function execute(?array $params = null): bool { return true; }
                                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { return []; }
                                    public function nextRowset(): bool { return false; }
                                };
                                $this->sql = '';
                            }
                        }
                    }
                } catch (\PDOException $e) {
                    // prepare 时抛出异常，检查是否是多个命令错误
                    if (str_contains($e->getMessage(), 'cannot insert multiple commands') || 
                        str_contains($e->getMessage(), 'multiple commands') ||
                        ($e->getCode() === '42601')) {
                        // 使用 exec() 执行
                        $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                        $result = $this->execPgsql($sqlWithBounds);
                        if ($result === false) {
                            $errorInfo = $this->getLink()->errorInfo();
                            throw new \PDOException(
                                $errorInfo[2] ?? 'SQL execution failed',
                                (int)($errorInfo[0] ?? 0),
                                $e
                            );
                        }
                        if ($this->fetch_type == 'insert' && $this->identity_field) {
                            $lastId = $this->getLink()->lastInsertId();
                            $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                                private $lastId;
                                private $identityField;
                                public function __construct($lastId, $identityField) {
                                    $this->lastId = $lastId;
                                    $this->identityField = $identityField;
                                }
                                public function execute(?array $params = null): bool { return true; }
                                public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                                    return [[$this->identityField => $this->lastId]]; 
                                }
                                public function nextRowset(): bool { return false; }
                            };
                            $this->sql = '';
                            $this->batch = false;
                        } else {
                            $this->PDOStatement = new class extends \PDOStatement {
                                public function execute(?array $params = null): bool { return true; }
                                public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { return []; }
                                public function nextRowset(): bool { return false; }
                            };
                            $this->sql = '';
                        }
                    }
                    // 其他错误不处理，让父类处理
                }
            }
        }
        
        // 🔧 修复：PostgreSQL 不支持 nextRowset()，完全重写 fetch 方法，不调用父类
        
        // Development SQL logging
        if (Env::get('log.dev_sql.enabled', false)) {
            $log_file = Env::get('log.dev_sql.file', 'dev_sql');
            $sqlWithValues = $this->getSqlWithBounds($this->sql);
            Env::log($log_file, $sqlWithValues, 'QUERY', true, true, 0);
        }
        
        // Database query logging
        if (Env::get('log.db.enabled', false)) {
            $file = Env::get('log.db.file', 'db');
            $sqlWithValues = $this->getSqlWithBounds($this->sql);
            Env::log($file, $sqlWithValues, 'QUERY', true, true, 0);
        }
        
        # 调试环境信息
        if (DEV && Debug::target('pre_fetch')) {
            $msg = __('即将执行信息：') . PHP_EOL;
            $msg .= '$this->batch:' . ($this->batch ? 'true' : 'false') . PHP_EOL;
            $msg .= '$this->fetch_type:' . $this->fetch_type . PHP_EOL;
            $msg .= '$this->sql:' . $this->sql . PHP_EOL;
            $msg .= '$this->bound_values:' . json_encode($this->bound_values) . PHP_EOL;
            $msg .= 'Format SQL:' . $this->getSql(true);
            Debug::target('pre_fetch', $msg);
        }
        
        // 如果 PDOStatement 已经设置且 SQL 已清空，说明已经执行过了，直接处理结果
        if ($this->PDOStatement !== null && empty($this->sql)) {
            // 已经执行了 SQL，直接处理结果
            return $this->processFetchResult($model_class);
        }
        
        // 处理批量插入
        if ($this->batch && $this->fetch_type == 'insert') {
            $hasReturning = stripos($this->sql, 'RETURNING') !== false;
            $hasMultipleCommands = $this->hasMultipleSqlCommands($this->sql);
            
            if ($hasMultipleCommands || !$hasReturning) {
                // 多个命令或没有 RETURNING，使用 exec()
                $sqlWithBounds = $this->getSqlWithBounds($this->sql);
                $result = $this->execPgsql($sqlWithBounds);
                if ($result === false) {
                    $errorInfo = $this->getLink()->errorInfo();
                    throw new \PDOException(
                        $errorInfo[2] ?? 'SQL execution failed',
                        (int)($errorInfo[0] ?? 0)
                    );
                }
                $lastId = $this->getLink()->lastInsertId();
                $this->PDOStatement = new class($lastId, $this->identity_field) extends \PDOStatement {
                    private $lastId;
                    private $identityField;
                    public function __construct($lastId, $identityField) {
                        $this->lastId = $lastId;
                        $this->identityField = $identityField;
                    }
                    public function execute(?array $params = null): bool { return true; }
                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { 
                        return [[$this->identityField => $this->lastId]]; 
                    }
                    public function rowCount(): int { return 1; }
                };
                $this->sql = '';
                $this->batch = false;
            } else {
                // 单个命令且有 RETURNING，使用 prepare/execute
                try {
                    $this->PDOStatement = $this->preparePgsql($this->sql);
                    $this->PDOStatement->execute($this->bound_values);
                    $this->batch = false;
                } catch (\PDOException $e) {
                    throw $e;
                }
            }
        } elseif (!empty($this->sql)) {
            // 检查是否包含多个 SQL 命令
            $hasMultipleCommands = $this->hasMultipleSqlCommands($this->sql);
            
            if ($hasMultipleCommands) {
                // 多个命令，拆分为单独的查询执行
                return $this->executeMultipleCommands($model_class);
            } else {
                // 单个命令，正常执行
                try {
                    $this->PDOStatement = $this->preparePgsql($this->sql);
                    $this->PDOStatement->execute($this->bound_values);
                } catch (\PDOException $e) {
                    throw $e;
                }
            }
        }
        
        // 处理结果
        return $this->processFetchResult($model_class);
    }
    
    /**
     * 🔧 处理 fetch 结果（不调用 nextRowset）
     */
    protected function processFetchResult(string $model_class = ''): mixed
    {
        if ($this->PDOStatement === null) {
            return false;
        }
        
        // 🔧 修复：PostgreSQL 不支持 nextRowset()，只获取第一个结果集
        $origin_data = $this->PDOStatement->fetchAll(PDO::FETCH_ASSOC);
        
        $this->batch = false;
        $data = [];
        if ($model_class) {
            // 确保 $origin_data 是数组或对象，而不是字符串
            if (is_array($origin_data) || is_object($origin_data)) {
                foreach ($origin_data as $origin_datum) {
                    $data[] = ObjectManager::make($model_class, ['data' => $origin_datum], '__construct');
                }
            } else {
                $data = $origin_data;
            }
        } else {
            $data = $origin_data;
        }
        
        switch ($this->fetch_type) {
            case 'find':
                $result = array_shift($data);
                if ($this->find_fields) {
                    if ($result) {
                        if (str_contains($this->find_fields, ',')) {
                            $fields = explode(',', $this->find_fields);
                            $fields_data = [];
                            foreach ($fields as $field) {
                                $fields_data[$field] = $result[$field] ?? null;
                            }
                            $result = $fields_data;
                        } else {
                            $result = $result[$this->find_fields] ?? null;
                        }
                    }
                    $this->find_fields = '';
                    break;
                }
                if ($model_class && empty($result)) {
                    $result = ObjectManager::make($model_class, ['data' => []], '__construct');
                }
                break;
            case 'insert':
                // 如果使用了 RETURNING 子句（PostgreSQL），从结果中获取 ID
                if (!empty($data) && is_array($data)) {
                    $firstRow = null;
                    if (isset($data[0]) && is_array($data[0])) {
                        $firstRow = $data[0];
                    } elseif (is_array($data) && !isset($data[0]) && !empty($data)) {
                        $firstRow = $data;
                    } elseif (count($data) === 1 && is_array($data[0])) {
                        $firstRow = $data[0];
                    }
                    
                    if ($firstRow && $this->identity_field) {
                        if (isset($firstRow[$this->identity_field])) {
                            $result = $firstRow[$this->identity_field];
                        } else {
                            $result = reset($firstRow);
                        }
                    } else {
                        $lastId = $this->getLink()->lastInsertId();
                        $result = $lastId !== false ? $lastId : (reset($data) ?? null);
                    }
                } else {
                    $lastId = $this->getLink()->lastInsertId();
                    $result = $lastId !== false ? $lastId : null;
                }
                break;
            case 'pagination':
            case 'query':
            case 'select':
                $result = $data;
                break;
            case 'delete':
            case 'update':
                // 🔧 修复：UPDATE/DELETE 操作应该返回 int|bool，而不是数组
                // PostgreSQL 的 UPDATE/DELETE 如果没有 RETURNING，fetchAll() 返回空数组 []
                // 如果有 RETURNING，fetchAll() 返回包含数据的数组
                $hasReturning = stripos($this->sql ?? '', 'RETURNING') !== false;
                
                if ($hasReturning) {
                    // 使用了 RETURNING 子句，检查返回的数据
                    // 过滤掉空数组元素（PostgreSQL 可能返回 [[]] 这样的格式）
                    $validData = array_filter($data, function($item) {
                        return is_array($item) && !empty($item);
                    });
                    
                    if (!empty($validData)) {
                        // 有有效的 RETURNING 结果，返回受影响的行数
                        $result = count($validData);
                    } else {
                        // RETURNING 但没有有效数据，使用 rowCount()
                        $rowCount = $this->PDOStatement ? $this->PDOStatement->rowCount() : 0;
                        $result = $rowCount > 0 ? $rowCount : false;
                    }
                } else {
                    // 没有使用 RETURNING 子句，直接使用 rowCount() 判断受影响的行数
                    $rowCount = $this->PDOStatement ? $this->PDOStatement->rowCount() : 0;
                    // rowCount() > 0 表示成功，返回受影响的行数；否则返回 false
                    $result = $rowCount > 0 ? $rowCount : false;
                }
                break;
            default:
                $result = $data;
                break;
        }
        
        $this->fetch_type = '';
        # 调试环境信息
        if (DEV && Debug::target('fetch')) {
            $msg = __('执行信息：') . PHP_EOL;
            $msg .= '$this->batch:' . ($this->batch ? 'true' : 'false') . PHP_EOL;
            $msg .= '$this->fetch_type:' . $this->fetch_type . PHP_EOL;
            $msg .= '$this->sql:' . $this->sql . PHP_EOL;
            $msg .= '$this->bound_values:' . json_encode($this->bound_values) . PHP_EOL;
            $msg .= 'Format SQL:' . $this->getSql(true);
            Debug::target('fetch', $msg);
        }
        
        $this->reset();
        return $result;
    }
    
    /**
     * 🔧 执行多个 SQL 命令（拆分执行）
     */
    protected function executeMultipleCommands(string $model_class = ''): mixed
    {
        // 拆分 SQL 命令
        $commands = $this->splitSqlCommands($this->sql);
        $results = [];
        
        foreach ($commands as $command) {
            $command = trim($command);
            if (empty($command)) {
                continue;
            }
            
            // 执行单个命令
            try {
                $sqlWithBounds = $this->getSqlWithBounds($command);
                $result = $this->execPgsql($sqlWithBounds);
                
                if ($result === false) {
                    $errorInfo = $this->getLink()->errorInfo();
                    throw new \PDOException(
                        $errorInfo[2] ?? 'SQL execution failed',
                        (int)($errorInfo[0] ?? 0)
                    );
                }
                
                // 对于 INSERT 操作，获取插入的 ID
                if ($this->fetch_type == 'insert' && $this->identity_field) {
                    $lastId = $this->getLink()->lastInsertId();
                    $results[] = $lastId;
                } else {
                    $results[] = $result;
                }
            } catch (\PDOException $e) {
                throw $e;
            }
        }
        
        // 根据 fetch_type 返回结果
        switch ($this->fetch_type) {
            case 'insert':
                // 返回最后一个插入的 ID
                return !empty($results) ? end($results) : null;
            case 'delete':
            case 'update':
                // 返回受影响的行数总和
                return array_sum($results);
            default:
                // 返回所有结果
                return $results;
        }
    }
    
    /**
     * 🔧 拆分 SQL 命令（按分号分割，但忽略字符串中的分号）
     */
    protected function splitSqlCommands(string $sql): array
    {
        $commands = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar) {
                // 检查是否是转义的引号
                if ($i > 0 && $sql[$i - 1] === '\\') {
                    $current .= $char;
                } else {
                    $inString = false;
                    $stringChar = '';
                    $current .= $char;
                }
            } elseif (!$inString && $char === ';') {
                $command = trim($current);
                if (!empty($command)) {
                    $commands[] = $command;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        // 添加最后一个命令
        $command = trim($current);
        if (!empty($command)) {
            $commands[] = $command;
        }
        
        return $commands;
    }
    
    /**
     * 🔧 原始 fetch 方法的错误处理（保留用于兼容）
     */
    protected function handleFetchException(\PDOException $e, string $model_class = ''): mixed
    {
        try {
            return parent::fetch($model_class);
        } catch (\PDOException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            // 检查是否是事务失败错误（25P02）
            // 这通常发生在之前的操作失败后，事务被标记为失败状态
            $isTransactionFailed = (
                $errorCode === '25P02' || 
                str_contains((string)$errorCode, '25P02') ||
                str_contains($errorMessage, 'current transaction is aborted') ||
                str_contains($errorMessage, 'In failed sql transaction')
            );
            
            // 检查是否是 ON CONFLICT 不支持的错误（42P10）
            // 这表示指定的字段没有唯一约束，需要回退到父类的 buildInsert 逻辑（先查询再更新）
            $isOnConflictNotSupported = str_contains($errorMessage, '__FALLBACK_TO_PARENT_LOGIC__');
            
            // 如果是事务失败错误，且之前尝试过 ON CONFLICT，需要回退到父类逻辑
            if ($isTransactionFailed && isset($this->_fallback_data)) {
                // 不回滚事务，由业务层负责处理
                // 回退到父类逻辑（与下面的逻辑相同）
                $isOnConflictNotSupported = true;
            }
            
            if ($isOnConflictNotSupported) {
                // 清除当前的 SQL 和 PDOStatement
                $this->sql = '';
                $this->PDOStatement = null;
                
                // 恢复 insert 数据（使用备份）
                if (isset($this->_fallback_data)) {
                    $this->insert['origin'] = $this->_fallback_data['insert_origin'] ?? null;
                    $this->insert['i_o_u'] = $this->_fallback_data['insert_origin'] ?? null;
                    $this->insert['insert'] = [];
                    $this->insert_update_where_fields = $this->_fallback_data['insert_update_where_fields'] ?? [];
                    $this->insert_update_fields = $this->_fallback_data['insert_update_fields'] ?? [];
                    
                    // 清除备份和旧的 bound_values（让 buildInsert 重新生成）
                    unset($this->_fallback_data);
                    $this->bound_values = []; // 清除旧的参数绑定，让 buildInsert 重新生成
                    
                    // 使用父类的 buildInsert 逻辑重新构建 SQL
                    // 直接使用父类的 buildInsert
                    $parentAdapter = new \Weline\Framework\Database\Connection\Api\Sql\Dialect\GenericDialectAdapter();
                    $reflection = new \ReflectionClass($parentAdapter);
                    $method = $reflection->getMethod('buildInsert');
                    $method->setAccessible(true);
                    $sql = $method->invoke($parentAdapter, $this);
                    
                    // 重新准备 SQL
                    $this->sql = $sql;
                    if (!empty($sql)) {
                        try {
                            $this->PDOStatement = $this->preparePgsql($sql);
                        } catch (\Throwable $e2) {
                            $this->PDOStatement = null;
                        }
                    } else {
                        $this->PDOStatement = null;
                    }
                    
                    // 重新执行
                    return parent::fetch($model_class);
                }
            }
            
            // 其他错误继续抛出
            throw $e;
        }
    }
    
}

