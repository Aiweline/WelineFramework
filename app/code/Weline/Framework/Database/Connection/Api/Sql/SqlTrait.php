<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Api\Sql;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\ConnectionInterface as DbConnectionInterface;
use Weline\Framework\Database\Exception\DbException;

trait SqlTrait
{
    /**
     * parserFiled 解析结果缓存（进程级，纯函数式，WLS 下无需跨请求重置）
     * @var array<string, string>
     */
    private static array $parserFiledCache = [];

    /**
     * AST 结构变量声明
     * 用于 IDE 类型提示，确保 AST 结构完整
     * 
     * AST 完整结构说明：
     * - action: 操作类型 ('select'|'insert'|'update'|'delete'|'find')
     * - from: FROM 子句信息，包含表名、别名，以及子查询标记
     * - select: SELECT 字段信息
     * - joins: JOIN 连接信息数组
     * - where: WHERE 条件数组
     * - group: GROUP BY 子句
     * - having: HAVING 子句
     * - order: ORDER BY 排序数组
     * - limit: LIMIT 限制字符串
     * - extra: 额外的 SQL 片段
     * - insert: INSERT 数据数组
     * - update: UPDATE 数据，包含 single 和 batch 两部分
     * - subqueries: 子查询 AST 结构映射（key 为子查询 ID，value 为子查询的 AST）
     * - index_sort_keys: 索引排序键数组
     * - unit_primary_keys: 联合主键数组
     * 
     * @var array{
     *   action?: string,
     *   from?: array{table?: string, alias?: string, is_subquery?: bool, subquery_id?: string},
     *   select?: array{fields?: string},
     *   joins?: array,
     *   where?: array,
     *   group?: string,
     *   having?: string,
     *   order?: array,
     *   limit?: string,
     *   extra?: string,
     *   insert?: array,
     *   update?: array{single?: array, batch?: array},
     *   subqueries?: array<string, array>,
     *   index_sort_keys?: array,
     *   unit_primary_keys?: array
     * }
     */
    protected array $ast = [];
    
    /**
     * 子查询计数器，用于生成唯一的子查询标识
     * @var int
     */
    protected int $subquery_counter = 0;
    /** @var array */
    public array $conditions = [
        '>',
        '<',
        '>=',
        '!=',
        '<=',
        '<>',
        'like',
        'not like',
        'in',
        'not in',
        'find_in_set',
        '=',
        'is null',
        'is not null',
    ];
    private static $special_fields = [
        'order',
        'key',
        'table',
        'fields',
    ];
    /** 未调用 setConnector/setConnection 前为 null，避免 typed property 未初始化致命错误 */
    public ?ConnectorInterface $connection = null;
    public string $db_name = 'default';

    public function __sleep()
    {
        return ['db_name', 'connection'];
    }


    public function getTable($table_name): string
    {
        return $this->tableNameStrategy->resolve((string)$table_name, $this->db_name);
    }


    /**
     * @DESC          | 获取链接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 21:10
     *
     * @return ConnectorInterface
     */
    public function getConnection(): ConnectorInterface
    {
        if ($this->connection === null) {
            throw new DbException(__('数据库连接未设置，请先调用 setConnector() 或 setConnection()。'));
        }
        return $this->connection;
    }

    public function getConnector(): ConnectorInterface
    {
        if ($this->connection === null) {
            throw new DbException(__('数据库连接未设置，请先调用 setConnector() 或 setConnection()。'));
        }
        return $this->connection;
    }

    /**
     * @DESC          | 设置链接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 21:10
     *
     * @param ConnectorInterface $connection
     */
    public function setConnection(ConnectorInterface $connection): void
    {
        $this->connection = $connection;
    }

    public function setConnector(ConnectorInterface $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * 获取封装后的数据库连接（推荐在框架内部使用，替代 getLink）
     * @since 1.0.0
     */
    public function getConnectionInterface(): DbConnectionInterface
    {
        if ($this->connection === null) {
            throw new DbException(__('数据库连接未设置，请先调用 setConnector() 或 setConnection()。'));
        }
        return $this->connection->getWrappedConnection();
    }


    /**
     * @DESC          |  # 检测条件数组 下角标 必须为数字
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 22:39
     * 参数区：
     *
     * @param array $where_array
     * @param mixed $f_key
     *
     * @throws null
     */
    private function checkWhereArray(array $where_array, mixed $f_key): void
    {
        foreach ($where_array as $f_item_key => $f_item_value) {
            if (!is_numeric($f_item_key)) {
                $this->exceptionHandle(__('Where查询异常：%{1},%{2},%{3}', ["第{$f_key}个条件数组错误", '出错的数组：["' . implode('","', $where_array) . '"]', "示例：where([['name','like','%张三%','or'],['name','like','%李四%']])"]));
            }
        }
    }

    /**
     * @DESC          | 检测条件参数是否正确
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 22:30
     * 参数区：
     *
     * @param array $where_array
     *
     * @return string
     * @throws null
     */
    private function checkConditionString(array $where_array): string
    {
        if (in_array(strtolower($where_array[1]), $this->conditions)) {
            return $where_array[1];
        } else {
            $this->exceptionHandle(__('当前错误的条件操作符：%{1} ,当前的条件数组：%{2}, 允许的条件符：%{3}', [$where_array[1], '["' . implode('","', $where_array) . '"]', '["' . implode('","', $this->conditions) . '"]']));
        }
    }

    /**
     * @DESC          # 准备sql
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/17 22:52
     * 参数区：
     * @throws null
     * 
     * @deprecated 此方法已被各个适配器的 prepareSql 方法覆盖，不再使用 DialectAdapter。
     *             各个适配器（Mysql, Pgsql, Sqlite）现在都有自己的 SQL 构建逻辑。
     *             此方法保留仅为了兼容性，实际不会被调用（各个适配器都覆盖了此方法）。
     */
    private function prepareSql($action): void
    {
        // 此方法已被各个适配器覆盖，不会被执行
        // 保留此方法仅为了兼容性，实际不会被调用
        // 如果意外调用到此方法，抛出异常提示
        throw new \RuntimeException(__('prepareSql 方法已被各个适配器覆盖，不应该调用 trait 中的方法。请确保适配器正确实现了 prepareSql 方法。'));
    }


    public function getPrepareSql(bool $format = false): string
    {
        if ($format) {
            return \SqlFormatter::format($this->sql);
        }
        return $this->sql;
    }

    public function quote(string $string): string|false
    {
        return $this->getLink()->quote($string);
    }

    /**
     * @DESC          # 解析数组键
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/25 22:34
     * 参数区：
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
            $orig = $field;
            if (isset(self::$parserFiledCache[$orig])) {
                $field = self::$parserFiledCache[$orig];
                return $field;
            }
            # 以()号隔开
            if (str_contains($field, '(')) {
                $field = explode('(', $field);
                foreach ($field as &$f) {
                    $f = self::parserFiled($f);
                }
                $field = implode('(', $field);
                self::$parserFiledCache[$orig] = $field;
                return $field;
            }
            if (str_contains($field, ')')) {
                $field = explode(')', $field);
                foreach ($field as &$f) {
                    $f = self::parserFiled($f);
                }
                $field = implode(')', $field);
                self::$parserFiledCache[$orig] = $field;
                return $field;
            }
            # 以逗号隔开
            if (str_contains($field, ',')) {
                $field = explode(',', $field);
                foreach ($field as &$f) {
                    $f = self::parserFiled($f);
                }
                $field = implode(',', $field);
                if (str_contains($field, '``')) {
                    $field = str_replace('``', '`', $field);
                }
                self::$parserFiledCache[$orig] = $field;
                return $field;
            }
            if (str_starts_with($field, '"') || str_starts_with($field, "'")) {
                if (str_contains($field, '``')) {
                    $field = str_replace('``', '`', $field);
                }
                self::$parserFiledCache[$orig] = $field;
                return $field;
            }
            # 如果没有空格，也没有.和等于符号【单纯字段】直接加上·
            if (!str_contains($field, ' ') && !str_contains($field, '=')) {
//                $field = str_replace('`', '', $field);
                if (str_contains($field, '.')) {
                    $field = '`' . str_replace('.', '`.`', $field) . '`';
                }
                if (str_contains($field, '``')) {
                    $field = str_replace('``', '`', $field);
                }
                $field = str_replace('`*`', '*', $field);
                self::$parserFiledCache[$orig] = $field;
                return $field;
            }
            $field = preg_replace('/\s+/', ' ', $field);
//            $field = str_replace('`', '', $field);
            # 解决类似`main_table`.`parent_source is null的问题
            $field_arr = explode(' ', $field);
            foreach ($field_arr as $field_arr_key => $field_arr_value) {
                if (strtolower($field_arr_value) == 'as') {
                    if (isset($field_arr[$field_arr_key + 1])) {
                        $field_arr[$field_arr_key + 1] = '`' . $field_arr[$field_arr_key + 1] . '`';
                    }
                }
                if (str_contains($field_arr_value, '.')) {
                    if (str_contains($field_arr_value, '=')) {
                        $field_arr_value_arr = explode('=', $field_arr_value);
                        $field_arr_value_arr[0] = self::parserFiled($field_arr_value_arr[0]);
                        $field_arr_value_arr[1] = self::parserFiled($field_arr_value_arr[1]);
                        $field_arr_value = implode('=', $field_arr_value_arr);
                    } else {
                        $field_arr_value = '`' . str_replace('.', '`.`', $field_arr_value) . '`';
                    }
                    $field_arr_value = str_replace('``', '`', $field_arr_value);
                    $field_arr[$field_arr_key] = $field_arr_value;
                }
            }
            $field = implode(' ', $field_arr);
            $field = str_replace('`*`', '*', $field);
            self::$parserFiledCache[$orig] = $field;
        } elseif (is_array($field)) {
            foreach ($field as $field_key => $value) {
                unset($field[$field_key]);
                $field_key = self::parserFiled($field_key);
                $field[$field_key] = $value;
            }
        }
        if (is_string($field) && str_contains($field, '``')) {
            $field = str_replace('``', '`', $field);
        }
        return $field;
    }

    /**
     * @DESC          # 异常函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/23 21:28
     * 参数区：
     *
     * @param $words
     *
     * @throws DbException
     */
    protected function exceptionHandle($words)
    {
        throw new DbException($words);
    }

    protected function getSqlWithBounds(string $sql, array $bindings = [], bool $format = false): string
    {
        if (empty($bindings)) {
            $bindings = $this->bound_values;
        }
        foreach ($bindings as $key => $binding) {
            if (is_string($binding)) {
                $binding = $this->quote($binding);
            }
            $sql = str_replace($key, (string)$binding, $sql);
        }
        if ($format) {
            return \SqlFormatter::format($sql);
        }
        return $sql;
    }


    /**
     * @param string $sql
     * @return string|string[]
     */
    protected static function formatSql(string $sql): string|array
    {
        // formatSql is now simplified - logging moved to fetch() for actual values
        return $sql;
    }

    /**
     * 清理 AST 值：移除方言特定的引号，保持操作结构干净
     * AST 是操作结构，不应该包含方言特定的语法信息（如引号）
     * 
     * @param string $value 可能包含引号的表名或字段表达式
     * @return string 清理后的干净值
     */
    protected function cleanAstValue(string $value): string
    {
        $value = trim($value);
        // 移除所有可能的引号（MySQL 反引号、PostgreSQL 双引号、SQL Server 方括号等）
        $value = str_replace(['`', '"', '[', ']'], '', $value);
        return $value;
    }

    /**
     * 清理 AST 字段表达式：移除引号但保留函数调用和结构
     * AST 是操作结构，不应该包含方言特定的语法信息（如引号）
     * 
     * @param string $fields 可能包含引号的字段表达式
     * @return string 清理后的干净字段表达式
     */
    protected function cleanAstFields(string $fields): string
    {
        $fields = trim($fields);
        
        // 如果是 *，直接返回
        if ($fields === '*') {
            return $fields;
        }
        
        // 处理逗号分隔的多个字段
        if (str_contains($fields, ',')) {
            $fieldList = array_map('trim', explode(',', $fields));
            $cleanedFields = [];
            foreach ($fieldList as $field) {
                $cleanedFields[] = $this->cleanSingleField($field);
            }
            return implode(', ', $cleanedFields);
        }
        
        // 单个字段
        return $this->cleanSingleField($fields);
    }
    
    /**
     * 清理单个字段表达式
     * 
     * @param string $field 单个字段表达式
     * @return string 清理后的字段表达式
     */
    protected function cleanSingleField(string $field): string
    {
        $field = trim($field);
        
        // 处理 AS 别名的情况：expression AS alias
        if (preg_match('/^(.+?)\s+(AS|as)\s+(.+)$/i', $field, $matches)) {
            $expr = trim($matches[1]);
            $alias = trim($matches[3]);
            // 清理表达式和别名中的引号
            $expr = $this->cleanFieldExpression($expr);
            $alias = $this->cleanAstValue($alias);
            return $expr . ' AS ' . $alias;
        }
        
        // 处理函数调用：count(*), sum(field) 等
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)/i', $field, $funcMatches)) {
            $funcName = $funcMatches[1];
            $funcParams = $funcMatches[2];
            // 清理参数中的引号，但保留函数结构
            $funcParams = str_replace(['`', '"', '[', ']'], '', $funcParams);
            return $funcName . '(' . $funcParams . ')';
        }
        
        // 普通字段，清理引号
        return $this->cleanFieldExpression($field);
    }
    
    /**
     * 清理字段表达式（处理 table.field 格式）
     * 
     * @param string $field 字段表达式
     * @return string 清理后的字段表达式
     */
    protected function cleanFieldExpression(string $field): string
    {
        $field = trim($field);
        // 移除所有可能的引号
        $field = str_replace(['`', '"', '[', ']'], '', $field);
        return $field;
    }

    /**
     * AST 收集方法 - 更新 AST 中的表信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     * AST 中存储的表名应该是干净的，不包含方言特定的引号
     */
    protected function updateAstTable(string $table, string $alias = ''): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        if (!isset($this->ast['from'])) {
            $this->ast['from'] = [];
        }
        // 🔧 清理表名：AST 是操作结构，不应该包含引号等方言信息
        $this->ast['from']['table'] = $this->cleanAstValue($table);
        if ($alias) {
            $this->ast['from']['alias'] = $this->cleanAstValue($alias);
        } elseif (isset($this->table_alias)) {
            $this->ast['from']['alias'] = $this->cleanAstValue($this->table_alias);
        }
    }

    /**
     * AST 收集方法 - 更新 AST 中的字段信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     * AST 中存储的字段表达式应该是干净的，不包含方言特定的引号
     */
    protected function updateAstFields(string $fields): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        if (!isset($this->ast['select'])) {
            $this->ast['select'] = [];
        }
        // 🔧 清理字段表达式：AST 是操作结构，不应该包含引号等方言信息
        $this->ast['select']['fields'] = $this->cleanAstFields($fields);
    }

    /**
     * AST 收集方法 - 添加 JOIN 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     * 注意：此方法会追加 JOIN，如果需要同步 joins 属性，应该在调用此方法后同步
     */
    protected function updateAstJoin(string $table, string $condition, string $type): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        if (!isset($this->ast['joins'])) {
            $this->ast['joins'] = [];
        }
        // 追加 JOIN（joins 属性已经在方法调用前更新）
        // 这里只需要确保 AST 中的 joins 与属性同步
        if (isset($this->joins) && is_array($this->joins)) {
            $this->ast['joins'] = $this->joins;
        }
    }

    /**
     * AST 收集方法 - 添加 WHERE 条件
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     * 注意：此方法会追加 WHERE，如果需要同步 wheres 属性，应该在调用此方法后同步
     */
    protected function updateAstWhere(array $where): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        // 同步 wheres 属性到 AST（wheres 属性已经在方法调用前更新）
        if (isset($this->wheres) && is_array($this->wheres)) {
            $this->ast['where'] = $this->wheres;
        }
    }

    /**
     * AST 收集方法 - 更新 ORDER BY 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     */
    protected function updateAstOrder(string $field, string $sort): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        // 同步 order 属性到 AST（order 属性已经在方法调用前更新）
        if (isset($this->order) && is_array($this->order)) {
            $this->ast['order'] = $this->order;
        }
    }

    /**
     * AST 收集方法 - 更新 GROUP BY 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     */
    protected function updateAstGroup(string $groupBy): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        $this->ast['group'] = $groupBy;
    }

    /**
     * AST 收集方法 - 更新 HAVING 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     */
    protected function updateAstHaving(string $having): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        $this->ast['having'] = $having;
    }

    /**
     * AST 收集方法 - 更新 LIMIT 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     */
    protected function updateAstLimit(string $limit): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        $this->ast['limit'] = $limit;
    }

    /**
     * AST 收集方法 - 更新 INSERT 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     */
    protected function updateAstInsert(array $insert): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        $this->ast['insert'] = $insert;
    }

    /**
     * AST 收集方法 - 更新 UPDATE 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     */
    protected function updateAstUpdate(array $singleUpdates, array $batchUpdates): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        if (!isset($this->ast['update'])) {
            $this->ast['update'] = [];
        }
        $this->ast['update']['single'] = $singleUpdates;
        $this->ast['update']['batch'] = $batchUpdates;
    }

    /**
     * AST 收集方法 - 更新额外 SQL 信息
     * 这是 SqlTrait 提供的 AST 收集方法，用于实时更新 AST
     */
    protected function updateAstExtra(string $extra): void
    {
        if (!isset($this->ast)) {
            $this->ast = [];
        }
        $this->ast['extra'] = $extra;
    }
}
