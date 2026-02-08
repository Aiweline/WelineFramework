<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Api\Sql;

use PDO;
use PDOStatement;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultIdentifierFormatter;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultTableNameStrategy;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\IdentifierFormatterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\TableNameStrategyInterface;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Helper\Tool;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\App\Debug;
use Weline\Framework\Manager\ObjectManager;

/**
 * QueryAst - 查询抽象语法树类
 * 负责构建和管理查询的 AST（抽象语法树），具体的 SQL 编译由各个适配器实现
 */
abstract class QueryAst implements QueryInterface
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
    public array $insert_update_fields = [];
    public array $insert_need_fields = [];
    public array $insert_update_where_fields = [];
    public array $joins = [];
    public string $fields = '*';
    public string $find_fields = '';
    public array $single_updates = [];
    public array $updates = [];
    public array $dec_inc_updates = [];
    public array $wheres = [];
    public array $bound_values = [];
    public string $limit = '';
    public array $order = [];
    public string $group_by = '';
    public string $having = '';

    public int $total = 0;

    public ?PDOStatement $PDOStatement = null;
    public string $sql = '';
    public string $additional_sql = '';

    public string $fetch_type = '';

    public array $pagination = ['page' => 1, 'pageSize' => 20, 'totalSize' => 0, 'lastPage' => 0];

    public string $backup_file = '';
    public bool $batch = false;
    
    // 用于存储回退数据的数组（避免 PHP 8.2+ 动态属性警告）
    public array $_fallback_data = [];

    /**
     * 小型 AST，用来描述当前 Query 的结构，方便方言化编译。
     * 结构类似：
     * [
     *   'action' => 'select'|'insert'|'update'|'delete',
     *   'from'   => ['table' => 'm_demo', 'alias' => 'main_table', 'is_subquery' => false],
     *   'select' => ['fields' => 'main_table.*'],
     *   'joins'  => [...],
     *   'where'  => [...],
     *   'group'  => '...',
     *   'having' => '...',
     *   'order'  => [...],
     *   'limit'  => ' LIMIT ...',
     *   'extra'  => $this->additional_sql,
     *   'insert' => $this->insert,
     *   'update' => ['single' => $this->single_updates, 'batch' => $this->updates],
     *   'subqueries' => [ // 子查询 AST 结构（用于存储子查询，保持干净的操作结构）
     *     'subquery_1' => [...], // 子查询的 AST
     *   ],
     * ]
     */
    protected array $ast = [];
    
    /**
     * 子查询计数器，用于生成唯一的子查询标识
     */
    protected int $subquery_counter = 0;

    protected IdentifierFormatterInterface $identifierFormatter;
    protected TableNameStrategyInterface $tableNameStrategy;

    public function __construct(
        ?IdentifierFormatterInterface $identifierFormatter = null,
        ?TableNameStrategyInterface   $tableNameStrategy = null
    ) {
        $this->identifierFormatter = $identifierFormatter ?? new DefaultIdentifierFormatter();
        $this->tableNameStrategy = $tableNameStrategy ?? new DefaultTableNameStrategy($this->identifierFormatter);
    }

    public function setIdentifierFormatter(IdentifierFormatterInterface $identifierFormatter): static
    {
        $this->identifierFormatter = $identifierFormatter;
        return $this;
    }

    public function setTableNameStrategy(TableNameStrategyInterface $tableNameStrategy): static
    {
        $this->tableNameStrategy = $tableNameStrategy;
        return $this;
    }

    public function getIdentifierFormatter(): IdentifierFormatterInterface
    {
        return $this->identifierFormatter;
    }

    public function formatTableName(string $logicalName): string
    {
        return $this->tableNameStrategy->resolve($logicalName, $this->db_name);
    }

    public function identity(string $field): QueryInterface
    {
        $this->identity_field = $field;
        return $this;
    }

    public function table(string $table_name): QueryInterface
    {
        $table_name = trim($table_name);
        if ($table_name === '') {
            return $this;
        }
        if (str_contains($table_name, ' ')) {
            $table_names = preg_split('/\s+/', $table_name);
            $table_name = $table_names[0];
            $alias_name = $table_names[1] ?? $this->table_alias;
            $this->fields = str_replace('main_table.', $alias_name . '.', $this->fields);
            $this->alias($alias_name);
        }
        $this->table = $this->tableNameStrategy->resolve($table_name, $this->db_name);
        // 更新 AST
        $this->updateAstTable($this->table, $this->table_alias);
        return $this;
    }

    /**
     * 使用子查询作为 FROM 子句的表
     * 
     * @param QueryInterface $subquery 子查询对象
     * @param string $alias 子查询的别名
     * @return QueryInterface
     */
    public function fromSubquery(QueryInterface $subquery, string $alias = 'main_table'): QueryInterface
    {
        // 生成唯一的子查询标识
        $this->subquery_counter++;
        $subqueryId = 'subquery_' . $this->subquery_counter;
        
        // 确保子查询已经构建了 AST
        if ($subquery instanceof QueryAst) {
            // 构建子查询的 AST（如果还没有构建）
            if (empty($subquery->getAst()) || !isset($subquery->getAst()['action'])) {
                $subquery->prepareSql('select');
            }
            
            // 获取子查询的干净 AST（不包含方言特定的语法）
            $subqueryAst = $subquery->getAst();
            
            // 存储子查询 AST
            if (!isset($this->ast['subqueries'])) {
                $this->ast['subqueries'] = [];
            }
            $this->ast['subqueries'][$subqueryId] = $subqueryAst;
            
            // 更新主查询的 FROM 信息，标记为子查询
            if (!isset($this->ast['from'])) {
                $this->ast['from'] = [];
            }
            $this->ast['from']['is_subquery'] = true;
            $this->ast['from']['subquery_id'] = $subqueryId;
            $this->ast['from']['alias'] = $this->cleanAstValue($alias);
            
            // 更新表别名
            $this->table_alias = $alias;
            $this->table = ''; // 清空表名，因为使用的是子查询
        }
        
        return $this;
    }

    public function concat_like(string $fields, string $like_word): QueryInterface
    {
        return $this->where("CONCAT({$fields}) like '{$like_word}'");
    }

    public function concat(string $fields, string $alias_field): QueryInterface
    {
        return $this->fields("CONCAT({$fields}) as '{$alias_field}'");
    }

    public function group_concat(string $fields, string $concat_field = '', string $separator = 'json', string $order_by = ''): QueryInterface
    {
        if (!$this->group_by) {
            throw new DbException(__('group_by 不能为空！group_concat方法要求先使用->group()方法！'));
        }
        if ($order_by) {
            $order_by = " ORDER BY {$order_by}";
        }
        if (empty($concat_field)) {
            $concat_field = 'concat_field';
        }
        if ($separator == 'json') {
            $count_sql = "CONCAT('[', GROUP_CONCAT(
        JSON_OBJECT(";
            $fields = explode(',', $fields);
            foreach ($fields as $field) {
                $field_name = $field;
                if (str_contains($field, '.')) {
                    $field_name = substr($field, strpos($field, '.') + 1, strlen($field));
                }
                $count_sql .= "'{$field_name}', {$field}, ";
            }
            $count_sql = substr($count_sql, 0, -2) . ")
        {$order_by}
    ), ']') as {$concat_field}";
            $this->fields($this->fields . ',' . $count_sql);
        } else {
            $this->fields($this->fields . ',' . "GROUP_CONCAT({$fields} SEPARATOR '{$separator}' ) as {$concat_field}");
        }
        return $this;
    }

    public function insert(array $data, array|string $update_where_fields = [], string $update_fields = '', bool $ignore_primary_key = false): QueryInterface
    {
        if (empty($data)) {
            throw new DbException('插入数据不能为空！');
        }
        # 要更新的字段
        if ($update_fields) {
            if (is_string($update_fields)) {
                $this->insert_update_fields = explode(',', $update_fields);
            } else {
                $this->insert_update_fields = $update_fields;
            }
        }

        # 更新依据条件
        if (is_string($update_where_fields) and $update_where_fields) {
            $update_where_fields = explode(',', $update_where_fields);
        }
        if (is_array($update_where_fields)) {
            $this->insert_update_where_fields = $update_where_fields;
        }
        # 如果没有忽略主键，则需要添加主键
        if (empty($this->insert_update_where_fields) and !$ignore_primary_key) {
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
            # 倒序
            $this->insert_update_where_fields = array_reverse($this->insert_update_where_fields);
        }
        # 如果要更新的字段为空
        # 插入数据
        if (is_string(array_key_first($data))) {
            $this->insert['origin'][] = $data;
        } else {
            sort($data);
            $this->insert['origin'] = $data;
        }

        # 计算是否存在多条语句
        if (count($this->insert['origin']) > 1) {
            $this->batch = true;
        }

        if (count($this->insert)) {
            # 除去主键的联合查询字段
            $insert_have_not_identity_fields = $this->insert_update_where_fields;
            foreach ($insert_have_not_identity_fields as $insert_have_not_identity_field_key => $insert_have_not_identity_field) {
                if ($insert_have_not_identity_field == $this->identity_field) {
                    unset($insert_have_not_identity_fields[$insert_have_not_identity_field_key]);
                    break;
                }
            }
            # 需要插入的字段
            $insert_need_fields = array_merge($this->_unit_primary_keys, $this->insert_update_where_fields);
            $this->insert_need_fields = $insert_need_fields;
            # 检测要更新的字段主键对应值是否存在，如果存在且非数字，那么插入的数据认为是无法自增的，需要的字段要包含主键
            $first_insert_item = $this->insert['origin'][0] ?? [];
            $first_insert_item_keys = array_keys($first_insert_item);
            if (!isset($first_insert_item[$this->identity_field]) or is_numeric($first_insert_item[$this->identity_field])) {
                foreach ($insert_need_fields as $insert_need_field_key => $insert_need_field) {
                    if ($insert_need_field == $this->identity_field) {
                        unset($insert_need_fields[$insert_need_field_key]);
                        break;
                    }
                }
            }
            # 调整$this->insert_need_fields字段顺序
            foreach ($first_insert_item_keys as $first_insert_item_key_index => $first_insert_item_key) {
                $this->insert_need_fields[$first_insert_item_key_index] = $first_insert_item_key;
            }
            $this->insert_need_fields = array_unique($this->insert_need_fields);
            # 如果长度不一致报错
            if (count($this->insert_need_fields) != count($first_insert_item_keys)) {
                throw new Exception(__('插入数据和更新依据字段不匹配，请检查! 所需字段：%{1}，实际字段: %{2}', [implode(',', $this->insert_need_fields), implode(',', $first_insert_item_keys)]));
            }
            foreach ($first_insert_item as $f => $fv) {
                if (!in_array($f, $insert_need_fields)) {
                    $insert_need_fields[] = $f;
                }
            }
            # 区分更新或者插入（在 AST 构建阶段处理）
            foreach ($this->insert['origin'] as $item) {
                # 检测个数据是否有需要更新的字段以及更新依据字段的字段数据
                $item_fields = array_keys($item);
                foreach ($insert_need_fields as $insert_need_field) {
                    if (!in_array($insert_need_field, $item_fields)) {
                        throw new Exception(__('插入数据和更新依据字段不匹配，请检查! 所需字段：%{1}，实际字段: %{2}', [implode(',', $insert_need_fields), implode(',', $item_fields)]));
                    }
                }
                # 检测要更新的字段数据格式是否正确
                if (!empty($this->insert_update_fields)) {
                    foreach ($this->insert_update_fields as $insert_update_field) {
                        if (!in_array($insert_update_field, $item_fields)) {
                            throw new Exception(__('检测打算要更新依据字段不匹配，请检查! 打算更新字段：%{1}，实际字段: %{2}', [implode(',', $this->insert_update_fields), implode(',', $item_fields)]));
                        }
                    }
                }
                
                # AST 构建阶段：处理主键和更新依赖字段逻辑
                $has_primary_key = false;
                
                # 检查主键是否存在（包括单主键和联合主键）
                if (!empty($item[$this->identity_field])) {
                    $has_primary_key = true;
                } elseif (!empty($this->_unit_primary_keys)) {
                    # 检查联合主键是否都存在
                    $all_primary_keys_exist = true;
                    foreach ($this->_unit_primary_keys as $unit_key) {
                        if (empty($item[$unit_key])) {
                            $all_primary_keys_exist = false;
                            break;
                        }
                    }
                    if ($all_primary_keys_exist) {
                        $has_primary_key = true;
                    }
                }
                
                # 逻辑处理：
                # 1. 主键存在 → 一定是更新
                if ($has_primary_key) {
                    $this->insert['i_o_u'][] = $item;
                    continue;
                }
                
                # 2. 主键不存在 + 有更新依赖字段 → 检测重复
                if (!empty($insert_have_not_identity_fields)) {
                    # 检测数据是否重复（在数据数组内部检测，根据更新依赖字段）
                    $is_duplicate = $this->checkDuplicateByUpdateFields($item, $this->insert['origin'], $insert_have_not_identity_fields);
                    if ($is_duplicate) {
                        # 发现重复 → 报错
                        $duplicate_fields_str = implode(',', $insert_have_not_identity_fields);
                        $duplicate_values = [];
                        foreach ($insert_have_not_identity_fields as $field) {
                            $duplicate_values[] = $field . '=' . ($item[$field] ?? 'NULL');
                        }
                        throw new Exception(__('检测到重复数据！根据更新依赖字段 [%{1}] 检测到重复记录：%{2}', [$duplicate_fields_str, implode(', ', $duplicate_values)]));
                    } else {
                        # 不重复 → 继续更新操作
                        $this->insert['i_o_u'][] = $item;
                        continue;
                    }
                }
                
                # 3. 主键不存在 + 无更新依赖字段 → 批量插入
                $this->insert['insert'][] = $item;
                
                if (empty($this->insert_need_fields)) {
                    $this->insert_need_fields = $item_fields;
                }
            }
        }

        # 获取字段
        $fields = '(';
        $first_insert = null;
        if (!empty($this->insert['insert'])) {
            $first_insert = $this->insert['insert'][array_key_first($this->insert['insert'])];
        }
        $special_fields = [
            'order',
            'key',
            'table',
            'fields',
        ];

        if (!empty($first_insert)) {
            $fields_keys = array_keys($first_insert);
            foreach ($fields_keys as &$fields_key) {
                if (in_array($fields_key, $special_fields)) {
                    $fields_key = '`' . $fields_key . '`';
                }
            }
            $fields .= implode(',', $fields_keys);
        } else {
            foreach ($this->insert_need_fields as &$fields_key) {
                if (in_array($fields_key, $special_fields)) {
                    $fields_key = '`' . $fields_key . '`';
                }
            }
            $fields .= implode(',', $this->insert_need_fields);
        }
        $fields = rtrim($fields, ',') . ')';
        $origin_fields = $this->fields;
        $this->fields = $fields;
        // 更新 AST
        $this->updateAstInsert($this->insert);
        $this->fetch_type = __FUNCTION__;
        $this->prepareSql(__FUNCTION__);
        $this->fields = $origin_fields;
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
        // 更新 AST
        $this->updateAstUpdate($this->single_updates, $this->updates);
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
        // 更新 AST
        $this->updateAstTable($this->table, $this->table_alias);
        return $this;
    }

    public function join(string $table, string $condition, string $type = 'left'): QueryInterface
    {
        if (1 === count(func_get_args())) {
            $type = 'inner';
        }
        $this->joins[] = [$table, $condition, $type];
        // 更新 AST
        $this->updateAstJoin($table, $condition, $type);
        return $this;
    }

    public function fields(string|array $fields): QueryInterface
    {
        // 处理数组参数
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
            foreach ($fields as &$field) {
                $field = self::parserFiled($field);
            }
            $this->fields = implode(',', $fields);
        }
        // 更新 AST
        $this->updateAstFields($this->fields);
        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): QueryInterface
    {
        $where_logic = trim(strtoupper($where_logic));
        $condition = trim(strtoupper($condition));
        $array_where_logic_type = trim(strtoupper($array_where_logic_type));
        if (is_array($field)) {
            foreach ($field as $f_key => $where_array) {
                # 处理字段，都要加`
                $f_key = self::parserFiled($f_key);
                if (!is_array($where_array)) {
                    $value = $where_array;
                    $where_array = [];
                    $where_array[0] = $f_key;
                    $where_array[1] = '=';
                    $where_array[2] = $value;
                    $where_array[3] = $array_where_logic_type;
                } elseif (2 === count($where_array)) {# 处理两个元素数组
                    $where_array[2] = $where_array[1];
                    $where_array[1] = '=';
                }

                # 检测条件数组 下角标 必须为数字
                $this->checkWhereArray($where_array, $f_key);
                # 检测条件数组 检测第二个元素必须是限定的 条件操作符
                $this->checkConditionString($where_array);
                $this->wheres[] = $where_array;
                // 更新 AST
                $this->updateAstWhere($where_array);
            }
        } else {
            if (is_array($value)) {
                if ($condition === 'IN' || $condition === 'NOT IN') {
                    if (empty($value)) {
                        throw new Exception(__('IN 条件无法匹配空值数组。数组值：[]'));
                    }
                    $where_array = [$field, $condition, $value, $where_logic];
                    # 检测条件数组 下角标 必须为数字
                    $this->checkWhereArray($where_array, 0);
                    # 检测条件数组 检测第二个元素必须是限定的 条件操作符
                    $this->checkConditionString($where_array);
                    $this->wheres[] = $where_array;
                    // 更新 AST
                    $this->updateAstWhere($where_array);
                } else {
                    $last_key = array_key_last($value);
                    foreach ($value as $kv => $item) {
                        if ($last_key === $kv) {
                            $array_where_logic_type = $where_logic;
                        }
                        # 判断字段是否为同一个
                        $where_array = [$field, $condition, $item, $array_where_logic_type];
                        # 检测条件数组 下角标 必须为数字
                        $this->checkWhereArray($where_array, 0);
                        # 检测条件数组 检测第二个元素必须是限定的 条件操作符
                        $this->checkConditionString($where_array);
                        $this->wheres[] = $where_array;
                        // 更新 AST
                        $this->updateAstWhere($where_array);
                    }
                }
            } else {
                // 🔧 支持子查询：如果 $value 是 QueryInterface 实例，将其作为子查询处理
                if ($value instanceof QueryInterface) {
                    $this->addSubqueryToWhere($field, $value, $condition, $where_logic);
                } else {
                    $where_array = [self::parserFiled($field), $condition, $value, $where_logic];
                    # 检测条件数组 下角标 必须为数字
                    $this->checkWhereArray($where_array, 0);
                    # 检测条件数组 检测第二个元素必须是限定的 条件操作符
                    $this->checkConditionString($where_array);
                    $this->wheres[] = $where_array;
                    // 更新 AST
                    $this->updateAstWhere($where_array);
                }
            }
        }
        return $this;
    }

    /**
     * 原生 SQL 条件
     *
     * 用于无法通过结构化 where() 表达的复杂条件（如 OR 分组、SQL 函数、字段对比等）。
     * 条件会被括号包裹后直接嵌入 WHERE 子句，不做字段名引用处理。
     *
     * @param string $sql 原生 SQL 条件表达式
     * @param string $where_logic 与下一个 where 条件的连接符，默认 AND
     * @return QueryInterface
     */
    public function whereRaw(string $sql, string $where_logic = 'AND'): QueryInterface
    {
        // 单元素数组在 buildWheres 中被作为原生 SQL 处理（case 1），默认 AND 连接
        $this->wheres[] = [$sql];
        return $this;
    }

    /**
     * 添加子查询到 WHERE 条件
     * 
     * @param string $field 字段名
     * @param QueryInterface $subquery 子查询对象
     * @param string $condition 条件操作符
     * @param string $where_logic WHERE 逻辑连接符
     * @return void
     */
    protected function addSubqueryToWhere(string $field, QueryInterface $subquery, string $condition, string $where_logic): void
    {
        // 生成唯一的子查询标识
        $this->subquery_counter++;
        $subqueryId = 'subquery_' . $this->subquery_counter;
        
        // 确保子查询已经构建了 AST
        if ($subquery instanceof QueryAst) {
            // 构建子查询的 AST（如果还没有构建）
            if (empty($subquery->getAst()) || !isset($subquery->getAst()['action'])) {
                $subquery->prepareSql('select');
            }
            
            // 获取子查询的干净 AST（不包含方言特定的语法）
            $subqueryAst = $subquery->getAst();
            
            // 存储子查询 AST
            if (!isset($this->ast['subqueries'])) {
                $this->ast['subqueries'] = [];
            }
            $this->ast['subqueries'][$subqueryId] = $subqueryAst;
            
            // 创建 WHERE 条件数组，标记为子查询
            $where_array = [
                self::parserFiled($field),
                $condition,
                ['is_subquery' => true, 'subquery_id' => $subqueryId],
                $where_logic
            ];
            
            # 检测条件数组 下角标 必须为数字
            $this->checkWhereArray($where_array, 0);
            # 检测条件数组 检测第二个元素必须是限定的 条件操作符
            $this->checkConditionString($where_array);
            $this->wheres[] = $where_array;
            // 更新 AST
            $this->updateAstWhere($where_array);
        }
    }

    /**
     * @DESC          # 累减
     * @param string $field
     * @param float|int $value
     * @return QueryInterface
     */
    public function dec(string $field, float|int $value = 1): QueryInterface
    {
        $this->dec_inc_updates[$field] = '-' . $value;
        if (empty($this->fetch_type)) {
            $this->fetch_type = 'update';
            $this->prepareSql($this->fetch_type);
        }
        return $this;
    }

    /**
     * @DESC          # 累加
     * @param string $field
     * @param float|int $value
     * @return QueryInterface
     */
    public function inc(string $field, float|int $value = 1): QueryInterface
    {
        $this->dec_inc_updates[$field] = '+' . $value;
        if (empty($this->fetch_type)) {
            $this->fetch_type = 'update';
            $this->prepareSql($this->fetch_type);
        }
        return $this;
    }

    public function limit($size, $offset = 0): QueryInterface
    {
        $this->limit = " LIMIT $offset,$size";
        // 更新 AST
        $this->updateAstLimit($this->limit);
        return $this;
    }

    public function page(int $page = 1, int $pageSize = 20): QueryInterface
    {
        $offset = 0;
        if (1 < $page) {
            $offset = $pageSize * ($page - 1) /*+ 1*/
            ;
        }
        $this->limit = " LIMIT $offset,$pageSize";
        $this->pagination['page'] = $page;
        // 更新 AST
        $this->updateAstLimit($this->limit);
        return $this;
    }

    public function pagination(int $page = 1, int $pageSize = 20, array $params = [], int $max_limit = 1000, int $total = 0): QueryInterface
    {
        if ($pageSize > $max_limit) {
            throw new Exception(__('分页超过每页限制大小！限制每页大小：%{1}', $max_limit));
        }
        $this->pagination['page'] = $page;
        $this->pagination['pageSize'] = $pageSize;
        if ($params) {
            $this->pagination = array_merge($this->pagination, $params);
        }
        $this->pagination['params'] = $params;
        if (!$total) {
            $query = clone $this;
            $total = $query->total();
        }
        $this->page($page, $pageSize);
        $this->pagination['totalSize'] = $total;
        $lastPage = intval($total / $pageSize);
        if ($total % $pageSize) {
            $lastPage += 1;
        }
        $this->pagination['lastPage'] = $lastPage;
        return $this;
    }

    public function order(string $field = '', string $sort = 'DESC'): QueryInterface
    {
        if (empty($field)) {
            $field = 'main_table.' . $this->identity_field;
        }
        $field = $this->parserFiled($field);
        if ('key' == strtolower($field) || 'value' == strtolower($field) || 'key' == strtolower($sort)) {
            $field = '`' . $field . '`';
        }
        $this->order[$field] = $sort;
        // 更新 AST
        $this->updateAstOrder($field, $sort);
        return $this;
    }

    public function group(string $fields): QueryInterface
    {
        $this->group_by = 'GROUP BY ' . $fields;
        // 更新 AST
        $this->updateAstGroup($this->group_by);
        return $this;
    }

    public function having(string $having): QueryInterface
    {
        $this->having = 'having ' . $having;
        // 更新 AST
        $this->updateAstHaving($this->having);
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

    public function total(string $field = '*', string $alias = 'total_count'): int
    {
        // 防御性检查：fetch_type 可能因 WLS 常驻内存未正确重置而残留为 'query'
        // 当 fetch_type == 'query' 但 sql 已被 clear()/reset() 清空时，
        // 直接构建子查询会产生 "FROM () as total_records" 语法错误
        $useRawQueryPath = ($this->fetch_type == 'query' && !empty(trim($this->sql)));
        
        if ($useRawQueryPath) {
            $this->sql = Tool::rm_sql_limit($this->sql);// 去除限制
            $this->sql = "SELECT COUNT({$field}) AS $alias FROM (" . $this->sql . ") as total_records";
            $this->query($this->sql);
        } else {
            # 聚合查询
            if ($this->group_by) {
                $this->prepareSql('select');
                $preSql = $this->getSql();
                // 防御性检查：子查询 SQL 不能为空
                if (!empty(trim($preSql))) {
                    $sql = "select count({$field}) as `{$alias}` from ({$preSql}) as total_records";
                    $this->sql = $sql;
                    $this->query($this->sql);
                } else {
                    // group_by 存在但 SQL 编译为空，降级为简单 COUNT
                    $savedGroupBy = $this->group_by;
                    $this->group_by = '';
                    $savedOrder = $this->order;
                    $this->order = [];
                    
                    $this->fields = "count({$field}) as `{$alias}`";
                    $this->limit(1, 0);
                    $this->prepareSql('find');
                    
                    $this->order = $savedOrder;
                    $this->group_by = $savedGroupBy;
                }
            } else {
                // 临时保存 ORDER BY，因为 COUNT 查询不需要 ORDER BY
                // PostgreSQL 要求：使用聚合函数时，ORDER BY 中的列必须出现在 GROUP BY 中
                $savedOrder = $this->order;
                $this->order = [];
                
                $this->fields = "count({$field}) as `{$alias}`";
                $this->limit(1, 0);
                $this->prepareSql('find');
                
                // 恢复 ORDER BY（不影响原始查询对象）
                $this->order = $savedOrder;
            }
        }

        $this->fetch_type = 'find';
        $result = $this->fetch();
        if (isset($result[$alias])) {
            $result = $result[$alias];
        }
        return intval($result);
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

    public function query(string $sql): QueryInterface
    {
        $sql = self::formatSql($sql);
        $this->reset();
        $this->sql = $sql;
        $this->fetch_type = __FUNCTION__;
        $this->PDOStatement = $this->getConnectionInterface()->prepare($sql);
        return $this;
    }

    public function additional(string $additional_sql): QueryInterface
    {
        $this->additional_sql = $additional_sql;
        // 更新 AST
        $this->updateAstExtra($this->additional_sql);
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        if ($this->PDOStatement === null) {
            return false;
        }
        
        // Development SQL logging - log SQL with actual values  
        if (Env::get('log.dev_sql.enabled', false)) {
            $log_file = Env::get('log.dev_sql.file', 'dev_sql');
            // Get SQL with bound values replaced
            $sqlWithValues = $this->getSqlWithBounds($this->sql);
            Env::log($log_file, $sqlWithValues, 'QUERY', true, true, 0);
        }
        
        // Database query logging - only if enabled
        if (Env::get('log.db.enabled', false)) {
            $file = Env::get('log.db.file', 'db');
            // Use compact standard format: [timestamp] [QUERY] source - SQL
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
        if ($this->batch and $this->fetch_type == 'insert') {
            // 检查 SQL 是否包含 RETURNING 子句（PostgreSQL）
            $hasReturning = stripos($this->sql, 'RETURNING') !== false;
            
                if ($hasReturning) {
                // 如果使用 RETURNING，需要使用 prepare/execute 来获取结果
                $this->PDOStatement = $this->getConnectionInterface()->prepare($this->sql);
                $this->PDOStatement->execute($this->bound_values);
                $origin_data = $this->PDOStatement->fetchAll(PDO::FETCH_ASSOC);
                // 批量插入时，返回最后一个插入的 ID
                if (!empty($origin_data) && is_array($origin_data)) {
                    $lastRow = end($origin_data);
                    $result = $lastRow[$this->identity_field] ?? reset($lastRow);
                } else {
                    $result = $this->getConnectionInterface()->lastInsertId();
                }
            } else {
                // 没有 RETURNING，使用 exec
                $affected = $this->getConnectionInterface()->execute($this->getSql());
                $result = $affected >= 0 ? $this->getConnectionInterface()->lastInsertId() : false;
            }
            $origin_data = $result;
            $this->reset();
        } else {
            // 单条语句，使用 prepare/execute
            try {
                $this->PDOStatement = $this->getConnectionInterface()->prepare($this->sql);
                $this->PDOStatement->execute($this->bound_values);
            } catch (\PDOException $e) {
                // 其他错误继续抛出
                throw $e;
            }
            // 检查是否有多个结果集
            $origin_data = [];
            do {
                $fetched = $this->PDOStatement->fetchAll(PDO::FETCH_ASSOC);
                $origin_data[] = $fetched;
            } while ($this->PDOStatement->nextRowset());
            if (count($origin_data) == 1) {
                $origin_data = $origin_data[0];
            }
        }
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
                    // 检查是否是 RETURNING 的结果
                    // RETURNING 的结果通常是数组，包含插入的 ID
                    $firstRow = null;
                    if (isset($data[0]) && is_array($data[0])) {
                        // 多行结果（批量插入）
                        $firstRow = $data[0];
                    } elseif (is_array($data) && !isset($data[0]) && !empty($data)) {
                        // 单行结果（关联数组）
                        $firstRow = $data;
                    } elseif (count($data) === 1 && is_array($data[0])) {
                        // 单行结果（索引数组）
                        $firstRow = $data[0];
                    }
                    
                    // 从 RETURNING 结果中获取 ID
                    if ($firstRow && $this->identity_field) {
                        // 尝试从结果中获取 identity_field
                        if (isset($firstRow[$this->identity_field])) {
                            $result = $firstRow[$this->identity_field];
                        } else {
                            // 如果没有找到 identity_field，尝试获取第一个值
                            $result = reset($firstRow);
                        }
                    } else {
                        // 尝试使用 lastInsertId
                        $lastId = $this->getConnectionInterface()->lastInsertId();
                        $result = $lastId !== false ? $lastId : (reset($data) ?? null);
                    }
                } else {
                    // 没有 RETURNING 结果，使用 lastInsertId
                    $lastId = $this->getConnectionInterface()->lastInsertId();
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
                // 🔧 修复 PostgreSQL UPDATE 返回 false 的问题
                // 如果使用了 RETURNING 子句（PostgreSQL），$data 可能包含返回的数据
                // 如果没有 RETURNING，$data 是空数组，应该使用 rowCount() 来判断
                if (is_array($data) && empty($data)) {
                    // 没有 RETURNING 结果，使用 rowCount() 判断受影响的行数
                    $rowCount = $this->PDOStatement ? $this->PDOStatement->rowCount() : 0;
                    $result = $rowCount > 0;
                } else {
                    // 有 RETURNING 结果，说明更新成功
                    $result = !empty($data);
                }
                break;
            default:
                throw new Exception(__('错误的获取类型。fetch之前必须有操作函数，操作函数包含（find,update,delete,select,query,insert,find）函数。当前类型：%{1}', $this->fetch_type));
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
        //        $this->clear();
        $this->clearQuery();
        if ($this->table_alias !== 'main_table') $this->alias('main_table');
        //        $this->reset();
        return $result;
    }

    public function fetchArray(): array
    {
        return $this->fetch() ?: [];
    }


    public function clear(string $type = ''): QueryInterface
    {
        if ($type) {
            $attr_var_name = $type;
            if (DEV && !isset(self::init_vars[$attr_var_name])) {
                $this->exceptionHandle(__('不支持的清理类型：%{1} 支持的初始化类型：%{2}', [$attr_var_name, var_export(self::init_vars, true)]));
            }
            $this->$attr_var_name = self::init_vars[$attr_var_name];
        } else {
            $this->reset();
        }
        $this->_unit_primary_keys = [];
        $this->batch = false;
        return $this;
    }


    public function clearQuery(string $type = ''): QueryInterface
    {
        if ($type) {
            $attr_var_name = $type;
            if (DEV && !isset(self::init_vars[$attr_var_name])) {
                $this->exceptionHandle(__('不支持的清理类型：%{1} 支持的初始化类型：%{2}', [$attr_var_name, var_export(self::init_vars, true)]));
            }
            $this->$attr_var_name = self::init_vars[$attr_var_name];
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
        $this->_unit_primary_keys = [];
        $this->PDOStatement = null;
        $this->batch = false;
        return $this;
    }

    public function beginTransaction(): void
    {
        $this->getConnectionInterface()->beginTransaction();
    }

    public function rollBack(): void
    {
        if($this->getConnectionInterface()->inTransaction()) {
            $this->getConnectionInterface()->rollBack();
        }
    }

    public function commit(): void
    {
        $this->getConnectionInterface()->commit();
    }

    /**
     * 归档数据
     *
     * @param string $period ['all'=>'全部','today'=>'今天','yesterday'=>'昨天','current_week'=>'这周','near_week'=>'最近一周','last_week'=>'上周','near_month'=>'近三十天','current_month'=>'本月','last_month'=>'上一月','quarter'=>'本季度','last_quarter'=>'上个季度','current_year'=>'今年','last_year'=>'上一年']
     * @param string $field [默认按照'create_time'字段归档，可指定归档字段]
     *
     * @return $this
     * @throws Exception
     */
    public function period(string $period, string $field = 'create_time'): static
    {
        # 提取$period中包含的数字
        $period_number = preg_replace('/\D/', '', $period);
        if ($period_number) {
            $period = str_replace($period_number, '{number}', $period);
        }
        $period_number = intval($period_number);

        if (!is_int(strpos($field, '.'))) {
            $field = $this->table_alias . '.' . $field;
        }
        switch ($period) {
            case 'all':
                break;
            case 'today':
                #今天
                $this->where("TO_DAYS({$field})=TO_DAYS(NOW())");
                break;
            case 'yesterday':
            case 'last_day':
                #昨天
                $this->where("DATE({$field}) = DATE(CURDATE()-1)");
                break;
            case 'the_day_{number}_days_ago':
                #提取数字指定几天前的那一天
                $this->where("DATE({$field}) = DATE_SUB(CURDATE(), INTERVAL {$period_number} DAY)");
                break;
            case 'current_week':
                #查询当前这周的数据
                $this->where("YEARWEEK(DATE_FORMAT({$field},'%Y-%m-%d')) = YEARWEEK(NOW())");
                break;
            case 'near_week':
                #近7天
                $this->where("DATE_SUB(CURDATE(), INTERVAL 7 DAY) <= DATE({$field})");
                break;
            case 'last_week':
                #查询上周的数据
                $this->where("YEARWEEK(DATE_FORMAT({$field},'%Y-%m-%d')) =YEARWEEK(NOW())-1");
                break;
            case 'the_week_{number}_weeks_ago':
                #提取数字指定几周之前的那个周
                $this->where("YEARWEEK(DATE_FORMAT({$field},'%Y-%m-%d')) =YEARWEEK(NOW())-{$period_number}");
                break;
            case 'near_month':
                #近30天
                $this->where("DATE_SUB(CURDATE(), INTERVAL 30 DAY) <= DATE({$field})");
                break;
            case 'current_month':
                # 本月
                $this->where("DATE_FORMAT({$field},'%Y%m') =DATE_FORMAT(CURDATE(),'%Y%m')");
                break;
            case 'last_month':
                #上一月
                $this->where("PERIOD_DIFF(DATE_FORMAT( NOW(),'%Y%m'),DATE_FORMAT({$field},'%Y%m')) =1");
                break;
            case 'the_month_{number}_months_ago':
                #提取数字指定几个月份之前的月份
                $this->where("PERIOD_DIFF(DATE_FORMAT( NOW(),'%Y%m'),DATE_FORMAT({$field},'%Y%m')) ={$period_number}");
                break;
            case 'quarter':
                #查询本季度数据
                $this->where("QUARTER({$field})=QUARTER(NOW())");
                break;
            case 'last_quarter':
                #查询上季度数据
                $this->where("QUARTER({$field})=QUARTER(DATE_SUB(NOW(),INTERVAL 1 QUARTER))");
                break;
            case 'the_quarter_{number}_quarters_ago':
                #提取数字指定几个季度前那个季度
                $this->where("QUARTER({$field})=QUARTER(DATE_SUB(NOW(),INTERVAL {$period_number} QUARTER))");
                break;
            case 'current_year':
                #查询本年数据
                $this->where("YEAR({$field})=YEAR(NOW())");
                break;
            case 'last_year':
                #查询上年数据
                $this->where("YEAR({$field})=YEAR(DATE_SUB(NOW(),INTERVAL 1 YEAR))");
                break;
            case 'the_year_{number}_years_ago':
                #提取数字指定几年前的那年
                $this->where("YEAR({$field})=YEAR(DATE_SUB(NOW(),INTERVAL {$period_number} YEAR))");
                break;
            default:
        }
        return $this;
    }

    /**
     * 获取数据库连接
     * 各个适配器必须实现此方法，返回 PDO 连接对象
     * 
     * @return PDO 数据库连接对象
     */
    /**
     * @deprecated 请使用 getConnectionInterface() 获取连接并调用其方法，后续版本可能移除
     */
    abstract public function getLink(): PDO;

    /**
     * 准备 SQL 语句
     * 各个适配器必须实现此方法，将 AST 编译为对应的 SQL 方言
     * 
     * @param string $action 操作类型：'select'|'insert'|'update'|'delete'|'find'
     */
    abstract protected function prepareSql(string $action): void;

    /**
     * 构建当前查询的小型 AST 结构，尽量保持与属性一一对应。
     * 这是 QueryAst 的核心方法，负责将查询属性转换为结构化描述。
     * 如果 AST 已经通过 Trait 方法实时更新，则只需要设置 action；否则从属性中构建完整的 AST。
     * 
     * 在构建 AST 时，会解析索引排序信息，用于优化查询性能。
     */
    protected function buildAst(string $action): void
    {
        // 解析索引排序信息（在构建 AST 时处理）
        $this->parseIndexSortInfo();
        
        // 如果 AST 已经存在且包含必要信息，只需要更新 action
        if (!empty($this->ast) && isset($this->ast['from'])) {
            $this->ast['action'] = $action;
            // 确保所有部分都已更新（从属性同步，以防有遗漏）
            // 🔧 比较时使用清理后的值，确保 AST 中的值是干净的
            $cleanedTable = $this->cleanAstValue($this->table);
            if (!isset($this->ast['from']['table']) || $this->ast['from']['table'] !== $cleanedTable) {
                $this->updateAstTable($this->table, $this->table_alias);
            }
            $cleanedFields = $this->cleanAstFields($this->fields);
            if (!isset($this->ast['select']['fields']) || $this->ast['select']['fields'] !== $cleanedFields) {
                $this->updateAstFields($this->fields);
            }
            if (!isset($this->ast['joins']) || $this->ast['joins'] !== $this->joins) {
                // 如果 joins 不匹配，重新构建
                $this->ast['joins'] = $this->joins;
            }
            if (!isset($this->ast['where']) || $this->ast['where'] !== $this->wheres) {
                $this->ast['where'] = $this->wheres;
            }
            if (!isset($this->ast['order']) || $this->ast['order'] !== $this->order) {
                $this->ast['order'] = $this->order;
            }
            if (!isset($this->ast['group']) || $this->ast['group'] !== $this->group_by) {
                $this->ast['group'] = $this->group_by;
            }
            if (!isset($this->ast['having']) || $this->ast['having'] !== $this->having) {
                $this->ast['having'] = $this->having;
            }
            if (!isset($this->ast['limit']) || $this->ast['limit'] !== $this->limit) {
                $this->ast['limit'] = $this->limit;
            }
            if (!isset($this->ast['extra']) || $this->ast['extra'] !== $this->additional_sql) {
                $this->ast['extra'] = $this->additional_sql;
            }
            if (!isset($this->ast['insert']) || $this->ast['insert'] !== $this->insert) {
                $this->ast['insert'] = $this->insert;
            }
            if (!isset($this->ast['update']) ||
                $this->ast['update']['single'] !== $this->single_updates ||
                $this->ast['update']['batch'] !== $this->updates) {
                $this->ast['update'] = [
                    'single' => $this->single_updates,
                    'batch'  => $this->updates,
                ];
            }
            if (!isset($this->ast['dec_inc_updates']) || $this->ast['dec_inc_updates'] !== $this->dec_inc_updates) {
                $this->ast['dec_inc_updates'] = $this->dec_inc_updates;
            }
            // 添加索引排序信息到 AST
            if (!isset($this->ast['index_sort_keys']) || $this->ast['index_sort_keys'] !== $this->_index_sort_keys) {
                $this->ast['index_sort_keys'] = $this->_index_sort_keys;
            }
            if (!isset($this->ast['unit_primary_keys']) || $this->ast['unit_primary_keys'] !== $this->_unit_primary_keys) {
                $this->ast['unit_primary_keys'] = $this->_unit_primary_keys;
            }
            $this->ast['action'] = $action;
        } else {
            // AST 不存在或为空，从属性中构建完整的 AST
            // 🔧 确保 AST 中的值是干净的，不包含方言特定的引号等语法信息
            $this->ast = [
                'action' => $action,
                'from'   => [
                    'table' => $this->cleanAstValue($this->table),
                    'alias' => $this->cleanAstValue($this->table_alias),
                ],
                'select' => [
                    'fields' => $this->cleanAstFields($this->fields),
                ],
                'joins'  => $this->joins, // JOIN 信息在编译阶段处理
                'where'  => $this->wheres, // WHERE 条件在编译阶段处理
                'group'  => $this->group_by ? $this->cleanAstFields($this->group_by) : '',
                'having' => $this->having, // HAVING 在编译阶段处理
                'order'  => $this->order, // ORDER BY 在编译阶段处理
                'limit'  => $this->limit,
                'extra'  => $this->additional_sql,
                'insert' => $this->insert,
                'update' => [
                    'single' => $this->single_updates,
                    'batch'  => $this->updates,
                ],
                'dec_inc_updates' => $this->dec_inc_updates,
                'index_sort_keys' => $this->_index_sort_keys,
                'unit_primary_keys' => $this->_unit_primary_keys,
            ];
        }
    }

    /**
     * 解析索引排序信息
     * 在 AST 构建阶段处理索引排序，用于优化查询性能
     */
    protected function parseIndexSortInfo(): void
    {
        // 如果设置了索引排序键，重新排序 where 条件以优化查询
        if (!empty($this->_index_sort_keys)) {
            $this->reorderWhereByIndexes();
        }
    }

    /**
     * 重新排序 where 条件（按索引优化）
     * 这是 AST 操作，应该在顶层 Query 中实现
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
     * 这是 AST 操作，应该在顶层 Query 中实现
     */
    protected function normalizeFieldName(string $field): string
    {
        return strtolower(trim($field, '`"[]'));
    }

    /**
     * 根据更新依赖字段检测数据数组内部是否重复
     * 在 AST 构建阶段使用，用于判断批量插入/更新数据中是否存在重复项
     * 
     * @param array $current_item 当前要检查的数据项
     * @param array $all_items 所有数据项数组（一维或二维数组）
     * @param array $update_dependency_fields 更新依赖字段数组
     * @return bool 如果数据重复返回 true，否则返回 false
     */
    protected function checkDuplicateByUpdateFields(array $current_item, array $all_items, array $update_dependency_fields): bool
    {
        if (empty($update_dependency_fields) || empty($all_items)) {
            return false;
        }
        
        # 构建当前项的依赖字段值签名（用于比较）
        $current_signature = $this->buildDependencySignature($current_item, $update_dependency_fields);
        if ($current_signature === null) {
            # 如果当前项的依赖字段不完整，无法检测，认为不重复
            return false;
        }
        
        # O(n)：先构建除当前项外所有项的签名集合，再判断当前签名是否在其中
        $other_signatures = [];
        foreach ($all_items as $other_item) {
            if (!is_array($other_item) || $other_item === $current_item) {
                continue;
            }
            $other_signature = $this->buildDependencySignature($other_item, $update_dependency_fields);
            if ($other_signature !== null) {
                $other_signatures[$other_signature] = true;
            }
        }
        return isset($other_signatures[$current_signature]);
    }

    /**
     * 构建依赖字段值签名
     * 用于比较两个数据项的更新依赖字段值是否相同
     * 
     * @param array $item 数据项
     * @param array $update_dependency_fields 更新依赖字段数组
     * @return string|null 签名字符串，如果依赖字段不完整返回 null
     */
    protected function buildDependencySignature(array $item, array $update_dependency_fields): ?string
    {
        $signature_parts = [];
        
        foreach ($update_dependency_fields as $field) {
            if (!isset($item[$field])) {
                # 如果依赖字段不存在，返回 null 表示无法构建签名
                return null;
            }
            
            # 将字段值转换为字符串并加入签名
            $value = $item[$field];
            if (is_array($value) || is_object($value)) {
                $value = serialize($value);
            } else {
                $value = (string)$value;
            }
            $signature_parts[] = $field . ':' . $value;
        }
        
        # 使用分隔符连接所有签名部分，确保唯一性
        return implode('|', $signature_parts);
    }

    /**
     * 获取 AST（用于调试和测试）
     */
    public function getAst(): array
    {
        return $this->ast;
    }

    public function getSql(bool $format = false): string
    {
        $real_sql = $this->sql;
        foreach ($this->bound_values as $where_key => $wheres_value) {
            if (is_string($wheres_value)) {
                // 连接可能在异常路径下不可用（如 catch 块拼接错误消息），
                // 此时用简单引号包裹代替 PDO::quote()，仅用于日志/调试
                if ($this->connection !== null) {
                    try {
                        $wheres_value = $this->getConnectionInterface()->quote($wheres_value);
                    } catch (\Throwable) {
                        $wheres_value = "'" . addslashes($wheres_value) . "'";
                    }
                } else {
                    $wheres_value = "'" . addslashes($wheres_value) . "'";
                }
            }
            $real_sql = str_replace($where_key, (string)$wheres_value, $real_sql);
        }
        if ($format) {
            return \SqlFormatter::format($real_sql);
        }
        return $real_sql;
    }

    /**
     * 检测 SQL 是否包含多个语句（排除字符串中的分号和末尾的分号）
     */
    protected function hasMultipleSqlStatements(string $sql): bool
    {
        // 移除注释
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // 移除首尾空白
        $sql = trim($sql);
        
        // 移除末尾的分号（单条语句末尾的分号是允许的）
        $sql = rtrim($sql, ';');
        
        // 如果移除末尾分号后为空，说明只有一条语句
        if (empty(trim($sql))) {
            return false;
        }
        
        // 匹配不在引号内的分号（这些是真正的语句分隔符）
        // 使用更精确的正则表达式：匹配分号，但排除在单引号或双引号内的分号
        $pattern = '/;(?=(?:[^\'"]*+(?:(?:\'[^\']*+\')|(?:"[^"]*+"))*+)*+$)/';
        $matches = preg_match_all($pattern, $sql);
        
        // 如果找到分号，说明有多个语句
        return $matches > 0;
    }

    public function truncate(string $backup_file = '', string $table = ''): static
    {
        if (empty($table)) {
            $table = $this->table;
        }
        if (empty($table)) {
            throw new Exception(__('请先指定要操作的表，表名不能为空!'));
        }
        $this->backup($backup_file, $table);
        # 清理表
        $PDOStatement = $this->getConnectionInterface()->prepare("TRUNCATE TABLE $table");
        $PDOStatement->execute();
        return $this;
    }

    public function backup(string $backup_file = '', string $table = ''): static
    {
        if (empty($table)) {
            $table = $this->table;
        }
        if (empty($table)) {
            throw new Exception(__('请先指定要操作的表，表名不能为空!'));
        }
        // 获取表的创建语句
        $PDOStatement = $this->getConnectionInterface()->prepare("SHOW CREATE TABLE $table");
        $PDOStatement->execute();
        $createTableResult = $PDOStatement->fetchAll(PDO::FETCH_ASSOC);
        $createTableSql = $createTableResult[0]['Create Table'];
        $createTableSql = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $createTableSql);
        // 定义备份文件路径和名称
        if (empty($backup_file)) {
            $originTable = str_replace('`', '', $table);
            $originTable = explode('.', $originTable) ?: [$table];
            $originTable = end($originTable);
            // 使用 Y-m-d_H-i-s 格式避免 Windows 文件名中的冒号问题
            $backupFile = Env::backup_dir . 'db' . DS . $table . DS . $originTable . '_' . date('Y-m-d_H-i-s') . '.sql';
        } else {
            if (!str_starts_with($backup_file, BP)) {
                $backupFile = BP . $backup_file;
            } else {
                $backupFile = $backup_file;
            }
        }
        if (!is_dir(dirname($backupFile))) {
            mkdir(dirname($backupFile), 0777, true);
        }
        // 将表的创建语句写入备份文件
        $backupFile = str_replace('\\', DS, $backupFile);
        $backupFile = str_replace('/', DS, $backupFile);
        $backupFile = str_replace('//', DS, $backupFile);
        $this->backup_file = $backupFile;
        $file = fopen($backupFile, 'w');
        fwrite($file, "-- $table 建表语句" . PHP_EOL);
        fwrite($file, $createTableSql . ';' . PHP_EOL);
        // 获取表的数据并写入备份文件
        $PDOStatement = $this->getConnectionInterface()->prepare("SELECT * FROM $table");
        $PDOStatement->execute();
        $results = $PDOStatement->fetchAll(PDO::FETCH_ASSOC);
        fwrite($file, PHP_EOL);
        fwrite($file, "-- $table 数据 " . PHP_EOL);
        foreach ($results as $result) {
            # 单引号转义
            foreach ($result as $key => $item) {
                if (is_string($item)) {
                    $result[$key] = str_replace("'", "\\'", $item);
                }
            }
            $values = implode("','", array_values($result));
            fwrite($file, "INSERT INTO $table VALUES ('$values');" . PHP_EOL);
        }
        // 关闭备份文件和数据库连接
        fclose($file);
        return $this;
    }
}
