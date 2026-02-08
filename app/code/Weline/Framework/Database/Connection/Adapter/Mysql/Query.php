<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Mysql;

use PDO;
use PDOStatement;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Compiler\MysqlCompiler;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\SqlTrait;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Manager\ObjectManager;

abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\QueryAst
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

    public ?PDOStatement $PDOStatement = null;
    public string $sql = '';
    public string $additional_sql = '';

    public string $fetch_type = '';

    public array $pagination = ['page' => 1, 'pageSize' => 20, 'totalSize' => 0, 'lastPage' => 0];

    public string $backup_file = '';

    /**
     * 单条 SQL 允许绑定的最大参数数量（MySQL 侧安全阈值）
     * 真正上限依赖服务器配置，这里取一个相对保守的值做预防性检查。
     */
    protected int $maxParamsPerStatement = 60000;

    /**
     * 获取数据库连接
     */
    abstract public function getLink(): PDO;

    public function identity(string $field): QueryInterface
    {
        $this->identity_field = $field;
        return $this;
    }


    public function insertOld(array $data, array|string $update_fields = [], string $update_where_fields = '', bool $ignore_primary_key = false): QueryInterface
    {
        if (empty($data)) {
            throw new DbException('插入数据不能为空！');
        }
        if ($update_fields) {
            $this->exist_update_sql = 'ON DUPLICATE KEY UPDATE ';
            if (is_string($update_fields)) {
                $exist_update_fields = explode(',', $update_fields);
                $exist_update_fields = implode('`.`', $exist_update_fields);
                $this->exist_update_sql .= "`$exist_update_fields`=VALUES(`$exist_update_fields`),";
            } else {
                foreach ($update_fields as $field) {
                    $this->exist_update_sql .= "`$field`=VALUES(`$field`),";
                }
            }
            $this->exist_update_sql = trim($this->exist_update_sql, ',');
        }
        if (is_string(array_key_first($data))) {
            $this->insert[] = $data;
        } else {
            $this->insert = $data;
        }
        $fields = '(';
        if (count($this->insert)) {
            $first_insert = $this->insert[array_key_first($this->insert)];
            foreach ($first_insert as $field => $value) {
                $fields .= "`$field`,";
            }
        }
        $fields = rtrim($fields, ',') . ')';
        $origin_fields = $this->fields;
        $this->fields = $fields;
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

    public function join(string $table, string $condition, string $type = 'left'): QueryInterface
    {
        if (1 === count(func_get_args())) {
            $type = 'inner';
        }
        $this->joins[] = [$table, $condition, $type];
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
            $this->fields = implode(',', $fields);
        }
        return $this;
    }

    public function limit($size, $offset = 0): QueryInterface
    {
        $this->limit = " LIMIT $offset,$size";
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
        return $this;
    }

    public function order(string $field = '', string $sort = 'DESC'): QueryInterface
    {
        if (empty($field)) {
            $field = $this->identity_field;
        }
        if (!is_int(strpos($field, '`'))) {
            $field = $this->parserFiled($field);
        }
        $this->order[$field] = $sort;
        return $this;
    }

    public function group(string $fields): QueryInterface
    {
        $this->group_by = 'GROUP BY ' . $fields;
        return $this;
    }

    public function having(string $having): QueryInterface
    {
        $this->having = 'having ' . $having;
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

    public function additional(string $additional_sql): QueryInterface
    {
        $this->additional_sql = $additional_sql;
        return $this;
    }

    /**
     * 获取字段定义（MySQL 实现）
     */
    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        $configProvider = $this->connection->getConfigProvider();
        $schema = $configProvider->getDatabase();

        // 使用参数化查询从 information_schema 读取字段结构
        $sql = "SELECT * FROM information_schema.columns 
                WHERE table_schema = :schema 
                  AND table_name = :table 
                  AND column_name = :column";

        $pdo = $this->connection->getLink();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':schema' => $schema,
            ':table' => $tableName,
            ':column' => $fieldName,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        // 重置内部状态，避免影响后续查询
        $this->reset();

        return $row ?: null;
    }

    /**
     * 重写：使用 MysqlCompiler 将 AST 编译为 MySQL SQL
     * 实现 QueryAst 的抽象方法 prepareSql
     */
    protected function prepareSql(string $action): void
    {
        if ($this->table === '') {
            throw new DbException(__('没有指定table表名！'));
        }

        $this->reorderWhereByIndexes();
        $this->buildAst($action);

        $paramCount = count($this->bound_values);
        if ($paramCount > $this->maxParamsPerStatement) {
            throw new DbException(
                __(
                    'MySQL 单条 SQL 绑定参数数量（%{1}）超过方言安全阈值（%{2}）。请减少单次批量写入的记录数或改为分批写入。',
                    [$paramCount, $this->maxParamsPerStatement]
                )
            );
        }

        // 子查询 FROM 暂用原有编译逻辑（Compiler 后续可扩展支持）
        $from = $this->ast['from'] ?? [];
        if (!empty($from['is_subquery']) && !empty($from['subquery_id'])) {
            $this->sql = $this->compileAstToMysqlSql();
        } else {
            $compiler = new MysqlCompiler();
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

        $this->sql = preg_replace('/\s+/', ' ', trim($this->sql));

        if (!empty($this->sql)) {
            $this->PDOStatement = $this->getLink()->prepare($this->sql);
        } else {
            $this->PDOStatement = null;
        }
    }


    /**
     * 将 AST 编译成 MySQL SQL。
     */
    protected function compileAstToMysqlSql(): string
    {
        $action = $this->ast['action'] ?? 'select';

        // 格式化表名（使用 MySQL 反引号）
        $table = $this->formatTableNameForMysql($this->ast['from']['table'] ?? $this->table);
        $aliasName = $this->ast['from']['alias'] ?? $this->table_alias;
        $alias = $aliasName ? 'AS `' . $aliasName . '`' : '';

        // 构建各个 SQL 部分
        $joins   = $this->buildJoinsForMysql();
        $wheres  = $this->buildWheresForMysql();
        $order   = $this->buildOrderForMysql();
        $groupBy = $this->ast['group'] ? 'GROUP BY ' . $this->ast['group'] : '';
        $having  = $this->ast['having'] ? 'HAVING ' . $this->ast['having'] : '';
        $extra   = $this->ast['extra'] ?? $this->additional_sql;

        switch ($action) {
            case 'insert':
                return $this->buildInsertForMysql($table);
            case 'delete':
                return "DELETE FROM {$table} {$wheres} {$extra}";
            case 'update':
                return $this->buildUpdateForMysql($table, $wheres);
            case 'find':
            case 'select':
            default:
                // 格式化字段列表
                $fields = $this->formatFieldsForMysql($this->ast['select']['fields'] ?? $this->fields);
                return "SELECT {$fields} FROM {$table} {$alias} {$joins} {$wheres} {$groupBy} {$having} {$extra} {$order} {$this->limit}";
        }
    }


    /**
     * 格式化表名（MySQL 使用反引号）
     */
    protected function formatTableNameForMysql(string $table): string
    {
        // 如果已经是 `db`.`table` 这种标准格式，直接返回
        if (preg_match('/^`([^`]+)`\.`([^`]+)`$/', $table)) {
            return $table;
        }

        // 去掉所有引号，统一用裸表名来解析
        $raw = str_replace(['`', '"'], '', trim($table));

        // 表名不能为空
        if ($raw === '') {
            throw new DbException(__('表名不能为空'));
        }

        // 按点拆分，处理 db.table 或 table
        $parts = array_values(array_filter(array_map('trim', explode('.', $raw)), fn($p) => $p !== ''));

        if (empty($parts)) {
            throw new DbException(__('表名格式错误：%{1}', [$table]));
        }

        // 最多保留 db.table 两段，多余的视为表名一部分，取最后两段
        if (count($parts) > 2) {
            $parts = array_slice($parts, -2);
        }

        // 只有一个部分：视为当前数据库下的表
        if (count($parts) === 1) {
            return '`' . $parts[0] . '`';
        }

        // 两个部分：db.table
        return '`' . $parts[0] . '`.`' . $parts[1] . '`';
    }

    /**
     * 格式化字段列表（MySQL 使用反引号）
     */
    protected function formatFieldsForMysql(string $fields): string
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
                $formattedFields[] = "{$fieldExpr} AS `{$alias}`";
            } else {
                // 格式化字段表达式
                $formattedFields[] = $this->formatFieldExpression($field);
            }
        }
        
        return implode(', ', $formattedFields);
    }

    /**
     * 格式化字段表达式（处理 table.field 格式）
     */
    protected function formatFieldExpression(string $field): string
    {
        $field = trim($field);
        
        // 特殊处理 alias.* 的情况（MySQL 语法）
        if (preg_match('/^([^.]*?)\.\*$/', $field, $matches)) {
            $alias = trim($matches[1], '`"');

            // 兼容框架占位别名 main_table：如果实际主表别名不是 main_table，则将其替换为真实别名
            if ($alias === 'main_table' && !empty($this->table_alias) && $this->table_alias !== 'main_table') {
                $alias = $this->table_alias;
            }
            // 如果别名不为空，格式化别名并保留 .*
            if (!empty($alias)) {
                // 检查别名是否包含点号（可能是 db.table 格式）
                if (str_contains($alias, '.')) {
                    $aliasParts = explode('.', $alias);
                    return '`' . implode('`.`', $aliasParts) . '`.*';
                }
                return '`' . $alias . '`.*';
            }
            // 如果别名为空，返回 *
            return '*';
        }
        
        // 如果包含点号，说明是限定名（table.field 或 alias.field 格式）
        if (str_contains($field, '.')) {
            // 统一去掉内部的引号，然后再按点拆分并用 MySQL 风格的反引号包裹
            $field = str_replace(['`', '"'], '', $field);
            $parts = explode('.', $field);

            // 同样处理 main_table.xxx 这种占位别名，替换为真实主表别名
            if (!empty($this->table_alias) && $this->table_alias !== 'main_table' && isset($parts[0]) && $parts[0] === 'main_table') {
                $parts[0] = $this->table_alias;
            }
            return '`' . implode('`.`', $parts) . '`';
        }
        
        // 普通字段名：去掉首尾引号后再包一层 MySQL 风格反引号
        $field = trim($field, '`"');
        return '`' . $field . '`';
    }

    /**
     * 构建 JOIN 语句（MySQL 语法）
     */
    protected function buildJoinsForMysql(): string
    {
        if (empty($this->joins)) {
            return '';
        }
        
        $joins = '';
        foreach ($this->joins as $join) {
            // join[0] 里是类似 "m_role `r`" 或 "m_role r" 的字符串
            $tableWithAlias = trim($join[0]);
            $condition = $join[1];
            $type = strtoupper($join[2] ?? 'LEFT');

            // 按空格简单拆分表名和别名
            $rawTable = $tableWithAlias;
            $alias = '';
            // 直接按空格拆分，并过滤掉空字符串（多空格的情况）
            $parts = array_values(array_filter(explode(' ', $tableWithAlias), fn($p) => $p !== ''));
            if (count($parts) >= 2) {
                // 最后一个 token 视为别名（去掉反引号）
                $aliasToken = $parts[count($parts) - 1];
                $alias = trim($aliasToken, '`');
                // 其余部分还原成原始表名字符串
                $rawTable = implode(' ', array_slice($parts, 0, -1));
            }

            // 格式化表名（只对真正的表名部分做处理）
            $table = $this->formatTableNameForMysql($rawTable);
            $aliasSql = $alias ? ' AS `' . $alias . '`' : '';
            
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
                    return '`' . $firstPart . '`.`' . $secondPart . '`';
                }
                
                // 整体限定名格式 `table.field` 或 "table.field"
                if (str_contains($firstPart, '.')) {
                    $parts = explode('.', $firstPart);
                    return '`' . implode('`.`', $parts) . '`';
                }
                
                // 简单标识符
                return '`' . $firstPart . '`';
            },
            $condition
        );
        
        // 处理不带引号的限定名（如：table.field）
        $condition = preg_replace_callback(
            '/(?<![`"a-zA-Z0-9_])([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)(?![`"a-zA-Z0-9_])/',
            function ($matches) {
                return '`' . $matches[1] . '`.`' . $matches[2] . '`';
            },
            $condition
        );
        
        return $condition;
    }

    /**
     * 构建 WHERE 语句（MySQL 语法）
     */
    protected function buildWheresForMysql(): string
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
            $field = $where[0];
            if (is_string($field) && str_contains($field, '(')) {
                // 仅将双引号替换为反引号，其余保持原样
                $field = str_replace('"', '`', $field);
            } else {
                // 情况 2：普通字段或 table.field
                if (!str_contains((string)$field, '"') && !str_contains((string)$field, '`')) {
                    if (str_contains((string)$field, '.')) {
                        $parts = explode('.', (string)$field);
                        $field = '`' . implode('`.`', $parts) . '`';
                    } else {
                        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$field)) {
                            $field = '`' . $field . '`';
                        }
                    }
                } else {
                    // 已经带引号的情况：只把双引号替换为反引号，确保 MySQL 能识别
                    if (is_string($field)) {
                        $field = str_replace('"', '`', $field);
                    } else {
                        $field = (string)$field;
                    }
                }
            }
            
            $key += 1;
            // 获取当前条件的逻辑连接符，如果是最后一个条件则不使用
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
                    // 只有一个参数时，是 SQL 字符串
                    $sqlCondition = $where[0];
                    $wheres .= "({$sqlCondition})";
                    if (!$isLast) {
                        $wheres .= ' ' . $currentLogic;
                    }
                    break;
                default:
                    // IS NULL / IS NOT NULL 条件不需要绑定值
                    $lowerCondition = strtolower($where[1]);
                    if ($lowerCondition === 'is null' || $lowerCondition === 'is not null') {
                        $wheres .= '(' . $field . ' ' . strtoupper($where[1]) . ')';
                        if (!$isLast) {
                            $wheres .= ' ' . $currentLogic;
                        }
                        break;
                    }
                    
                    if ($where[2] === null) {
                        $wheres .= '(' . $field . ')';
                        if (!$isLast) {
                            $wheres .= ' ' . $currentLogic;
                        }
                        break;
                    }
                    
                    // 规范化字段名用于生成参数名
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
                        // 只有在不是最后一个条件时才添加逻辑连接符
                        if (!$isLast) {
                            if (!empty($currentLogic)) {
                                $wheres .= ' ' . $currentLogic;
                            }
                        }
                    }
            }
        }
        
        // 移除末尾的空格和逻辑连接符
        $wheres = trim($wheres);
        
        // 移除末尾的 " AND)" 或 " OR)"（带右括号的情况）
        $wheres = preg_replace('/\s+(AND|OR)\s*\)\s*$/i', ')', $wheres);
        
        // 移除末尾的 " AND" 或 " OR"（不带括号的情况）
        $wheres = preg_replace('/\s+(AND|OR)(\s*)$/i', '', $wheres);
        
        // 移除括号内的 " AND)" 或 " OR)"
        $wheres = preg_replace('/\s+(AND|OR)\s*\)/i', ')', $wheres);
        
        // 再次清理，确保没有残留
        $wheres = rtrim($wheres);
        
        // 如果末尾仍然有 AND 或 OR，循环移除
        while (preg_match('/\s+(AND|OR)(\s*)$/i', $wheres)) {
            $wheres = preg_replace('/\s+(AND|OR)(\s*)$/i', '', $wheres);
            $wheres = rtrim($wheres);
        }
        
        return $wheres;
    }

    /**
     * 构建 ORDER BY 语句（MySQL 语法）
     */
    protected function buildOrderForMysql(): string
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
                    $field = '`' . implode('`.`', $parts) . '`';
                } else {
                    $field = '`' . $field . '`';
                }
            } else {
                // 移除双引号，使用反引号（确保 $field 是字符串）
                if (is_string($field)) {
                    $field = str_replace('"', '`', $field);
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
     * 构建 INSERT 语句（MySQL 语法，支持批量插入和 ON DUPLICATE KEY UPDATE）
     */
    protected function buildInsertForMysql(string $table): string
    {
        // 处理 insert 数据
        $insert_items = $this->insert['insert'] ?? [];
        $insert_or_update_items = $this->insert['i_o_u'] ?? [];
        unset($this->insert['i_o_u'], $this->insert['origin'], $this->insert['insert']);
        
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
                $insert_fields_quoted = array_map(fn($field) => '`' . $field . '`', $insert_fields);
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
        $sql = $identity_inserts_sql;
        
        if (!empty($values)) {
            // 获取字段列表
            $firstInsertItem = reset($all_insert_items);
            if (!empty($firstInsertItem)) {
                $insert_fields = array_keys($firstInsertItem);
                $insert_fields_quoted = array_map(fn($field) => '`' . $field . '`', $insert_fields);
                $insert_fields_str = '(' . implode(',', $insert_fields_quoted) . ')';
                
                // MySQL 批量插入语法
                $sql .= "INSERT INTO {$table} {$insert_fields_str} VALUES {$values}";
                
                // 如果有 ON DUPLICATE KEY UPDATE 需求，添加 ON DUPLICATE KEY UPDATE 子句
                if (!empty($this->exist_update_sql)) {
                    $sql .= ' ' . $this->exist_update_sql;
                } elseif (!empty($this->insert_update_fields) || !empty($this->insert_update_where_fields)) {
                    // 如果没有设置 exist_update_sql，但设置了更新字段，自动生成 ON DUPLICATE KEY UPDATE
                    if (!empty($this->insert_update_fields)) {
                        $updateParts = [];
                        foreach ($this->insert_update_fields as $field) {
                            $field = trim((string)$field);
                            if ($field !== '' && in_array($field, $insert_fields, true)) {
                                $updateParts[] = "`{$field}`=VALUES(`{$field}`)";
                            }
                        }
                        if (!empty($updateParts)) {
                            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);
                        }
                    } else {
                        // 如果没有指定要更新的字段，更新所有字段（除了冲突检测字段和主键字段）
                        $updateParts = [];
                        foreach ($insert_fields as $field) {
                            // 跳过冲突检测字段
                            if (!empty($this->insert_update_where_fields) && in_array($field, $this->insert_update_where_fields, true)) {
                                continue;
                            }
                            // 跳过主键字段
                            if ($this->identity_field && $field === $this->identity_field) {
                                continue;
                            }
                            $updateParts[] = "`{$field}`=VALUES(`{$field}`)";
                        }
                        if (!empty($updateParts)) {
                            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);
                        }
                    }
                }
            }
        }
        
        return $sql;
    }

    /**
     * 构建 UPDATE 语句（MySQL 语法）
     */
    protected function buildUpdateForMysql(string $table, string $wheres): string
    {
        if (empty($wheres)) {
            throw new DbException(__('请设置更新条件'));
        }
        
        // 使用数组收集每个字段的更新表达式，避免对同一字段重复赋值
        $updateExpressions = [];
        
        // 处理 dec_inc_updates
        if (!empty($this->dec_inc_updates)) {
            foreach ($this->dec_inc_updates as $dec_inc_update_field => $dec_inc_update_value) {
                $field_quoted = '`' . $dec_inc_update_field . '`';
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
                $identity_field_quoted = '`' . $this->identity_field . '`';
                $wheres .= ($wheres ? ' AND ' : 'WHERE ') . "{$identity_field_quoted} IN ($identity_values_str)";
                
                // 使用 CASE WHEN 进行批量更新
                $keys = array_keys(current($this->updates));
                foreach ($keys as $column) {
                    if ($column === $this->identity_field) {
                        continue;
                    }
                    $column_quoted = '`' . $column . '`';
                    // 为当前列构建 CASE 表达式
                    $caseSql = sprintf("%s = CASE %s \n", $column_quoted, $identity_field_quoted);
                    
                    foreach ($this->updates as $update_key => $line) {
                        $update_key += 1;
                        $identity_field_column_key = ':' . md5("{$this->identity_field}_{$column}_key_{$update_key}");
                        $this->bound_values[$identity_field_column_key] = (string)$line[$this->identity_field];
                        $identity_field_column_value = ':' . md5("update_{$column}_value_{$update_key}");
                        $value = $line[$column] ?? null;
                        
                        // 根据类型处理值
                        if (is_bool($value)) {
                            $this->bound_values[$identity_field_column_value] = $value ? '1' : '0';
                            $caseSql .= sprintf('WHEN %s THEN %s ', $identity_field_column_key, $identity_field_column_value);
                        } else {
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
                    $update_field_quoted = '`' . $update_field . '`';
                    $this->bound_values[$update_key] = (string)$field_value;
                    // 单条更新时也通过数组覆盖，确保同一字段只有一个赋值
                    $updateExpressions[$update_field] = "{$update_field_quoted} = $update_key";
                }
            }
        }
        
        // 处理 single_updates
        if (!empty($this->single_updates)) {
            foreach ($this->single_updates as $update_field => $update_value) {
                $update_field_quoted = '`' . $update_field . '`';
                $update_key = ':' . md5($update_field);
                $this->bound_values[$update_key] = (string)$update_value;
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
            if (DEV && !isset(self::init_vars[$attr_var_name])) {
                $this->exceptionHandle(__('不支持的清理类型：%{1} 支持的初始化类型：%{2}', [$attr_var_name, var_export(self::init_vars, true)]));
            }
            $this->$attr_var_name = self::init_vars[$attr_var_name];
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
        $this->PDOStatement = null;
        return $this;
    }

    public function beginTransaction(): void
    {
        $this->getLink()->beginTransaction();
    }

    public function rollBack(): void
    {
        $this->getLink()->rollBack();
    }

    public function commit(): void
    {
        $this->getLink()->commit();
    }

    public function getPrepareSql(bool $format = true): string
    {
        if ($format) {
            return \SqlFormatter::format($this->sql);
        }
        return $this->sql;
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
        $PDOStatement = $this->getLink()->prepare("TRUNCATE TABLE $table");
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
        $PDOStatement = $this->getLink()->prepare("SHOW CREATE TABLE $table");
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
        $PDOStatement = $this->getLink()->prepare("SELECT * FROM $table");
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
