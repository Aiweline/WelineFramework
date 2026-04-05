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
use Weline\Framework\Database\Compiler\PgsqlCompiler;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\SqlTrait;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Util\SelectFieldListSplitter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;

/**
 * PostgreSQL 查询构建器
 * 
 * 继承自 QueryAst 的方法：
 * @method void reorderWhereByIndexes() 重新排序 where 条件（按索引优化）
 * @method void buildAst(string $action) 构建 AST 结构
 * @method string normalizeFieldName(string $field) 规范化字段名
 * 
 * @see \Weline\Framework\Database\Connection\Api\Sql\QueryAst
 */
abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\QueryAst
{
    use SqlTrait;

    /**
     * 规范化字段名（继承自 QueryAst）
     * 移除引号并转换为小写
     * 
     * @param string $field 字段名
     * @return string 规范化后的字段名
     */
    protected function normalizeFieldName(string $field): string
    {
        // 调用父类方法
        return parent::normalizeFieldName($field);
    }

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
        $this->fetch_type = __FUNCTION__;

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
            // Filter empty strings
            $update_where_fields = array_filter(array_map('trim', $update_where_fields), function($field) {
                return !empty($field);
            });
        }
        if (is_array($update_where_fields) && !empty($update_where_fields)) {
            $this->insert_update_where_fields = array_values($update_where_fields); // 重新索引数组
        }
        
        // 插入数据（先收集，再根据实际数据确定冲突依据字段）
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

        // 主键（identity_field）为 null/空时从插入数据中移除，由数据库自增生成
        if ($this->identity_field !== '') {
            foreach ($this->insert['origin'] as &$row) {
                if (array_key_exists($this->identity_field, $row) && ($row[$this->identity_field] === null || $row[$this->identity_field] === '')) {
                    unset($row[$this->identity_field]);
                }
            }
            unset($row);
        }
        // 处理插入数据分类（按 PostgreSQL：冲突依据字段仅使用实际数据中存在的列）
        if (count($this->insert)) {
            $first_insert_item = $this->insert['origin'][0] ?? [];
            $first_insert_item_keys = array_keys($first_insert_item);

            // 仅保留实际数据中存在的冲突依据字段，避免要求数据中包含表中不存在的列
            $this->insert_update_where_fields = array_values(array_intersect(
                $this->insert_update_where_fields,
                $first_insert_item_keys
            ));

            // 若过滤后冲突依据为空且未忽略主键，则补充主键/自增键
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

            // 处理 ON CONFLICT 语法（使用过滤后的冲突依据字段）
            if (!empty($this->insert_update_where_fields)) {
                if (!empty($this->insert_update_fields)) {
                    $this->exist_update_sql = 'DO UPDATE SET ';
                    foreach ($this->insert_update_fields as $field) {
                        $field = trim(str_replace(['`', '"'], '', $field));
                        $this->exist_update_sql .= "\"{$field}\"=EXCLUDED.\"{$field}\",";
                    }
                    $this->exist_update_sql = trim($this->exist_update_sql, ',');
                } else {
                    $this->exist_update_sql = QueryInterface::EXIST_UPDATE_ALL_FIELDS;
                }
            }

            $insert_have_not_identity_fields = $this->insert_update_where_fields;
            foreach ($insert_have_not_identity_fields as $insert_have_not_identity_field_key => $insert_have_not_identity_field) {
                if ($insert_have_not_identity_field == $this->identity_field) {
                    unset($insert_have_not_identity_fields[$insert_have_not_identity_field_key]);
                    break;
                }
            }

            // 所需字段 = 主键/冲突依据与首行键的并集（不按索引覆盖，避免“所需含 code 实际无 code”的误报）
            $insert_need_fields = array_unique(array_merge(
                $this->_unit_primary_keys,
                $this->insert_update_where_fields,
                $first_insert_item_keys
            ));
            if (!isset($first_insert_item[$this->identity_field]) || is_numeric($first_insert_item[$this->identity_field])) {
                $insert_need_fields = array_values(array_filter($insert_need_fields, function ($f) {
                    return $f !== $this->identity_field;
                }));
            }
            $this->insert_need_fields = $insert_need_fields;

            foreach ($first_insert_item as $f => $fv) {
                if (!in_array($f, $insert_need_fields)) {
                    $insert_need_fields[] = $f;
                }
            }
            $insert_need_fields = array_unique($insert_need_fields);

            // 校验每行均包含所需字段
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

        $this->prepareSql(__FUNCTION__);
        return $this;
    }

    /**
     * update/find/select/delete 统一走 QueryAst，仅在本适配器实现 prepareSql 将 AST 编译为方言 SQL
     */

    public function alias(string $table_alias_name): QueryInterface
    {
        return parent::alias($table_alias_name);
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
            $mergedParts = SelectFieldListSplitter::split($this->fields);
            $mergedParts = array_unique($mergedParts);
            $this->fields = implode(',', $mergedParts);
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
     * 注意：$this->group_by 只存储字段列表，不包含 GROUP BY 关键字
     * GROUP BY 关键字在 buildSelectForPgsql() 中添加
     */
    public function group(string $fields): QueryInterface
    {
        // 规范化字段列表（使用双引号）
        $fieldList = SelectFieldListSplitter::split($fields);
        $formattedFields = [];
        foreach ($fieldList as $field) {
            $field = trim($field);
            if (!str_contains($field, '"') && !str_contains($field, '`')) {
                if (str_contains($field, '(') && str_contains($field, ')')) {
                    $formattedFields[] = $field;
                    continue;
                }
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
        // 只存储字段列表，不包含 GROUP BY 关键字
        $this->group_by = implode(', ', $formattedFields);
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
            // 🔧 修复：函数/表达式（如 concat(a,b,c)）不经过 parserFiled，避免括号与逗号被拆成多个标识符导致 PostgreSQL 语法错误
            if (is_string($field) && str_contains($field, '(') && str_contains($field, ')')) {
                $field = str_replace('`', '"', $field);
            } else {
                $field = self::parserFiled($field);
                if (is_string($field)) {
                    $field = str_replace('`', '"', $field);
                } else {
                    $field = (string)$field;
                }
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
     * 🔧 重写：完全按照 PostgreSQL 语法构建 SQL（方言逻辑集中在本类）
     * 先构建一个简单 AST，再按 PgSQL 规则编译成 SQL。
     * 实现 QueryAst 的抽象方法 prepareSql
     */
    protected function prepareSql(string $action): void
    {
        $this->reorderWhereByIndexes();
        $this->buildAst($action);
        $from = $this->ast['from'] ?? [];
        if ($this->table === '' && empty($from['is_subquery'])) {
            throw new DbException(__('没有指定table表名！'));
        }

        $compiler = new PgsqlCompiler();
        $options = [
            'identity_field' => $this->identity_field,
            'table_alias' => $this->table_alias,
            'exist_update_sql' => $this->exist_update_sql,
            'insert_update_fields' => $this->insert_update_fields,
            'insert_update_where_fields' => $this->insert_update_where_fields,
        ];
        $compiled = $compiler->compile($this->ast, $options);
        $this->sql = $compiled->sql;
        $this->bound_values = $compiled->bindings;

        $this->sql = $this->normalizeSql($this->sql);
        $this->sql = preg_replace('/\s+(AND|OR)\s*\)\s*(LIMIT|ORDER|GROUP|HAVING|$)/i', ') $2', $this->sql);
        $this->sql = preg_replace('/\s+(AND|OR)(\s*)(LIMIT|ORDER|GROUP|HAVING|$)/i', ' $3', $this->sql);
        $this->sql = preg_replace('/\s+/', ' ', $this->sql);
        $this->sql = trim($this->sql);

        if (!empty($this->sql)) {
            $this->PDOStatement = $this->preparePgsql($this->sql);
        } else {
            $this->PDOStatement = null;
        }
        return;

        $this->reorderWhereByIndexes();
        $this->buildAst($action);

        $from = $this->ast['from'] ?? [];
        if (!empty($from['is_subquery']) && !empty($from['subquery_id'])) {
            $this->sql = $this->compileAstToPgsqlSql();
        } else {
            $compiler = new PgsqlCompiler();
            $options = [
                'identity_field' => $this->identity_field,
                'table_alias' => $this->table_alias,
                'exist_update_sql' => $this->exist_update_sql,
                'insert_update_fields' => $this->insert_update_fields,
                'insert_update_where_fields' => $this->insert_update_where_fields,
            ];
            $compiled = $compiler->compile($this->ast, $options);
            $this->sql = $compiled->sql;
            $this->bound_values = $compiled->bindings;
        }

        $this->sql = $this->normalizeSql($this->sql);
        $this->sql = preg_replace('/\s+(AND|OR)\s*\)\s*(LIMIT|ORDER|GROUP|HAVING|$)/i', ') $2', $this->sql);
        $this->sql = preg_replace('/\s+(AND|OR)(\s*)(LIMIT|ORDER|GROUP|HAVING|$)/i', ' $3', $this->sql);
        $this->sql = preg_replace('/\s+/', ' ', $this->sql);
        $this->sql = trim($this->sql);


        if (!empty($this->sql)) {
            $this->PDOStatement = $this->preparePgsql($this->sql);
        } else {
            $this->PDOStatement = null;
        }
    }

    /**
     * 获取字段定义（PostgreSQL 实现）
     */
    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        $configProvider = $this->connection->getConfigProvider();
        $dbName = $configProvider->getDatabase();

        // 默认 schema 为 public
        $schema = SchemaConfig::getCurrentSchema();
        $table = $tableName;

        // 支持 "schema.table" 或 "db.schema.table" 格式
        if (str_contains($tableName, '.')) {
            $parts = explode('.', $tableName, 3);
            if (count($parts) === 3) {
                // 可能是 db.schema.table
                [$dbPart, $schemaPart, $tablePart] = $parts;
                if ($dbPart === $dbName) {
                    $schema = $schemaPart;
                    $table = $tablePart;
                } else {
                    // db 不匹配时，认为前两段是 schema.table
                    $schema = $dbPart;
                    $table = $schemaPart;
                }
            } else {
                // schema.table
                [$schemaPart, $tablePart] = $parts;
                if ($schemaPart === $dbName) {
                    $schema = SchemaConfig::getCurrentSchema();
                    $table = $tablePart;
                } else {
                    $schema = $schemaPart;
                    $table = $tablePart;
                }
            }
        }

        $sql = "SELECT * FROM information_schema.columns 
                WHERE table_schema = :schema 
                  AND table_name = :table 
                  AND column_name = :column";

        $pdo = $this->getLink();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':schema' => $schema,
            ':table' => $table,
            ':column' => $fieldName,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $this->reset();

        return $row ?: null;
    }


    /**
     * 将 AST 编译成 PostgreSQL SQL。
     * 目前内部仍然复用原有的 build*ForPgsql 方法，只是多了一层结构化描述，方便以后按 Doctrine 风格继续演进。
     */
    protected function compileAstToPgsqlSql(): string
    {
        $action = $this->ast['action'] ?? 'select';

        // 🔧 处理 FROM 子句：可能是表名或子查询
        $fromInfo = $this->ast['from'] ?? [];
        $isSubquery = $fromInfo['is_subquery'] ?? false;
        
        if ($isSubquery && isset($fromInfo['subquery_id'])) {
            // FROM 子句是子查询
            $subqueryId = $fromInfo['subquery_id'];
            $table = $this->compileSubquery($subqueryId);
        } else {
            // FROM 子句是普通表
            $table = $this->formatTableNameForPgsql($this->ast['from']['table'] ?? $this->table);
        }
        
        $aliasName = $this->ast['from']['alias'] ?? $this->table_alias;
        $alias = $aliasName ? 'AS "' . $aliasName . '"' : '';

        // 构建各个 SQL 部分
        $joins   = $this->buildJoinsForPgsql();
        $wheres  = $this->buildWheresForPgsql();
        $order   = $this->buildOrderForPgsql();
        // 构建 GROUP BY 子句
        // 注意：$this->ast['group'] 只包含字段列表，不包含 GROUP BY 关键字
        $groupBy = '';
        if (isset($this->ast['group']) && $this->ast['group']) {
            $groupValue = trim($this->ast['group']);
            // 移除可能存在的 GROUP BY 前缀（兼容旧代码）
            if (stripos($groupValue, 'GROUP BY') === 0) {
                $groupValue = trim(substr($groupValue, 8));
            }
            if ($groupValue) {
                $groupBy = 'GROUP BY ' . $this->normalizeSql($groupValue);
            }
        }
        $having  = isset($this->ast['having']) && $this->ast['having'] ? 'HAVING ' . $this->normalizeSql($this->ast['having']) : '';
        $extra   = $this->ast['extra'] ?? $this->additional_sql;

        switch ($action) {
            case 'insert':
                return $this->buildInsertForPgsql($table);
            case 'delete':
                // 🔧 修复：additional_sql 已经在 additional() 方法中标准化了
                return "DELETE FROM {$table} {$wheres} {$extra}";
            case 'update':
                return $this->buildUpdateForPgsql($table, $wheres);
            case 'find':
            case 'select':
            default:
                // 格式化字段列表
                $fields = $this->formatFieldsForPgsql($this->ast['select']['fields'] ?? $this->fields);
                return "SELECT {$fields} FROM {$table} {$alias} {$joins} {$wheres} {$groupBy} {$having} {$extra} {$order} {$this->limit}";
        }
    }
    
    /**
     * 编译子查询 AST 为 PostgreSQL SQL
     * 
     * @param string $subqueryId 子查询标识
     * @return string 编译后的子查询 SQL（已用括号包裹）
     */
    protected function compileSubquery(string $subqueryId): string
    {
        if (!isset($this->ast['subqueries'][$subqueryId])) {
            throw new DbException(__('子查询 %{1} 不存在', [$subqueryId]));
        }
        
        $subqueryAst = $this->ast['subqueries'][$subqueryId];
        
        // 创建临时查询对象来编译子查询 AST
        // 注意：这里需要创建一个新的查询实例，但使用相同的连接和配置
        $tempQuery = clone $this;
        $tempQuery->ast = $subqueryAst;
        // 恢复子查询的属性（从 AST 中恢复）
        if (isset($subqueryAst['from']['table'])) {
            $tempQuery->table = $subqueryAst['from']['table'];
        }
        if (isset($subqueryAst['from']['alias'])) {
            $tempQuery->table_alias = $subqueryAst['from']['alias'];
        }
        if (isset($subqueryAst['select']['fields'])) {
            $tempQuery->fields = $subqueryAst['select']['fields'];
        }
        if (isset($subqueryAst['where'])) {
            $tempQuery->wheres = $subqueryAst['where'];
        }
        if (isset($subqueryAst['joins'])) {
            $tempQuery->joins = $subqueryAst['joins'];
        }
        if (isset($subqueryAst['order'])) {
            $tempQuery->order = $subqueryAst['order'];
        }
        if (isset($subqueryAst['group'])) {
            $tempQuery->group_by = $subqueryAst['group'];
        }
        if (isset($subqueryAst['having'])) {
            $tempQuery->having = $subqueryAst['having'];
        }
        if (isset($subqueryAst['limit'])) {
            $tempQuery->limit = $subqueryAst['limit'];
        }
        if (isset($subqueryAst['extra'])) {
            $tempQuery->additional_sql = $subqueryAst['extra'];
        }
        
        // 编译子查询 SQL
        $subquerySql = $tempQuery->compileAstToPgsqlSql();
        
        // 用括号包裹子查询
        return '(' . $subquerySql . ')';
    }


    /**
     * 格式化表名（PostgreSQL 使用双引号）
     */
    protected function formatTableNameForPgsql(string $table): string
    {
        // 如果已经是 "schema"."table" 这种标准格式，直接返回
        if (preg_match('/^"([^"]+)"\."([^"]+)"$/', $table)) {
            return $table;
        }

        // 去掉所有 MySQL 风格或残留的引号，统一用裸表名来解析
        $raw = str_replace(['`', '"'], '', trim($table));

        // 表名不能为空
        if ($raw === '') {
            throw new DbException(__('表名不能为空'));
        }

        // 按点拆分，处理 db.schema.table 或 schema.table 或 table
        $parts = array_values(array_filter(array_map('trim', explode('.', $raw)), fn($p) => $p !== ''));

        if (empty($parts)) {
            throw new DbException(__('表名格式错误：%{1}', [$table]));
        }

        // 处理数据库名 -> schema 转换：如果首段是当前 db_name，则使用 current_schema()
        $dbName = $this->db_name ?? '';
        if (count($parts) >= 2 && $parts[0] === $dbName) {
            // 使用 current_schema() 而不是硬编码 'public'
            try {
                $currentSchema = $this->getLink()->query('SELECT current_schema()')->fetchColumn();
                $parts[0] = $currentSchema ?: 'public';
            } catch (\Throwable $e) {
                $parts[0] = 'public';
            }
        }

        // 最多保留 schema.table 两段，多余的视为表名一部分，取最后两段
        if (count($parts) > 2) {
            $parts = array_slice($parts, -2);
        }

        // 只有一个部分：视为当前 schema 下的表
        if (count($parts) === 1) {
            return '"' . $parts[0] . '"';
        }

        // 两个部分：schema.table
        return '"' . $parts[0] . '"."' . $parts[1] . '"';
    }

    /**
     * 按 SELECT 列表的「顶层逗号」分割字段（括号内的逗号不拆，避免 COALESCE(SUM(x), 0) 等被拆坏）
     *
     * @return list<string>
     */
    protected function splitSelectFieldList(string $fields): array
    {
        return SelectFieldListSplitter::split($fields);
    }

    /**
     * 格式化字段列表（PostgreSQL 使用双引号）
     */
    protected function formatFieldsForPgsql(string $fields): string
    {
        if ($fields === '*' || empty($fields)) {
            return '*';
        }
        
        // 分割字段列表（不可简单 explode 逗号：函数实参中含逗号）
        $fieldList = $this->splitSelectFieldList($fields);
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
     * AST 中的字段名是干净的操作结构，不包含方言特定的引号
     * 此方法负责将 AST 字段名转换为 PostgreSQL 方言格式
     * 
     * @param string $field AST 中的字段名（应该是干净的，无引号）
     * @return string PostgreSQL 格式的字段表达式
     */
    protected function formatFieldExpression(string $field): string
    {
        // 清理输入：AST 理论上不应该有引号，但为了兼容性，先清理可能存在的引号
        $field = trim($field);
        $field = str_replace(['`', '"'], '', $field); // 清理可能存在的引号（兼容性处理）
        
        // 1. 处理 alias.* 的情况（PostgreSQL 语法）
        // PostgreSQL 支持 alias.* 或 "alias".*，但不支持 "alias"."*"
        if (preg_match('/^([^.]*?)\.\*$/', $field, $matches)) {
            $alias = trim($matches[1]);

            // 兼容框架占位别名 main_table：如果实际主表别名不是 main_table，则将其替换为真实别名
            if ($alias === 'main_table' && !empty($this->table_alias) && $this->table_alias !== 'main_table') {
                $alias = $this->table_alias;
            }
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
        
        // 2. 处理函数调用（如 count(*), sum(field), max(field) 等）
        // 函数调用不应该被引号括起来，直接返回
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/i', $field)) {
            return $field;
        }
        
        // 3. 处理限定名（table.field 或 alias.field 格式）
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);

            // 处理 main_table.xxx 这种占位别名，替换为真实主表别名
            if (!empty($this->table_alias) && $this->table_alias !== 'main_table' && isset($parts[0]) && $parts[0] === 'main_table') {
                $parts[0] = $this->table_alias;
            }
            // 处理数据库名 -> schema 转换
            $dbName = $this->db_name ?? 'public';
            if (count($parts) >= 2 && $parts[0] === $dbName) {
                $parts[0] = 'public';
            }
            // 按照 PostgreSQL 规则格式化：每个部分用双引号包裹
            return '"' . implode('"."', $parts) . '"';
        }
        
        // 4. 普通字段名：按照 PostgreSQL 规则用双引号包裹
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
            // join[0] 里是类似 "m_role `r`" 或 "m_role r" 或 "m_role as r" 的字符串
            $tableWithAlias = trim($join[0]);
            $condition = $join[1];
            $type = strtoupper($join[2] ?? 'LEFT');

            // 🔧 不用正则，按空格简单拆分表名和别名
            $rawTable = $tableWithAlias;
            $alias = '';
            // 直接按空格拆分，并过滤掉空字符串（多空格的情况）
            $parts = array_values(array_filter(explode(' ', $tableWithAlias), fn($p) => $p !== ''));
            if (count($parts) >= 2) {
                // 最后一个 token 视为别名（去掉反引号/双引号）
                $aliasToken = $parts[count($parts) - 1];
                $alias = trim($aliasToken, '`"');
                
                // 🔧 处理 "table as alias" 格式：跳过 AS 关键字
                $tableParts = array_slice($parts, 0, -1);
                // 如果倒数第二个是 "as" 或 "AS"，也要跳过
                if (count($tableParts) > 0 && strtolower($tableParts[count($tableParts) - 1]) === 'as') {
                    $tableParts = array_slice($tableParts, 0, -1);
                }
                $rawTable = implode(' ', $tableParts);
            }

            // 格式化表名（只对真正的表名部分做 schema/引号处理）
            $table = $this->formatTableNameForPgsql($rawTable);
            $aliasSql = $alias ? ' AS "' . $alias . '"' : '';
            
            // 格式化条件（处理标识符）
            $condition = $this->formatJoinCondition($condition);
            
            $joins .= " {$type} JOIN {$table}{$aliasSql} ON {$condition} ";
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
            // 情况 1：函数或复杂表达式（包含括号），例如：DATE(main_table.login_time)
            // 这类表达式不再尝试拆分 table.field，只做简单的反引号替换，避免把函数名当成表名
            $field = $where[0];
            if (is_string($field) && str_contains($field, '(')) {
                // 仅将 MySQL 风格的 ` 标识符引号替换为 PgSQL 的 "，其余保持原样
                $field = str_replace('`', '"', $field);
                // AST 层修复：为表达式中未加引号的保留字加双引号（如 CONCAT(a,b,order) 中的 order）
                $field = self::quoteReservedWordsInExpression($field);
            } else {
                // 情况 2：普通字段或 table.field
                if (!str_contains((string)$field, '"') && !str_contains((string)$field, '`')) {
                    if (str_contains((string)$field, '.')) {
                        $parts = explode('.', (string)$field);
                        $field = '"' . implode('"."', $parts) . '"';
                    } else {
                        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$field)) {
                            $field = '"' . $field . '"';
                        }
                    }
                } else {
                    // 已经带引号的情况：只把反引号替换为双引号，确保 PgSQL 能识别
                    if (is_string($field)) {
                        $field = str_replace('`', '"', $field);
                    } else {
                        $field = (string)$field;
                    }
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
                    // IS NULL / IS NOT NULL 条件不需要绑定值
                    // 🔧 修复：规范化条件字符串（去除多余空格，转小写）
                    $conditionStr = $where[1] ?? '';
                    $lowerCondition = strtolower(trim(preg_replace('/\s+/', ' ', $conditionStr)));
                    
                    // 🔧 优化：使用 str_contains 检测 IS NULL 变体，更健壮
                    $isNullCondition = ($lowerCondition === 'is null' || $lowerCondition === 'is not null');
                    if (!$isNullCondition && str_contains($lowerCondition, 'is') && str_contains($lowerCondition, 'null')) {
                        // 可能是 IS NULL 或 IS NOT NULL 的变体（如 "IS  NULL"）
                        $isNullCondition = true;
                    }
                    
                    if ($isNullCondition) {
                        // 标准化输出为 IS NULL 或 IS NOT NULL
                        $nullType = str_contains($lowerCondition, 'not') ? 'IS NOT NULL' : 'IS NULL';
                        $wheres .= '(' . $field . ' ' . $nullType . ')';
                        if (!$isLast) {
                            $wheres .= ' ' . $currentLogic;
                        }
                        break;
                    }
                    
                    // 🔧 修复：当值为 null 时，必须使用 IS NULL 语义
                    // PostgreSQL 不允许字段名作为独立的布尔表达式
                    if ($where[2] === null) {
                        // 根据条件判断使用 IS NULL 还是 IS NOT NULL
                        // 如果条件包含 NOT（如 != 或 <>），使用 IS NOT NULL
                        $conditionUpper = strtoupper(trim($conditionStr));
                        if (in_array($conditionUpper, ['!=', '<>', 'NOT', 'NOT ='], true)) {
                            $wheres .= '(' . $field . ' IS NOT NULL)';
                        } else {
                            $wheres .= '(' . $field . ' IS NULL)';
                        }
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
                    
                    // 🔧 处理子查询：如果 where[2] 是数组且包含 is_subquery 标记
                    if (is_array($where[2]) && isset($where[2]['is_subquery']) && $where[2]['is_subquery']) {
                        $subqueryId = $where[2]['subquery_id'] ?? '';
                        if ($subqueryId) {
                            $subquerySql = $this->compileSubquery($subqueryId);
                            $wheres .= $field . ' ' . $where[1] . ' ' . $subquerySql;
                            if (!$isLast) {
                                $wheres .= ' ' . $currentLogic;
                            }
                            break;
                        }
                    }
                    
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
                    // 🔧 修复：正确处理 null 值，避免将 null 转换为空字符串导致 PostgreSQL 整数字段错误
                    if (is_array($insert_value)) {
                        $this->bound_values[$insert_bound_key] = json_encode($insert_value, JSON_UNESCAPED_UNICODE);
                    } elseif (is_null($insert_value)) {
                        $this->bound_values[$insert_bound_key] = null;
                    } else {
                        $this->bound_values[$insert_bound_key] = (string)$insert_value;
                    }
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
                    // 构建冲突字段列表（仅使用真实存在于插入字段中的列，过滤非法/占位字段，如 '.' 或空字符串）
                    $conflictFields = [];
                    if (!empty($this->insert_update_where_fields)) {
                        foreach ($this->insert_update_where_fields as $field) {
                            $field = trim((string)$field);
                            // 只保留当前 insert 记录里真实存在的字段
                            if ($field !== '' && in_array($field, $insert_fields, true)) {
                                $conflictFields[] = '"' . $field . '"';
                            }
                        }
                    }
                    if (!empty($conflictFields)) {
                        // 语义常量 EXIST_UPDATE_ALL_FIELDS 由本适配器展开为方言
                        if ($this->exist_update_sql === QueryInterface::EXIST_UPDATE_ALL_FIELDS) {
                            $updateParts = [];
                            foreach ($insert_fields as $field) {
                                // 跳过冲突检测字段
                                if (in_array($field, $this->insert_update_where_fields, true)) {
                                    continue;
                                }
                                // 跳过主键字段
                                if ($this->identity_field && $field === $this->identity_field) {
                                    continue;
                                }
                                $updateParts[] = "\"{$field}\"=EXCLUDED.\"{$field}\"";
                            }
                            if (!empty($updateParts)) {
                                $this->exist_update_sql = 'DO UPDATE SET ' . implode(', ', $updateParts);
                            } else {
                                // 如果没有要更新的字段，使用 DO NOTHING
                                $this->exist_update_sql = 'DO NOTHING';
                            }
                        }
                        $sql .= ' ON CONFLICT (' . implode(', ', $conflictFields) . ') ' . $this->exist_update_sql;
                    } else {
                        // 如果没有合法的冲突字段，取消 ON CONFLICT 子句，退回为普通 INSERT
                        $this->exist_update_sql = '';
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
        
        // 使用数组收集每个字段的更新表达式，避免对同一字段重复赋值
        $updateExpressions = [];
        
        // 处理 dec_inc_updates
        if (!empty($this->dec_inc_updates)) {
            foreach ($this->dec_inc_updates as $dec_inc_update_field => $dec_inc_update_value) {
                $field_quoted = '"' . $dec_inc_update_field . '"';
                // 直接覆盖同名字段的表达式，确保不会出现重复赋值
                $updateExpressions[$dec_inc_update_field] = "{$field_quoted} = {$field_quoted} {$dec_inc_update_value}";
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
                // PostgreSQL 在 CASE 表达式中要求所有分支返回相同类型
                // 需要根据值的类型进行适当的类型转换
                $keys = array_keys(current($this->updates));
                foreach ($keys as $column) {
                    if ($column === $this->identity_field) {
                        continue;
                    }
                    $column_quoted = '"' . $column . '"';
                    // 为当前列构建 CASE 表达式
                    $caseSql = sprintf("%s = CASE %s \n", $column_quoted, $identity_field_quoted);
                    
                    // 检测该列的值类型，决定是否需要类型转换
                    $needsCast = false;
                    $castType = null;
                    foreach ($this->updates as $line) {
                        $value = $line[$column] ?? null;
                        if ($value !== null) {
                            // 检查是否为整数类型
                            if (is_int($value) || (is_string($value) && is_numeric($value) && !str_contains((string)$value, '.'))) {
                                $needsCast = true;
                                $castType = 'INTEGER';
                                break;
                            } elseif (is_bool($value)) {
                                $needsCast = true;
                                $castType = 'INTEGER';
                                break;
                            }
                        }
                    }
                    
                    foreach ($this->updates as $update_key => $line) {
                        $update_key += 1;
                        $identity_field_column_key = ':' . md5("{$this->identity_field}_{$column}_key_{$update_key}");
                        $this->bound_values[$identity_field_column_key] = (string)$line[$this->identity_field];
                        $identity_field_column_value = ':' . md5("update_{$column}_value_{$update_key}");
                        $value = $line[$column] ?? null;
                        
                        // 根据类型处理值
                        if (is_bool($value)) {
                            $this->bound_values[$identity_field_column_value] = $value ? '1' : '0';
                            $caseSql .= sprintf('WHEN %s THEN CAST(%s AS INTEGER) ', $identity_field_column_key, $identity_field_column_value);
                        } elseif (is_int($value) || (is_string($value) && is_numeric($value) && !str_contains((string)$value, '.'))) {
                            // 整数或数字字符串（不包含小数点）
                            $this->bound_values[$identity_field_column_value] = (string)$value;
                            $caseSql .= sprintf('WHEN %s THEN CAST(%s AS INTEGER) ', $identity_field_column_key, $identity_field_column_value);
                        } else {
                            // 其他类型（字符串、浮点数、NULL等）
                            $this->bound_values[$identity_field_column_value] = $value === null ? null : (string)$value;
                            $caseSql .= sprintf('WHEN %s THEN %s ', $identity_field_column_key, $identity_field_column_value);
                        }
                    }
                    $caseSql .= 'END';
                    // 覆盖当前列的更新表达式，避免重复赋值
                    $updateExpressions[$column] = $caseSql;
                }
            } else {
                // 单条更新
                if (count($this->updates) > 1) {
                    throw new \Exception(__('更新条数大于一条时请使用示例更新'));
                }
                foreach ($this->updates[0] as $update_field => $field_value) {
                    $update_key = ':' . md5($update_field);
                    $update_field_quoted = '"' . $update_field . '"';
                    // 🔧 修复：正确处理 null 值，避免将 null 转换为空字符串导致 PostgreSQL 整数字段错误
                    $this->bound_values[$update_key] = $field_value === null ? null : (string)$field_value;
                    // 单条更新时也通过数组覆盖，确保同一字段只有一个赋值
                    $updateExpressions[$update_field] = "{$update_field_quoted} = $update_key";
                }
            }
        }
        
        // 处理 single_updates
        if (!empty($this->single_updates)) {
            foreach ($this->single_updates as $update_field => $update_value) {
                $update_field_quoted = '"' . $update_field . '"';
                $update_key = ':' . md5($update_field);
                // 🔧 修复：正确处理 null 值，避免将 null 转换为空字符串导致 PostgreSQL 整数字段错误
                $this->bound_values[$update_key] = $update_value === null ? null : (string)$update_value;
                // single_updates 的值优先级最高，覆盖前面的表达式
                $updateExpressions[$update_field] = "{$update_field_quoted}=$update_key";
            }
        }
        
        if (empty($updateExpressions)) {
            throw new DbException(__('没有要更新的字段'));
        }
        
        // 将每个字段的更新表达式拼接为最终的 SET 子句
        $updates = implode(',', $updateExpressions);
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
        $link = $this->getLink();
        if (!$link->inTransaction()) {
            $link->beginTransaction();
        }
    }

    public function rollBack(): void
    {
        $pdo = $this->getLink();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            return;
        }
        // PostgreSQL：语句失败后 inTransaction() 可能错误返回 false，但连接仍处于 aborted 状态，
        // 必须执行 ROLLBACK 才能恢复，否则后续操作报 25P02
        try {
            $pdo->rollBack();
        } catch (\Throwable) {
            // 无活跃事务时 rollBack 会抛错，忽略
        }
    }

    public function commit(): void
    {
        $link = $this->getLink();
        if ($link->inTransaction()) {
            $link->commit();
        }
    }

    /**
     * @DESC          # 解析数组键（PgSQL 版）
     * 基于 SqlTrait::parserFiled 逻辑改造，只是把 MySQL 的反引号改为 PgSQL 的双引号。
     *
     * @param string|array $field 解析数据：一维数组值 或者 二维数组值
     *
     * @return string|array
     */
    protected static function parserFiled(mixed &$field): mixed
    {
        if (!is_array($field) && !is_string($field)) {
            return $field;
        }
        if (is_string($field)) {
            // 以()号隔开
            if (str_contains($field, '(')) {
                $field = explode('(', $field);
                foreach ($field as &$f) {
                    $f = self::parserFiled($f);
                }
                $field = implode('(', $field);
                return $field;
            }
            if (str_contains($field, ')')) {
                $field = explode(')', $field);
                foreach ($field as &$f) {
                    $f = self::parserFiled($f);
                }
                $field = implode(')', $field);
                return $field;
            }
            // 以逗号隔开
            if (str_contains($field, ',')) {
                $field = explode(',', $field);
                foreach ($field as &$f) {
                    $f = self::parserFiled($f);
                }
                $field = implode(',', $field);
                if (str_contains($field, '""')) {
                    return str_replace('""', '"', $field);
                }
                return $field;
            }
            if (str_starts_with($field, '"') || str_starts_with($field, "'")) {
                if (str_contains($field, '""')) {
                    return str_replace('""', '"', $field);
                }
                return $field;
            }
            // 如果没有空格，也没有等于符号【单纯字段】直接加上双引号
            // PostgreSQL 需对 order、user、key 等保留字加引号，否则 syntax error
            if (!str_contains($field, ' ') && !str_contains($field, '=')) {
                if (str_contains($field, '.')) {
                    $field = '"' . str_replace('.', '"."', $field) . '"';
                } elseif ($field !== '*' && !str_starts_with($field, '"') && !str_starts_with($field, "'")) {
                    $field = '"' . $field . '"';
                }
                if (str_contains($field, '""')) {
                    $field = str_replace('""', '"', $field);
                }
                $field = str_replace('"*"', '*', $field);
                return $field;
            }
            $field = preg_replace('/\s+/', ' ', $field);
            // 解决类似 main_table.parent_source is null 的问题
            $field_arr = explode(' ', $field);
            foreach ($field_arr as $field_arr_key => $field_arr_value) {
                if (strtolower($field_arr_value) == 'as') {
                    if (isset($field_arr[$field_arr_key + 1])) {
                        $field_arr[$field_arr_key + 1] = '"' . $field_arr[$field_arr_key + 1] . '"';
                    }
                }
                if (str_contains($field_arr_value, '.')) {
                    if (str_contains($field_arr_value, '=')) {
                        $field_arr_value_arr = explode('=', $field_arr_value);
                        $field_arr_value_arr[0] = self::parserFiled($field_arr_value_arr[0]);
                        $field_arr_value_arr[1] = self::parserFiled($field_arr_value_arr[1]);
                        $field_arr_value = implode('=', $field_arr_value_arr);
                    } else {
                        $field_arr_value = '"' . str_replace('.', '"."', $field_arr_value) . '"';
                    }
                    $field_arr_value = str_replace('""', '"', $field_arr_value);
                    $field_arr[$field_arr_key] = $field_arr_value;
                }
            }
            $field = implode(' ', $field_arr);
            $field = str_replace('"*"', '*', $field);
        } elseif (is_array($field)) {
            foreach ($field as $field_key => $value) {
                unset($field[$field_key]);
                $field_key = self::parserFiled($field_key);
                $field[$field_key] = $value;
            }
        }
        if (str_contains($field, '""')) {
            return str_replace('""', '"', $field);
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
            $schema = SchemaConfig::getCurrentSchema();
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
                COALESCE(col_description(('\"{$schema}\".\"{$table}\"')::regclass::oid, c.ordinal_position), '') AS \"Comment\"
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
     * PostgreSQL 保留字（常作为列名出现，在表达式中必须用双引号）
     * 与 SqlTrait::$special_fields 保持一致
     */
    private const PGSQL_RESERVED_WORDS = ['order', 'key', 'table', 'fields', 'group', 'user'];

    /** 单次 select/query 最大加载行数，防止大结果集耗尽内存 */
    /** 单次 fetch 最大行数，避免 128M 等小内存下 OOM；超量请用 fetchIterator() 或 limit() */
    private const MAX_FETCH_ROWS = 50_000;

    /**
     * 为表达式中未加引号的保留字加双引号，避免 syntax error
     * 匹配形如 CONCAT(a,b,order,c) 中作为标识符的 order
     */
    protected static function quoteReservedWordsInExpression(string $sql): string
    {
        foreach (self::PGSQL_RESERVED_WORDS as $word) {
            $pattern = '/([,(])\s*\b' . preg_quote($word, '/') . '\b\s*([,)])/i';
            $sql = preg_replace($pattern, '$1"' . $word . '"$2', $sql);
        }
        return $sql;
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
        
        // 2.5 为表达式中未加引号的保留字加上双引号（如 CONCAT(a,b,order,c) 中的 order）
        $sql = self::quoteReservedWordsInExpression($sql);
        
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
        // 处理顺序：先移除特殊字符，再检查数字开头（与 normalizeParameterArray 完全一致）
        return preg_replace_callback('/:([a-zA-Z0-9_]+)/', function($matches) {
            $paramName = $matches[1];
            // 先移除所有非字母数字下划线的字符
            $paramName = preg_replace('/[^a-zA-Z0-9_]/', '_', $paramName);
            // 如果参数名以数字开头，添加 'p' 前缀
            if (preg_match('/^[0-9]/', $paramName)) {
                return ':p' . $paramName;
            }
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
     * 精确替换 SQL 占位符，避免 :param1 误替换到 :param10。
     */
    protected function replaceSqlPlaceholder(string $sql, string $from, string $to): string
    {
        $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($from, '/') . '(?![A-Za-z0-9_])/';
        return (string)preg_replace($pattern, $to, $sql);
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
        $this->bound_values = $this->normalizeParameterArray($this->bound_values);
        
        // 🔧 修复：强制更新 SQL 中的参数名以匹配规范化后的 bound_values
        // 问题原因：原条件判断可能在某些情况下（如重复调用）跳过更新
        // 解决方案：直接用 bound_values 的键来替换 SQL 中的参数名
        if (!empty($this->bound_values)) {
            foreach ($this->bound_values as $newParam => $value) {
                // 如果 bound_values 的键以 ':p' 开头（添加了 p 前缀），说明原参数以数字开头
                // 需要将 SQL 中的原始形式（去掉 p 前缀）替换为新形式
                if (str_starts_with($newParam, ':p') && strlen($newParam) > 2) {
                    $originalParam = ':' . substr($newParam, 2);
                    $sql = $this->replaceSqlPlaceholder($sql, $originalParam, $newParam);
                }
            }
        }
        
        // ⚠️ 不再调用 normalizeParameterNames（避免双重规范化）
        // normalizeParameterArray 已将 bound_values 规范化；
        // preparePgsql/bindValue 的手动映射 + 单词边界替换已确保 SQL 与 bound_values 一致
        
        // 保持对象状态一致：后续回退到 exec() 时，SQL 占位符必须与 bound_values 键一致
        $this->sql = $sql;
        
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
        
        // 直接返回原始 PDOStatement
        return $stmt;
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
        // 🔧 修复：规范化 SQL 和 bound_values，确保参数名一致
        // 批量插入时 SQL 末尾有分号，需要先规范化
        $this->bound_values = $this->normalizeParameterArray($this->bound_values);
        foreach ($this->bound_values as $newParam => $value) {
            if (str_starts_with($newParam, ':p') && strlen($newParam) > 2) {
                $originalParam = ':' . substr($newParam, 2);
                $sql = $this->replaceSqlPlaceholder($sql, $originalParam, $newParam);
            }
        }
        $sql = $this->normalizeSql($sql);
        
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
        
        // 直接返回原始 PDOStatement
        return $stmt;
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
        $traceOperation = $this->fetch_type ?: 'query';
        $traceTable = $this->table !== '' ? $this->table : 'unknown';
        $dbTraceLabel = 'db::' . $traceOperation . '::' . $traceTable;
        $dbTraceStart = 0.0;
        $dbTraceSql = '';
        if (RequestLifecycleTrace::isEnabled()) {
            $dbTraceStart = microtime(true);
            try {
                $dbTraceSql = $this->getSqlWithBounds($this->sql);
            } catch (\Throwable) {
                $dbTraceSql = $this->sql;
            }
        }
        try {
            try {
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
                    // 🔧 修复：规范化 SQL 和 bound_values，确保参数名一致后再调用 getSqlWithBounds
                    $this->bound_values = $this->normalizeParameterArray($this->bound_values);
                    foreach ($this->bound_values as $newParam => $value) {
                        if (str_starts_with($newParam, ':p') && strlen($newParam) > 2) {
                            $originalParam = ':' . substr($newParam, 2);
                            $this->sql = $this->replaceSqlPlaceholder($this->sql, $originalParam, $newParam);
                        }
                    }
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
                        $errorCode = $e->getCode();
                        $errorMessage = $e->getMessage();
                        // ON CONFLICT 无匹配约束（42P10）：表可能无联合主键/唯一约束，回退为普通 INSERT
                        if ($errorCode == '42P10' ||
                            str_contains($errorMessage, 'there is no unique or exclusion constraint matching the ON CONFLICT specification') ||
                            str_contains($errorMessage, 'ON CONFLICT specification')) {
                            $sqlWithoutConflict = preg_replace('/\s+ON CONFLICT\s+\([^)]+\)\s+(?:DO UPDATE SET|DO NOTHING)[^;]*/i', '', $this->sql);
                            $sqlWithoutConflict = preg_replace('/\s+RETURNING\s+[^;]+/i', '', $sqlWithoutConflict);
                            $this->sql = trim($sqlWithoutConflict);
                            $this->PDOStatement = null;
                            $testStmt = $this->preparePgsql($this->sql);
                            if ($testStmt === false) {
                                throw new \PDOException(
                                    __('无法回退到普通插入：SQL 准备失败'),
                                    (int)$errorCode,
                                    $e
                                );
                            }
                            $this->PDOStatement = $testStmt;
                            $this->PDOStatement->execute($this->bound_values);
                        } elseif (str_contains($errorMessage, 'cannot insert multiple commands') ||
                            str_contains($errorMessage, 'multiple commands') ||
                            ($errorCode === '42601')) {
                            // 如果 prepare 抛出异常（可能是多个命令），回退到 exec()
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
        
        // 🔧 修复：在执行 INSERT 时，如果遇到 ON CONFLICT 错误（42P10），回退到普通插入
        if ($this->fetch_type == 'insert' && !empty($this->exist_update_sql) && stripos($this->sql, 'ON CONFLICT') !== false) {
            try {
                // 先尝试执行带 ON CONFLICT 的 SQL
                if ($this->PDOStatement === null && !empty($this->sql)) {
                    $this->PDOStatement = $this->preparePgsql($this->sql);
                }
                if ($this->PDOStatement !== null) {
                    $this->PDOStatement->execute($this->bound_values);
                }
                return $this->processFetchResult($model_class);
            } catch (\PDOException $e) {
                // 检查是否是 ON CONFLICT 错误（42P10: Invalid column reference）
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();
                if ($errorCode == '42P10' || 
                    str_contains($errorMessage, 'there is no unique or exclusion constraint matching the ON CONFLICT specification') ||
                    str_contains($errorMessage, 'ON CONFLICT specification')) {
                    // 回退到普通插入：移除 ON CONFLICT 子句
                    $sqlWithoutConflict = preg_replace('/\s+ON CONFLICT\s+\([^)]+\)\s+(?:DO UPDATE SET|DO NOTHING)[^;]*/i', '', $this->sql);
                    // 移除 RETURNING 子句（如果有），因为普通插入可能不需要
                    $sqlWithoutConflict = preg_replace('/\s+RETURNING\s+[^;]+/i', '', $sqlWithoutConflict);
                    $this->sql = trim($sqlWithoutConflict);
                    
                    // 清除旧的 PDOStatement 和 bound_values（如果需要）
                    $this->PDOStatement = null;
                    
                    // 重新准备语句
                    $this->PDOStatement = $this->preparePgsql($this->sql);
                    if ($this->PDOStatement === false) {
                        throw new \PDOException(
                            __('无法回退到普通插入：SQL 准备失败'),
                            (int)$errorCode,
                            $e
                        );
                    }
                    // 重新执行
                    $this->PDOStatement->execute($this->bound_values);
                    return $this->processFetchResult($model_class);
                }
                // 其他错误，继续抛出
                throw $e;
            }
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
                    // 🔧 修复：检查是否是 ON CONFLICT 错误（42P10），回退到普通插入
                    $errorCode = $e->getCode();
                    $errorMessage = $e->getMessage();
                    if ($errorCode == '42P10' || 
                        str_contains($errorMessage, 'there is no unique or exclusion constraint matching the ON CONFLICT specification') ||
                        str_contains($errorMessage, 'ON CONFLICT specification')) {
                        // 回退到普通插入：移除 ON CONFLICT 子句
                        $sqlWithoutConflict = preg_replace('/\s+ON CONFLICT\s+\([^)]+\)\s+(?:DO UPDATE SET|DO NOTHING)[^;]*/i', '', $this->sql);
                        // 移除 RETURNING 子句（如果有），因为普通插入可能不需要
                        $sqlWithoutConflict = preg_replace('/\s+RETURNING\s+[^;]+/i', '', $sqlWithoutConflict);
                        $this->sql = trim($sqlWithoutConflict);
                        
                        // 清除旧的 PDOStatement
                        $this->PDOStatement = null;
                        
                        // 重新准备语句
                        $this->PDOStatement = $this->preparePgsql($this->sql);
                        if ($this->PDOStatement === false) {
                            throw new \PDOException(
                                __('无法回退到普通插入：SQL 准备失败'),
                                (int)$errorCode,
                                $e
                            );
                        }
                        // 重新执行
                        $this->PDOStatement->execute($this->bound_values);
                        $this->batch = false;
                    } else {
                        throw $e;
                    }
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
            } finally {
                if ($dbTraceStart > 0.0) {
                    $duration = (microtime(true) - $dbTraceStart) * 1000;
                    if ($duration < 0) {
                        $duration = 0;
                    }
                    RequestLifecycleTrace::recordSpan(
                        $dbTraceLabel,
                        $duration,
                        'db',
                        null,
                        [
                            'sql' => $dbTraceSql,
                            'operation' => $traceOperation,
                            'table' => $traceTable,
                        ]
                    );
                }
            }
        } catch (\PDOException $e) {
            // PostgreSQL：任意 SQL 失败后连接进入 aborted 状态，必须 ROLLBACK 才能恢复；
            // 否则后续操作（含 BEGIN）均报 25P02。在 rethrow 前统一 rollBack 以恢复连接。
            $this->rollBack();
            throw $e;
        }
    }
    
    private function safeLastInsertId(): ?string
    {
        try {
            $lastId = $this->getLink()->lastInsertId();
        } catch (\PDOException $exception) {
            $code = (string)$exception->getCode();
            $message = (string)$exception->getMessage();
            if ($code === '55000' || \str_contains($message, 'lastval is not yet defined in this session')) {
                return null;
            }

            throw $exception;
        }

        if ($lastId === false || $lastId === '') {
            return null;
        }

        return (string)$lastId;
    }

    /**
     * 🔧 处理 fetch 结果（不调用 nextRowset）
     * find 只读一行；select/query 逐行读取并设上限，避免大结果集耗尽内存
     */
    protected function processFetchResult(string $model_class = ''): mixed
    {
        if ($this->PDOStatement === null) {
            return false;
        }

        $data = [];
        if ($this->fetch_type === 'find') {
            $row = $this->PDOStatement->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $data[] = $row;
            }
        } else {
            while (($row = $this->PDOStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $data[] = $row;
                if (count($data) >= self::MAX_FETCH_ROWS) {
                    $this->PDOStatement->closeCursor();
                    $this->PDOStatement = null;
                    $this->batch = false;
                    throw new DbException(
                        __('结果集超过 %{1} 行，为避免内存耗尽请使用 limit() 或 fetchIterator() 流式读取。', [self::MAX_FETCH_ROWS])
                    );
                }
            }
        }
        $this->PDOStatement->closeCursor();
        $this->PDOStatement = null;
        $this->batch = false;
        
        switch ($this->fetch_type) {
            case 'find':
                $result = array_shift($data);
                // 有 $this->find_fields 则返回数组
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
                // 只有在没有 find_fields 且需要模型对象时，才转换为模型对象
                if ($model_class) {
                    if (empty($result)) {
                        $result = ObjectManager::make($model_class, ['data' => []]);
                    } elseif (is_array($result)) {
                        $result = ObjectManager::make($model_class,['data' => $result]);
                    }
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
                        $lastId = $this->safeLastInsertId();
                        $result = $lastId !== false ? $lastId : (reset($data) ?? null);
                    }
                } else {
                    $lastId = $this->safeLastInsertId();
                    $result = $lastId !== false ? $lastId : null;
                }
                break;
            case 'pagination':
            case 'query':
            case 'select':
                // 只有在需要模型对象时才转换，否则直接返回数组
                if ($model_class && !empty($data)) {
                    $result = [];
                    foreach ($data as $datum) {
                        if (is_array($datum)) {
                            $result[] = ObjectManager::make($model_class, ['data' => $datum], '__construct');
                        } else {
                            $result[] = $datum;
                        }
                    }
                } else {
                    $result = $data;
                }
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
     * 从 limit 子句解析出数值（LIMIT n 或 LIMIT n OFFSET m）
     */
    protected function getLimitValue(): ?int
    {
        if ($this->limit === '') {
            return null;
        }
        if (preg_match('/\bLIMIT\s+(\d+)/i', $this->limit, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * 智能获取结果：小结果集用 fetch 返回数组，大结果集用 fetchIterator 流式迭代
     * 根据是否设置 LIMIT 及与阈值比较自动选择，调用方统一 foreach 即可。
     * 仅用于列表查询（select()->where(...)->fetchSmart()），勿用于 find()。
     *
     * @param int $threshold 行数阈值，limit 存在且 ≤ 此值时用 fetch，否则用 fetchIterator
     * @param string $model_class 模型类名，传空则返回关联数组
     * @param int $iteratorBatchSize fetchIterator 时的批大小，仅在大结果集时生效
     * @return array<int, array|object>|\Generator<int, array|object, mixed, void>
     */
    public function fetchSmart(
        int $threshold = 5000,
        string $model_class = '',
        int $iteratorBatchSize = 1
    ): array|\Generator {
        $limit = $this->getLimitValue();
        if ($limit !== null && $limit <= $threshold) {
            return $this->fetch($model_class) ?: [];
        }
        return $this->fetchIterator($model_class, $iteratorBatchSize);
    }

    /**
     * 流式迭代结果集，按行或按批 yield，用毕即关闭游标，降低内存占用
     * 仅适用于 select/query 类语句；大结果集请用此方法替代 fetch()/fetchArray()
     *
     * @param string $model_class 模型类名，传空则 yield 关联数组
     * @param int $batchSize 每批行数，1 表示逐行 yield，>1 时每批 yield 一个数组
     * @return \Generator<int, array|object, mixed, void>
     */
    public function fetchIterator(string $model_class = '', int $batchSize = 1): \Generator
    {
        $stmt = $this->PDOStatement;
        $needExecute = ($stmt === null && !empty($this->sql));
        if ($needExecute) {
            try {
                if ($this->hasMultipleSqlCommands($this->sql)) {
                    throw new DbException(__('fetchIterator 不支持多语句 SQL，请使用单条 SELECT'));
                }
                $stmt = $this->preparePgsql($this->sql);
                if ($stmt === false || !$stmt->execute($this->bound_values)) {
                    $err = $this->getLink()->errorInfo();
                    throw new DbException($err[2] ?? 'Execute failed');
                }
                $this->PDOStatement = $stmt;
            } catch (\PDOException $e) {
                $this->rollBack();
                throw $e;
            }
        }
        if ($stmt === null) {
            return;
        }
        try {
            $batch = [];
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($model_class && is_array($row)) {
                    $row = ObjectManager::make($model_class, ['data' => $row], '__construct');
                }
                if ($batchSize <= 1) {
                    yield $row;
                } else {
                    $batch[] = $row;
                    if (count($batch) >= $batchSize) {
                        yield $batch;
                        $batch = [];
                    }
                }
            }
            if ($batchSize > 1 && !empty($batch)) {
                yield $batch;
            }
        } finally {
            if ($this->PDOStatement !== null) {
                $this->PDOStatement->closeCursor();
                $this->PDOStatement = null;
            }
            $this->batch = false;
        }
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
                    
                    // 清除 exist_update_sql，让它构建普通的 INSERT 语句（不使用 ON CONFLICT）
                    $this->exist_update_sql = '';
                    
                    // 重新构建 SQL（使用自己的 prepareSql 方法，但这次不使用 ON CONFLICT）
                    $this->prepareSql('insert');
                    
                    // 重新执行
                    return parent::fetch($model_class);
                }
            }
            
            // 其他错误继续抛出
            throw $e;
        }
    }
    
}
