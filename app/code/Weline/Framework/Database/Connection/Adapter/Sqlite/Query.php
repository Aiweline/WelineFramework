<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Sqlite;

use PDO;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Compiler\SqliteCompiler;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * SQLite 查询构建器
 *
 * 继承自 QueryAst 的方法：
 * @method void reorderWhereByIndexes() 重新排序 where 条件（按索引优化）
 * @method void buildAst(string $action) 构建 AST 结构
 *
 * @see \Weline\Framework\Database\Connection\Api\Sql\QueryAst
 */
abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\QueryAst
{
    use SqlTrait;
    
    // 重试配置
    private const MAX_RETRY_ATTEMPTS = 5;
    private const RETRY_DELAY_MS = 100;

    public string $exist_update_sql = '';

    /**
     * 获取数据库连接
     */
    abstract public function getLink(): PDO;

    public function splitSqlStatements($sql)
    {
        // 正则表达式匹配不在引号内的分号
        $pattern = '/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/';

        // 使用正则表达式拆分SQL语句
        $statements = preg_split($pattern, $sql);

        // 去除每个语句的前后空格
        $statements = $statements ? array_map('trim', $statements) : [];

        // 过滤掉空语句
        return array_filter($statements);
    }

    public function fetch(string $model_class = ''): mixed
    {
        // Development SQL logging - log SQL with actual values
        try {
            if (Env::get('log.dev_sql.enabled', false)) {
                $log_file = Env::get('log.dev_sql.file', 'dev_sql');
                // Get SQL with bound values replaced
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($log_file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
        }
        
        // Database query logging - only if enabled
        try {
            if (Env::get('log.db.enabled', false)) {
                $file = Env::get('log.db.file', 'db');
                // Use compact standard format: [timestamp] [QUERY] source - SQL
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
        }
        if (Debug::target('custom')) {
            // 自定义调试类型信息
            Debug::target('custom', '我是调试信息！');
        }
        # 调试环境信息
        if (Debug::target('pre_fetch')) {
            $msg = __('即将执行信息：') . PHP_EOL;
            $msg .= '$this->batch:' . ($this->batch ? 'true' : 'false') . PHP_EOL;
            $msg .= '$this->fetch_type:' . $this->fetch_type . PHP_EOL;
            $msg .= '$this->sql:' . $this->sql . PHP_EOL;
            $msg .= '$this->bound_values:' . json_encode($this->bound_values) . PHP_EOL;
            Debug::target('pre_fetch', $msg);
        }
        // 防御：fetch_type 为空但 sql 已有时，根据 SQL 推断操作类型（避免链式操作中 query 被 reset/clear 后丢失类型）
        if ($this->fetch_type === '' && trim($this->sql) !== '') {
            $sqlUpper = strtoupper(ltrim(trim($this->sql)));
            if (str_starts_with($sqlUpper, 'INSERT')) {
                $this->fetch_type = 'insert';
            } elseif (str_starts_with($sqlUpper, 'SELECT')) {
                $this->fetch_type = 'select';
            } elseif (str_starts_with($sqlUpper, 'UPDATE')) {
                $this->fetch_type = 'update';
            } elseif (str_starts_with($sqlUpper, 'DELETE')) {
                $this->fetch_type = 'delete';
            }
        }
        if ($this->batch and $this->fetch_type == 'insert') {
            // 使用重试机制执行批量插入
            $origin_data = $this->executeWithRetry(function() {
                $result = $this->getLink()->exec($this->getSql());
                if ($result === false) {
                    return false;
                } else {
                    return $this->getLink()->lastInsertId();
                }
            });
            $this->reset();
        } else {
            # SQLITE 不支持多结果集：将SQL语句打散，并逐条执行后返回结果集
            $sql = $this->getSqlWithBounds($this->sql);
            $statements = $this->splitSqlStatements($sql);
            
            if (count($statements) == 1) {
                // 使用重试机制执行单条语句
                $origin_data = $this->executeWithRetry(function() {
                    $stmt = $this->getLink()->prepare($this->sql);
                    if ($stmt === false) {
                        $errorInfo = $this->getLink()->errorInfo();
                        $errorCode = $errorInfo[0] ?? '';
                        $errorMessage = $errorInfo[2] ?? '';
                        
                        // SQLite 变量限制错误
                        if (str_contains($errorMessage, 'too many SQL variables') || 
                            str_contains($errorMessage, 'SQLITE_MAX_VARIABLE_NUMBER')) {
                            throw new Exception(
                                __('SQLite 变量数量超过限制（最多999个变量）。请将批量操作拆分为更小的批次。SQL预览：%{1}', 
                                [substr($this->sql, 0, 200)])
                            );
                        }
                        
                        // 其他 PDO prepare 错误
                        throw new Exception(
                            __('PDO prepare 失败：%{1} (错误代码: %{2})。SQL预览：%{3}', 
                            [$errorMessage, $errorCode, substr($this->sql, 0, 200)])
                        );
                    }
                    $this->PDOStatement = $stmt;
                    $this->PDOStatement->execute($this->bound_values);
                    return $this->PDOStatement->fetchAll(PDO::FETCH_ASSOC);
                });
            } else {
                // 使用重试机制执行多条语句
                $origin_data = [];
                foreach ($statements as $statement) {
                    $state_res = $this->executeWithRetry(function() use ($statement) {
                        $result = $this->getLink()->exec($statement);
                        if ($result !== false) {
                            return $this->getLink()->lastInsertId();
                        }
                        return $result;
                    });
                    $origin_data[] = $state_res;
                }
            }
        }
        $this->batch = false;
        # sqlite 不支持多结果集
        $data = [];
        if ($model_class and is_array($origin_data)) {
            foreach ($origin_data as $origin_datum) {
                $data[] = ObjectManager::make($model_class, ['data' => $origin_datum], '__construct');
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
                                if (str_contains($field, '.')) {
                                    $field = explode('.', $field);
                                    $field = $field[1];
                                }
                                $fields_data[$field] = $result[$field] ?? null;
                            }
                            $result = $fields_data;
                        } else {
                            $field = $this->find_fields;
                            if (str_contains($field, '.')) {
                                $field = explode('.', $field);
                                $field = $field[1];
                            }
                            $result = $result[$field] ?? null;
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
                $result = $this->getLink()->lastInsertId();
                break;
            case 'query':
                if (str_contains($this->sql, 'PRAGMA table_info(')) {
                    # 表结构兼容转化
                    foreach ($data as &$datum) {
                        $datum['Field'] = $datum['name'];
                        $datum['Type'] = $datum['type'];
                        $datum['Null'] = $datum['notnull'] ? 'NO' : 'YES';
                        $datum['Key'] = $datum['pk'] ? 'PRI' : '';
                        $datum['Default'] = $datum['dflt_value'];
                        $datum['Extra'] = $datum['dflt_value'] ? 'DEFAULT' : '';
                        $datum['Comment'] = '';
                        $datum['Privileges'] = 'SELECT';
                    }
                }
            case 'select':
                $result = $data;
                break;
            case 'delete':
            case 'update':
                $result = (bool)$data;
                break;
            default:
                // fetch_type 仍为空时返回安全值，避免链式 insert()->fetch() 在部分环境下中断升级
                if ($this->fetch_type === '') {
                    $result = is_array($data) ? $data : [];
                } else {
                    throw new Exception(__('错误的获取类型。fetch之前必须有操作函数，操作函数包含（find,update,delete,select,query,insert,find）函数。'));
                }
                break;
        }
        $this->fetch_type = '';
        
        // Development SQL logging - log SQL with actual values
        try {
            if (Env::get('log.dev_sql.enabled', false)) {
                $log_file = Env::get('log.dev_sql.file', 'dev_sql');
                // Get SQL with bound values replaced
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($log_file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
        }
        
        // Database query logging - only if enabled
        try {
            if (Env::get('log.db.enabled', false)) {
                $file = Env::get('log.db.file', 'db');
                // Use compact standard format: [timestamp] [QUERY] source - SQL
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
        }
        # 调试环境信息
        if (Debug::target('fetch')) {
            $msg = __('执行后信息：') . PHP_EOL;
            $msg .= '$this->batch:' . ($this->batch ? 'true' : 'false') . PHP_EOL;
            $msg .= '$this->fetch_type:' . $this->fetch_type . PHP_EOL;
            $msg .= '$this->sql:' . $this->sql . PHP_EOL;
            $msg .= '$this->bound_values:' . json_encode($this->bound_values) . PHP_EOL;
            $msg .= __('查询结果:') . (is_string($result) ? $result : json_encode($result)) . PHP_EOL;
            Debug::target('fetch', $msg);
            exit(1);
        }
        //        $this->clear();
        $this->clearQuery();
        if ($this->table_alias !== 'main_table') $this->alias('main_table');
        //        $this->reset();
        return $result;
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
        $PDOStatement = $this->getLink()->prepare("DELETE FROM $table");
        $PDOStatement->execute();
        return $this;
    }

    public function query(string $sql): QueryInterface
    {
        $sql = self::formatSql($sql);
        $this->reset();
        $this->sql = $sql;
        $this->fetch_type = __FUNCTION__;
        $stmt = $this->getLink()->prepare($sql);
        if ($stmt === false) {
            $errorInfo = $this->getLink()->errorInfo();
            $errorCode = $errorInfo[0] ?? '';
            $errorMessage = $errorInfo[2] ?? '';

            // 统一抛出 PDO prepare 错误，批量变量过多的情况由上层在生成 SQL 时按批次拆分处理
            throw new Exception(
                __('PDO prepare 失败：%{1} (错误代码: %{2})。SQL预览：%{3}', 
                [$errorMessage, $errorCode, substr($sql, 0, 200)])
            );
        }
        $this->PDOStatement = $stmt;
        return $this;
    }

    /**
     * 🔧 重写：完全按照 SQLite 语法构建 SQL（方言逻辑集中在本类）
     * 先构建一个简单 AST，再按 SQLite 规则编译成 SQL。
     * 实现 QueryAst 的抽象方法 prepareSql
     */
    protected function prepareSql(string $action): void
    {
        if ($this->table === '') {
            throw new \Weline\Framework\Database\Exception\DbException(__('没有指定table表名！'));
        }

        $this->reorderWhereByIndexes();
        $this->buildAst($action);

        $from = $this->ast['from'] ?? [];
        if (!empty($from['is_subquery']) && !empty($from['subquery_id'])) {
            $this->sql = $this->compileAstToSqliteSql();
        } else {
            $compiler = new SqliteCompiler();
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

        $this->sql = preg_replace('/\s+/', ' ', $this->sql);
        $this->sql = trim($this->sql);

        if (!empty($this->sql)) {
            $stmt = $this->getLink()->prepare($this->sql);
            if ($stmt === false) {
                $err = $this->getLink()->errorInfo();
                throw new Exception(__('SQL 准备失败：%{1}。SQL: %{2}', [$err[2] ?? 'Unknown', substr($this->sql, 0, 200)]));
            }
            $this->PDOStatement = $stmt;
        } else {
            $this->PDOStatement = null;
        }
    }


    /**
     * 将 AST 编译成 SQLite SQL。
     */
    protected function compileAstToSqliteSql(): string
    {
        $action = $this->ast['action'] ?? 'select';

        // 格式化表名（使用 SQLite 双引号）
        $table = $this->formatTableNameForSqlite($this->ast['from']['table'] ?? $this->table);
        $aliasName = $this->ast['from']['alias'] ?? $this->table_alias;
        $alias = $aliasName ? 'AS "' . $aliasName . '"' : '';

        // 构建各个 SQL 部分
        $joins   = $this->buildJoinsForSqlite();
        $wheres  = $this->buildWheresForSqlite();
        $order   = $this->buildOrderForSqlite();
        $groupBy = $this->ast['group'] ? 'GROUP BY ' . $this->ast['group'] : '';
        $having  = $this->ast['having'] ? 'HAVING ' . $this->ast['having'] : '';
        $extra   = $this->ast['extra'] ?? $this->additional_sql;

        switch ($action) {
            case 'insert':
                return $this->buildInsertForSqlite($table);
            case 'delete':
                return "DELETE FROM {$table} {$wheres} {$extra}";
            case 'update':
                return $this->buildUpdateForSqlite($table, $wheres);
            case 'find':
            case 'select':
            default:
                // 格式化字段列表
                $fields = $this->formatFieldsForSqlite($this->ast['select']['fields'] ?? $this->fields);
                return "SELECT {$fields} FROM {$table} {$alias} {$joins} {$wheres} {$groupBy} {$having} {$extra} {$order} {$this->limit}";
        }
    }


    /**
     * 格式化表名（SQLite 使用双引号）
     */
    protected function formatTableNameForSqlite(string $table): string
    {
        // 如果已经是 "table" 这种标准格式，直接返回
        if (preg_match('/^"([^"]+)"$/', $table)) {
            return $table;
        }

        // 去掉所有引号，统一用裸表名来解析
        $raw = str_replace(['`', '"', '[', ']'], '', trim($table));

        // 表名不能为空
        if ($raw === '') {
            throw new \Weline\Framework\Database\Exception\DbException(__('表名不能为空'));
        }

        // 按点拆分，处理 db.table 或 table（SQLite 通常只有一个表名）
        $parts = array_values(array_filter(array_map('trim', explode('.', $raw)), fn($p) => $p !== ''));

        if (empty($parts)) {
            throw new \Weline\Framework\Database\Exception\DbException(__('表名格式错误：%{1}', [$table]));
        }

        // SQLite 通常只使用表名，不使用数据库前缀
        // 取最后一部分作为表名
        $tableName = end($parts);

        return '"' . $tableName . '"';
    }

    /**
     * 格式化字段列表（SQLite 使用双引号）
     */
    protected function formatFieldsForSqlite(string $fields): string
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
                $alias = trim($matches[3], '`"[]');
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
     */
    protected function formatFieldExpression(string $field): string
    {
        $field = trim($field);
        
        // 特殊处理 alias.* 的情况（SQLite 语法）
        if (preg_match('/^([^.]*?)\.\*$/', $field, $matches)) {
            $alias = trim($matches[1], '`"[]');

            // 兼容框架占位别名 main_table：如果实际主表别名不是 main_table，则将其替换为真实别名
            if ($alias === 'main_table' && !empty($this->table_alias) && $this->table_alias !== 'main_table') {
                $alias = $this->table_alias;
            }
            // 如果别名不为空，格式化别名并保留 .*
            if (!empty($alias)) {
                return '"' . $alias . '".*';
            }
            // 如果别名为空，返回 *
            return '*';
        }
        
        // 如果包含点号，说明是限定名（table.field 或 alias.field 格式）
        if (str_contains($field, '.')) {
            // 统一去掉内部的引号，然后再按点拆分并用 SQLite 风格的双引号包裹
            $field = str_replace(['`', '"', '[', ']'], '', $field);
            $parts = explode('.', $field);

            // 同样处理 main_table.xxx 这种占位别名，替换为真实主表别名
            if (!empty($this->table_alias) && $this->table_alias !== 'main_table' && isset($parts[0]) && $parts[0] === 'main_table') {
                $parts[0] = $this->table_alias;
            }
            return '"' . implode('"."', $parts) . '"';
        }
        
        // 普通字段名：去掉首尾引号后再包一层 SQLite 风格双引号
        $field = trim($field, '`"[]');
        return '"' . $field . '"';
    }

    /**
     * 构建 JOIN 语句（SQLite 语法）
     */
    protected function buildJoinsForSqlite(): string
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
                // 最后一个 token 视为别名（去掉引号）
                $aliasToken = $parts[count($parts) - 1];
                $alias = trim($aliasToken, '`"[]');
                // 其余部分还原成原始表名字符串
                $rawTable = implode(' ', array_slice($parts, 0, -1));
            }

            // 格式化表名（只对真正的表名部分做处理）
            $table = $this->formatTableNameForSqlite($rawTable);
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
            '/([`"\[\]])([^`"\[\]]+)\1(?:\.([`"\[\]])([^`"\[\]]+)\3)?/',
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
            '/(?<![`"\[\]a-zA-Z0-9_])([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)(?![`"\[\]a-zA-Z0-9_])/',
            function ($matches) {
                return '"' . $matches[1] . '"."' . $matches[2] . '"';
            },
            $condition
        );
        
        return $condition;
    }

    /**
     * 获取字段定义（SQLite 实现）
     */
    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        // SQLite 使用 PRAGMA table_info
        // 这里不依赖 Connector::processName，直接使用原始表名，交给 SQLite 自身处理
        $sql = "PRAGMA table_info('{$tableName}')";
        // 通过连接器的 query 接口执行，避免直接访问底层 PDO
        $rows = $this->connection->query($sql)->fetchArray();

        foreach ($rows as $info) {
            if (($info['name'] ?? '') === $fieldName) {
                $this->reset();
                return $info;
            }
        }

        $this->reset();
        return null;
    }
    
    /**
     * 构建 WHERE 语句（SQLite 语法）
     */
    protected function buildWheresForSqlite(): string
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
                // 仅将反引号替换为双引号，其余保持原样
                $field = str_replace(['`', '['], '"', $field);
                $field = str_replace(']', '"', $field);
            } else {
                // 情况 2：普通字段或 table.field
                if (!str_contains((string)$field, '"') && !str_contains((string)$field, '`') && !str_contains((string)$field, '[')) {
                    if (str_contains((string)$field, '.')) {
                        $parts = explode('.', (string)$field);
                        $field = '"' . implode('"."', $parts) . '"';
                    } else {
                        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$field)) {
                            $field = '"' . $field . '"';
                        }
                    }
                } else {
                    // 已经带引号的情况：只把反引号和方括号替换为双引号，确保 SQLite 能识别
                    if (is_string($field)) {
                        $field = str_replace(['`', '['], '"', $field);
                        $field = str_replace(']', '"', $field);
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
                    // 🔧 修复：规范化条件字符串（去除多余空格，转小写）
                    $conditionStr = $where[1] ?? '';
                    $lowerCondition = strtolower(trim(preg_replace('/\s+/', ' ', $conditionStr)));
                    
                    // 🔧 优化：使用 str_contains 检测 IS NULL 变体，更健壮
                    $isNullCondition = ($lowerCondition === 'is null' || $lowerCondition === 'is not null');
                    if (!$isNullCondition && str_contains($lowerCondition, 'is') && str_contains($lowerCondition, 'null')) {
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
                    
                    // 🔧 修复：值为 null 时统一使用 IS NULL 语义
                    if ($where[2] === null) {
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
                    
                    // 规范化字段名用于生成参数名（移除引号和特殊字符）
                    $normalized_field = str_replace(['`', '"', '[', ']'], '', (string)$field);
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
     * 构建 ORDER BY 语句（SQLite 语法）
     */
    protected function buildOrderForSqlite(): string
    {
        if (empty($this->order)) {
            return '';
        }
        
        $order = '';
        foreach ($this->order as $field => $dir) {
            // 格式化字段名
            if (!str_contains($field, '"') && !str_contains($field, '`') && !str_contains($field, '[')) {
                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $field = '"' . implode('"."', $parts) . '"';
                } else {
                    $field = '"' . $field . '"';
                }
            } else {
                // 移除反引号和方括号，使用双引号（确保 $field 是字符串）
                if (is_string($field)) {
                    $field = str_replace(['`', '['], '"', $field);
                    $field = str_replace(']', '"', $field);
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
     * 构建 INSERT 语句（SQLite 语法，支持批量插入和 ON CONFLICT）
     */
    protected function buildInsertForSqlite(string $table): string
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
        $sql = $identity_inserts_sql;
        
        if (!empty($values)) {
            // 获取字段列表
            $firstInsertItem = reset($all_insert_items);
            if (!empty($firstInsertItem)) {
                $insert_fields = array_keys($firstInsertItem);
                $insert_fields_quoted = array_map(fn($field) => '"' . $field . '"', $insert_fields);
                $insert_fields_str = '(' . implode(',', $insert_fields_quoted) . ')';
                
                // SQLite 批量插入语法
                $sql .= "INSERT INTO {$table} {$insert_fields_str} VALUES {$values}";
                
                // 如果有 ON CONFLICT 需求，添加 ON CONFLICT 子句（SQLite 支持 ON CONFLICT）
                if (!empty($this->exist_update_sql)) {
                    // 构建冲突字段列表（仅使用真实存在于插入字段中的列）
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
                        // 如果 exist_update_sql 是 'DO UPDATE SET ALL_FIELDS'，生成所有字段的更新语句
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
                } elseif (!empty($this->insert_update_fields) || !empty($this->insert_update_where_fields)) {
                    // 如果没有设置 exist_update_sql，但设置了更新字段，自动生成 ON CONFLICT
                    if (!empty($this->insert_update_where_fields)) {
                        $conflictFields = [];
                        foreach ($this->insert_update_where_fields as $field) {
                            $field = trim((string)$field);
                            if ($field !== '' && in_array($field, $insert_fields, true)) {
                                $conflictFields[] = '"' . $field . '"';
                            }
                        }
                        if (!empty($conflictFields)) {
                            if (!empty($this->insert_update_fields)) {
                                $updateParts = [];
                                foreach ($this->insert_update_fields as $field) {
                                    $field = trim((string)$field);
                                    if ($field !== '' && in_array($field, $insert_fields, true)) {
                                        $updateParts[] = "\"{$field}\"=EXCLUDED.\"{$field}\"";
                                    }
                                }
                                if (!empty($updateParts)) {
                                    $sql .= ' ON CONFLICT (' . implode(', ', $conflictFields) . ') DO UPDATE SET ' . implode(', ', $updateParts);
                                }
                            } else {
                                // 如果没有指定要更新的字段，更新所有字段（除了冲突检测字段和主键字段）
                                $updateParts = [];
                                foreach ($insert_fields as $field) {
                                    if (in_array($field, $this->insert_update_where_fields, true)) {
                                        continue;
                                    }
                                    if ($this->identity_field && $field === $this->identity_field) {
                                        continue;
                                    }
                                    $updateParts[] = "\"{$field}\"=EXCLUDED.\"{$field}\"";
                                }
                                if (!empty($updateParts)) {
                                    $sql .= ' ON CONFLICT (' . implode(', ', $conflictFields) . ') DO UPDATE SET ' . implode(', ', $updateParts);
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $sql;
    }

    /**
     * 构建 UPDATE 语句（SQLite 语法）
     */
    protected function buildUpdateForSqlite(string $table, string $wheres): string
    {
        if (empty($wheres)) {
            throw new \Weline\Framework\Database\Exception\DbException(__('请设置更新条件'));
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
                $keys = array_keys(current($this->updates));
                foreach ($keys as $column) {
                    if ($column === $this->identity_field) {
                        continue;
                    }
                    $column_quoted = '"' . $column . '"';
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
                    $update_field_quoted = '"' . $update_field . '"';
                    $this->bound_values[$update_key] = (string)$field_value;
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
                $this->bound_values[$update_key] = (string)$update_value;
                // single_updates 的值优先级最高，覆盖前面的表达式
                $updateExpressions[$update_field] = "{$update_field_quoted}=$update_key";
            }
        }
        
        if (empty($updateExpressions)) {
            throw new \Weline\Framework\Database\Exception\DbException(__('没有要更新的字段'));
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

    /**
     * 执行带重试机制的数据库操作
     */
    protected function executeWithRetry(callable $operation, array $params = []): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                return call_user_func_array($operation, $params);
                
            } catch (\PDOException $e) {
                $lastException = $e;
                $attempts++;
                
                // 如果是数据库锁定错误，进行重试
                if ($this->isDatabaseLockedError($e) && $attempts < self::MAX_RETRY_ATTEMPTS) {
                    $this->waitBeforeRetry($attempts);
                    continue;
                }
                
                // 如果不是锁定错误或达到最大重试次数，抛出异常
                break;
            }
        }

        throw new Exception("数据库操作失败，已重试 {$attempts} 次。最后错误: " . $lastException->getMessage());
    }

    /**
     * 检查是否是数据库锁定错误
     */
    protected function isDatabaseLockedError(\PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'database is locked') || 
               str_contains($message, 'database table is locked') ||
               str_contains($message, 'sqlite_busy') ||
               $e->getCode() === 5; // SQLITE_BUSY
    }

    /**
     * 重试前等待
     */
    protected function waitBeforeRetry(int $attempt): void
    {
        // 指数退避算法：每次重试等待时间递增
        $delay = self::RETRY_DELAY_MS * pow(2, $attempt - 1);
        $maxDelay = 1000; // 最大延迟1秒
        $delay = min($delay, $maxDelay);
        
        // 添加随机抖动避免惊群效应
        $jitter = rand(0, intval($delay * 0.1));
        $delay += $jitter;
        
        SchedulerSystem::usleep($delay * 1000); // 转换为微秒
    }

    /**
     * 归档数据 - SQLite 兼容版本
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
                $this->where("date({$field}) = date('now')");
                break;
            case 'yesterday':
            case 'last_day':
                #昨天
                $this->where("date({$field}) = date('now', '-1 day')");
                break;
            case 'the_day_{number}_days_ago':
                #提取数字指定几天前的那一天
                $this->where("date({$field}) = date('now', '-{$period_number} day')");
                break;
            case 'current_week':
                #查询当前这周的数据
                $this->where("strftime('%Y%W', {$field}) = strftime('%Y%W', 'now')");
                break;
            case 'near_week':
                #近7天
                $this->where("date('now', '-7 day') <= date({$field})");
                break;
            case 'last_week':
                #查询上周的数据
                $this->where("strftime('%Y%W', {$field}) = strftime('%Y%W', date('now', '-7 day'))");
                break;
            case 'the_week_{number}_weeks_ago':
                #提取数字指定几周之前的那个周
                $daysAgo = $period_number * 7;
                $this->where("strftime('%Y%W', {$field}) = strftime('%Y%W', date('now', '-{$daysAgo} day'))");
                break;
            case 'near_month':
                #近30天
                $this->where("date('now', '-30 day') <= date({$field})");
                break;
            case 'current_month':
                # 本月
                $this->where("strftime('%Y%m', {$field}) = strftime('%Y%m', 'now')");
                break;
            case 'last_month':
                #上一月
                $this->where("strftime('%Y%m', {$field}) = strftime('%Y%m', date('now', 'start of month', '-1 month'))");
                break;
            case 'the_month_{number}_months_ago':
                #提取数字指定几个月份之前的月份
                $this->where("strftime('%Y%m', {$field}) = strftime('%Y%m', date('now', 'start of month', '-{$period_number} month'))");
                break;
            case 'quarter':
                #查询本季度数据
                $currentMonth = "CAST(strftime('%m', 'now') AS INTEGER)";
                $quarterStart = "(({$currentMonth} - 1) / 3 * 3 + 1)";
                $this->where("CAST(strftime('%m', {$field}) AS INTEGER) >= {$quarterStart} AND CAST(strftime('%m', {$field}) AS INTEGER) < ({$quarterStart} + 3) AND strftime('%Y', {$field}) = strftime('%Y', 'now')");
                break;
            case 'last_quarter':
                #查询上季度数据
                $lastQuarterMonth = "CAST(strftime('%m', date('now', '-3 month')) AS INTEGER)";
                $quarterStart = "(({$lastQuarterMonth} - 1) / 3 * 3 + 1)";
                $this->where("CAST(strftime('%m', {$field}) AS INTEGER) >= {$quarterStart} AND CAST(strftime('%m', {$field}) AS INTEGER) < ({$quarterStart} + 3) AND strftime('%Y', {$field}) = strftime('%Y', date('now', '-3 month'))");
                break;
            case 'the_quarter_{number}_quarters_ago':
                #提取数字指定几个季度前那个季度
                $monthsAgo = $period_number * 3;
                $targetMonth = "CAST(strftime('%m', date('now', '-{$monthsAgo} month')) AS INTEGER)";
                $quarterStart = "(({$targetMonth} - 1) / 3 * 3 + 1)";
                $this->where("CAST(strftime('%m', {$field}) AS INTEGER) >= {$quarterStart} AND CAST(strftime('%m', {$field}) AS INTEGER) < ({$quarterStart} + 3) AND strftime('%Y', {$field}) = strftime('%Y', date('now', '-{$monthsAgo} month'))");
                break;
            case 'current_year':
                #查询本年数据
                $this->where("strftime('%Y', {$field}) = strftime('%Y', 'now')");
                break;
            case 'last_year':
                #查询上年数据
                $this->where("strftime('%Y', {$field}) = strftime('%Y', date('now', '-1 year'))");
                break;
            case 'the_year_{number}_years_ago':
                #提取数字指定几年前的那年
                $this->where("strftime('%Y', {$field}) = strftime('%Y', date('now', '-{$period_number} year'))");
                break;
            default:
        }
        return $this;
    }
}
