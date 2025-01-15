<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/6/21
 * 时间：11:47
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Framework\Database\Connection\Api\Sql;

interface QueryInterface
{
    public const attr_IDENTITY_FIELD = 'identity_field';
    public const attr_TABLE = 'table';
    public const attr_TABLE_ALIA = 'table_alias';
    public const attr_INSERT = 'insert';
    public const attr_JOIN = 'joins';
    public const attr_FIELD = 'fields';
    public const attr_UPDATE = 'updates';
    public const attr_WHERE = 'wheres';
    public const attr_BOUND_VALUE = 'bound_values';
    public const attr_LIMIT = 'limit';
    public const attr_ORDER = 'order';
    public const attr_GROUP_BY = 'group_by';
    public const attr_HAVING = 'having';
    public const attr_SQL = 'sql';
    public const attr_ADDITIONAL_SQL = 'additional_sql';

    public const init_vars = [
        self::attr_IDENTITY_FIELD => 'id',
        self::attr_TABLE => '',
        self::attr_TABLE_ALIA => 'main_table',
        self::attr_INSERT => [],
        self::attr_JOIN => [],
        self::attr_FIELD => 'main_table.*',
        self::attr_UPDATE => [],
        self::attr_WHERE => [],
        self::attr_BOUND_VALUE => [],
        self::attr_LIMIT => '',
        self::attr_ORDER => [],
        self::attr_GROUP_BY => '',
        self::attr_HAVING => '',
        self::attr_SQL => '',
        self::attr_ADDITIONAL_SQL => '',
    ];
    public const query_vars = [
        self::attr_INSERT => [],
        self::attr_JOIN => [],
        self::attr_FIELD => 'main_table.*',
        self::attr_UPDATE => [],
        self::attr_WHERE => [],
        self::attr_BOUND_VALUE => [],
        self::attr_LIMIT => '',
        self::attr_ORDER => [],
        self::attr_GROUP_BY => '',
        self::attr_HAVING => '',
        self::attr_SQL => '',
        self::attr_ADDITIONAL_SQL => '',
    ];

    /**
     * @DESC          # 设置主键
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/25 22:02
     * 参数区：
     *
     * @param string $field
     *
     * @return mixed
     */
    public function identity(string $field): QueryInterface;

    /**
     * @DESC          # 表名设置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 23:01
     * 参数区：
     *
     * @param string $table_name
     *
     * @return QueryInterface
     */
    public function table(string $table_name): QueryInterface;

    /**
     * @DESC          # 表名别名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/17 23:25
     * 参数区：
     *
     * @param string $table_alias_name
     *
     * @return QueryInterface
     */
    public function alias(string $table_alias_name): QueryInterface;

    /**
     * @DESC          # 模糊查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/17 23:25
     * 参数区：
     *
     * @param string $word
     * @param string $fields
     * @return QueryInterface
     */
    public function concat(string $fields,string $alias_field): QueryInterface;
    /**
     * @DESC          # 模糊查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/17 23:25
     * 参数区：
     *
     * @param string $word
     * @param string $fields
     * @return QueryInterface
     */
    public function concat_like(string $fields,string $like_word): QueryInterface;


    /**
     * @DESC          # 组合字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/17 23:25
     * 参数区：
     *
     * @param string $fields
     * @param string $separator 默认json
     * @return QueryInterface
     */
    public function group_concat(string $fields, string $concat_field, string $separator = 'json'): QueryInterface;

    /**
     * @DESC          # 更新
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/18 0:20
     * 参数区：
     *
     * @param array|string $field 更新条件字段，如果查询设置有表主键则自动使用表主键
     * @param int|string $value_or_condition_field 更新数据示例：['id'=>1,'name'=>'用户']或者多值更新[['id'=>1,'name'=>'用户1'],['id'=>2,'name'=>'用户2']]
     *
     * @return QueryInterface
     */
    public function update(array|string $field = '', int|string $value_or_condition_field = 'id'): QueryInterface;

    /**
     * @DESC          # 表名设置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 23:01
     * 参数区：
     *
     * @param string $fields 示例：a.id,a.name,b.role_id,b.rule_name
     *
     * @return QueryInterface
     */
    public function fields(string $fields): QueryInterface;

    /**
     * @DESC          # 连接查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/19 21:53
     * 参数区：
     *
     * @param string $table
     * @param string $condition
     * @param string $type
     *
     * @return QueryInterface
     */
    public function join(string $table, string $condition, string $type = 'left'): QueryInterface;

    /**
     * @DESC          | 条件查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 21:44
     * 参数区：
     *
     * @param array|string $field 字段或者条件数组,例如：['name', 'like', '%张三%', 'AND']
     *                                      第一个元素：字段名
     *                                      第二个元素：逻辑符
     *                                      第三个元素：条件值
     *                                      第四个元素：where 查询链接符，可以不用指定，多个where时默认 AND 链接,
     *                                      多个where中使用，也可以全部where写在第一个元素中，
     *                                      就不需要多个where链接查询条件了
     * @param mixed|null $value 条件值
     * @param string $condition 逻辑符： < | = | like | > 等常规逻辑
     * @param string $where_logic 下一个where使用的逻辑 值：and | or 默认 and
     * @param string $array_where_logic_type 当值给的是一个数组时，下一个where使用的逻辑 值：and | or 默认 and
     *
     * @return QueryInterface
     */
    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): QueryInterface;

    /**
     * @DESC          # 累减
     * @param string $field
     * @param float|int $value
     * @return QueryInterface
     */
    public function dec(string $field, float|int $value = 1): QueryInterface;

    /**
     * @DESC          # 累加
     * @param string $field
     * @param float|int $value
     * @return QueryInterface
     */
    public function inc(string $field, float|int $value = 1): QueryInterface;

    /**
     * @DESC          # 限制查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/18 22:44
     * 参数区：
     *
     * @param int $size
     * @param int $offset
     *
     * @return QueryInterface
     */
    public function limit(int $size, int $offset = 0): QueryInterface;

    /**
     * @DESC          # 限制查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/18 22:44
     * 参数区：
     *
     * @param int $page
     * @param int $pageSize
     *
     * @return QueryInterface
     */
    public function page(int $page = 1, int $pageSize = 20): QueryInterface;

    /**
     * @DESC          # 统计页码，总数等信息，为了性能当第一次统计时使用此函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/24 22:28
     * 参数区：
     *
     * @param int $page 页码
     * @param int $pageSize 页大小
     * @param array $params 参数 可用于保持分页
     *
     * @return QueryInterface
     */
    public function pagination(int $page = 1, int $pageSize = 20, array $params = [], int $max_limit = 1000, int $total = 0): QueryInterface;

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/18 22:46
     * 参数区：
     *
     * @param string $field
     * @param string $sort
     *
     * @return QueryInterface
     */
    public function order(string $field = '', string $sort = 'DESC'): QueryInterface;

    /**
     * @DESC          # Group By语法
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 25/2/2023 上午10:52
     * 参数区：
     * @param string $fields 【用英文逗号','隔开每个字段】
     * @return QueryInterface
     */
    public function group(string $fields): QueryInterface;

    /**
     * @DESC          # 聚合函数后的筛选条件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 25/2/2023 上午11:11
     * 参数区：
     * @param string $having 【筛选条件】
     * @return QueryInterface
     */
    public function having(string $having): QueryInterface;

    /**
     * @DESC          # 仅查找一个
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/17 23:15
     * 参数区：
     * @return QueryInterface
     */
    public function find(string $find_fields = ''): QueryInterface;

    /**
     * @DESC          # 统计
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/17 23:15
     * 参数区：
     * @return int
     */
    public function total(string $field = '*', string $alias = 'total_count'): int;

    /**
     * @DESC         |选择
     *
     * 参数区：
     *
     * @return QueryInterface
     */
    public function select(string $fields = ''): QueryInterface;

    /**
     * @DESC         |插入 作为批量使用更新插入时请将表引擎设置为 ENGINE=InnoDB;改成ENGINE=MyIsam;可以解决这个问题，或者自行设置批量插入sql
     *
     * 参数区：
     *
     * @param array $data [数据]
     * @param array|string $exist_update_fields [数组或字符串] 检测到这些字段对应的值已经存在则更新此参数指定的字段 字符串方式以','号分割字段
     * @param string $update_where_fields [数组：存在则更新字段] 检测到这些字段对应的值已经存在则更新 字符串方式以','号分割字段，作为附加条件加入主键和联合主键的条件
     * @param bool $ignore_primary_key [布尔] 是否忽略主键
     *
     * @return QueryInterface
     */
    public function insert(array $data, array|string $update_where_fields = [], string $update_fields = '', bool $ignore_primary_key = false): QueryInterface;

    /**
     * @DESC         |删除
     *
     * 参数区：
     *
     * @return QueryInterface
     */
    public function delete(): QueryInterface;

    /**
     * @DESC          | 查询结果集
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 21:09
     *
     * @param string $sql
     *
     * @return QueryInterface
     */
    public function query(string $sql): QueryInterface;

    /**
     * @DESC          # 附加的sql 用于复杂自定义的长sql 比如聚合函数的使用
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/24 22:06
     * 参数区：
     *
     * @param string $additional_sql
     *
     * @return QueryInterface
     */
    public function additional(string $additional_sql): QueryInterface;

    /**
     * @DESC          | 查询最终的结果
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 21:09
     *
     * @param string $model_class
     *
     * @return mixed
     */
    public function fetch(string $model_class = ''): mixed;

    /**
     * @DESC          | 查询原始最终的结果
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 21:09
     *
     * @return array
     */
    public function fetchArray(): array;

    /**
     * @DESC          # 清理特定条件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/23 22:02
     * 参数区：
     *
     * @param string $type 'wheres' | 'orders' | 'limit' | 'joins' | 'fields' | 'alias' | 'updates'|'table'
     *
     * @return QueryInterface
     */
    public function clear(string $type = ''): QueryInterface;

    /**
     * @DESC          # 清理特定条件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/23 22:02
     * 参数区：
     *
     * @param string $type 默认：清理除了表名、表别名、表主键外的查询缓存 'wheres' | 'orders' | 'limit' | 'joins' | 'fields' | 'alias' |
     *                     'updates'|'table'
     *
     * @return QueryInterface
     */
    public function clearQuery(string $type = ''): QueryInterface;

    /**
     * @DESC          # 重置所有
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 22:59
     * 参数区：
     * @return QueryInterface
     */
    public function reset(): QueryInterface;

    /**
     * @DESC          # 开启事务
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/23 22:33
     * 参数区：
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * @DESC          # 事务回滚
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/23 22:33
     * 参数区：
     * @return void
     */
    public function rollBack(): void;

    /**
     * @DESC          # 事务提交
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/23 22:33
     * 参数区：
     * @return void
     */
    public function commit(): void;

    /**
     * 归档数据
     *
     * @param string $period ['all'=>'全部','today'=>'今天','yesterday'=>'昨天','current_week'=>'这周','near_week'=>'最近一周','last_week'=>'上周','near_month'=>'近三十天','current_month'=>'本月','last_month'=>'上一月','quarter'=>'本季度','last_quarter'=>'上个季度','current_year'=>'今年','last_year'=>'上一年']
     * @param string $field
     *
     * @return $this
     */
    public function period(string $period, string $field = 'main_table.create_time'): static;

    /**
     * @DESC          # 截断清理整个表
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 18/3/2024 上午10:43
     * 参数区：
     * @return mixed
     */
    public function truncate(string $backup_file = '', string $table = ''): static;

    /**
     * @DESC          # 备份表【未指定表时备份默认表】
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 18/3/2024 上午11:04
     * 参数区：
     * @return $this
     */
    public function backup(string $backup_file = '', string $table = ''): static;

    /**
     * @DESC          # 读取最终的sql
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:42
     * 参数区：
     *
     * @param bool $format
     *
     * @return string
     */
    public function getSql(bool $format = false): string;

    /**
     * @DESC          # 读取预编译sql
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:42
     * 参数区：
     *
     * @param bool $format
     *
     * @return string
     */
    public function getPrepareSql(bool $format = false): string;
}
