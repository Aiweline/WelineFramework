<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Api\Sql;

use http\Env;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Exception\QueryException;
use Weline\Framework\Database\Exception\SqlParserException;
use Weline\Framework\Output\Log;

trait SqlTrait
{
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
    ];
    private static $special_fields = [
        'order',
        'key',
        'table',
        'fields',
    ];
    public ConnectorInterface $connection;
    public string $db_name = 'default';

    public function __sleep()
    {
        return ['db_name', 'connection'];
    }


    public function getTable($table_name): string
    {
        if (str_contains($table_name, ' ')) {
            $table_name = preg_replace_callback('/\s+/', function ($matches) {
                return ' ';
            }, $table_name);
            $table_names = explode(' ', $table_name);
            $table_name = $table_names[0];
            $alias_name = $table_names[1] ?? $this->table_alias;
            $this->fields = str_replace('main_table.', $alias_name . '.', $this->fields);
            $this->alias($alias_name);
        }
        if ($this->db_name) {
            $table_name = "{$this->db_name}.{$table_name}";
        } else {
            $table_name = "`{$table_name}`";
        }
        return $table_name;
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
                $this->exceptionHandle(__('Where查询异常：%1,%2,%3', ["第{$f_key}个条件数组错误", '出错的数组：["' . implode('","', $where_array) . '"]', "示例：where([['name','like','%张三%','or'],['name','like','%李四%']])"]));
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
            $this->exceptionHandle(__('当前错误的条件操作符：%1 ,当前的条件数组：%2, 允许的条件符：%3', [$where_array[1], '["' . implode('","', $where_array) . '"]', '["' . implode('","', $this->conditions) . '"]']));
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
     */
    private function prepareSql($action): void
    {
        $alias = $this->table_alias ? 'AS ' . $this->table_alias : '';
        if ($this->table == '') {
            $this->exceptionHandle(__('没有指定table表名！'));
        }
        # 处理 joins
        $joins = '';
        foreach ($this->joins as $join) {
            $joins .= " {$join[2]} JOIN {$join[0]} ON {$join[1]} ";
        }
        # 处理 Where 条件
        $wheres = '';
        // 如果有联合主键，把条件按照联合主键的顺序依次添加到sql语句中，提升查询速度
        if (!empty($this->_index_sort_keys)) {
            $_index_sort_keys_wheres = [];
            foreach ($this->wheres as $where_key => $where) {
                $where_cond = $where[1];
                $where_field = $where[0];
                if (str_contains($where_field, '.')) {
                    $where_field_arr = explode('.', $where_field);
                    $where_field = array_pop($where_field_arr);
                }
                if (in_array($where_field, $this->_index_sort_keys)) {
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
        if ($this->wheres) {
            $wheres .= ' WHERE ';
            $logic = 'AND ';
            foreach ($this->wheres as $key => $where) {
                if (!str_contains((string)$where[0], '`')) {
                    if (str_contains($where[0], '.')) {
                        $where0items = explode('.', $where[0]);
                        $where[0] = '`' . implode('`.`', $where0items) . '`';
                    } else {
                        // 如果$where[0]只有字母
                        if (preg_match('/^[a-zA-Z]+$/', $where[0])) {
                            $where[0] = '`' . $where[0] . '`';
                        }
                    }
                }
                $key += 1;
                # 如果自己设置了where 逻辑连接符 就修改默认的连接符 AND
                if (isset($where[3])) {
                    $logic = array_pop($where) . ' ';
                }
                switch (count($where)) {
                    # 字段等于sql
                    case 1:
                        $wheres .= $where[0] . " {$logic} ";
                        break;
                    # 默认where逻辑连接符为AND
                    default:
                        $param = ':' . str_replace('`', '_', $where[0]);
                        $param = str_replace(' ', '_', $param);
                        $param = str_replace('(', '_', $param);
                        $param = str_replace(')', '_', $param);
                        $param = str_replace(',', '_', $param);
                        # 是sql的字段不添加字段引号(没有值则是sql)
                        if (null === $where[2]) {
                            $wheres .= '(' . $where[0] . ') ' . $logic;
                        } else {
//                            $quote = '`';
//                            # 复杂参数转化
//                            if (str_contains($where[0], '(')) {
//                                $quote = '';
//                                $param = str_replace('(', '_', $param);
//                            }
//                            if (str_contains($where[0], ')')) {
//                                $quote = '';
//                                $param = str_replace(')', '_', $param);
//                            }
//                            if (str_contains($where[0], ',')) {
//                                $quote = '';
//                                $param = str_replace(',', '_', $param);
//                            }
                            # 处理带别名的参数键
                            $param = str_replace('.', '__', $param) . $key;
                            switch (strtolower($where[1])) {
                                case 'in':
                                case 'not in':
                                case 'find_in_set' :
                                    $set_where = '(';
                                    if (is_array($where[2])) {
                                        foreach ($where[2] as $in_where_key => $item) {
                                            # $in_where_key如果是字符串，只保留字母和下划线
                                            if (is_string($in_where_key)) {
                                                $in_where_key = preg_replace('/[^A-Za-z_]/', '', $in_where_key);
                                            }
                                            $set_where_key_param = $param . '_' . $in_where_key . '_' . str_replace(' ', '_', $where[1]);
                                            $this->bound_values[$set_where_key_param] = (string)$item;
                                            $set_where .= $set_where_key_param . ',';
                                        }
                                        $where[2] = rtrim($set_where, ',') . ')';
                                        break;
                                    }
                                // no break
                                default:
                                    $this->bound_values[$param] = (string)$where[2];
                                    $where[2] = $param;
                            };
                            $wheres .= '(' . implode(' ', $where) . ') ' . $logic;
                        }
                }
            }

            $wheres = rtrim($wheres, $logic);
        }

        # 排序
        $order = '';
        foreach ($this->order as $field => $dir) {
            if (!str_contains($field, '`')) {
                $fields = explode('.', $field);
                $field = '`' . implode('`.`', $fields) . '`';
            }
            $order .= "$field $dir,";
        }
        $order = rtrim($order, ',');
        if ($order) {
            $order = 'ORDER BY ' . $order;
        }

        # 匹配sql
        switch ($action) {
            case 'insert':
                # sqlite 不支持数据库层面的检测，需要手动查询一次，如果有存在更新语句则，先查询已存在的记录，存在则更新，不存在则插入
                # 取出需要更新的项目
                $insert_items = $this->insert['insert'] ?? [];
                $insert_or_update_items = $this->insert['i_o_u'] ?? [];
                unset($this->insert['i_o_u']);
                unset($this->insert['origin']);
                unset($this->insert['insert']);
                $update_inserts_sql = '';
                if ($insert_or_update_items) {
                    $bound_filed_values = [];
                    $exist_sql = "SELECT * FROM {$this->table} WHERE ";
                    foreach ($insert_or_update_items as $insert_key => $insert) {
                        $exist_sql .= '(';
                        foreach ($this->insert_update_where_fields as $insert_update_where_field_k => $insert_update_where_field) {
                            $insert_update_where_field_key = ':' . md5('insert_update_where_' . $insert_update_where_field . '_' . $insert_update_where_field_k . '_' . $insert_key);
                            if ($insert_update_where_field == $this->identity_field and !isset($insert[$insert_update_where_field])) {
                                continue;
                            }
                            $bound_filed_values[$insert_update_where_field_key] = $insert[$insert_update_where_field];
                            $exist_sql .= '`' . $insert_update_where_field . '` = ' . $insert_update_where_field_key . ' AND ';
                        }
                        $exist_sql = rtrim($exist_sql, 'AND ') . ') OR ';
                    }
                    $exist_sql = rtrim($exist_sql, 'OR ');
                    # 存在，检查数据，有变更则更新
                    $insert_updates = [];
                    # 查询数据，看看是否存在
                    $exist_sql = self::formatSql($exist_sql);
                    $existsQuery = $this->getLink()->prepare($exist_sql);
                    $existsQuery->execute($bound_filed_values);
                    $exists = $existsQuery->fetchAll();

                    if (count($exists) > 0) {
                        # 对比数据是否有变更
                        foreach ($exists as $exist) {
                            # 设计一个联合键值字符串，用于比较插入数据和要更新的数据
                            $exist_update_value_key = '';
                            foreach ($this->insert_update_where_fields as $insert_update_where_field) {
                                $exist_update_value_key .= $insert_update_where_field . '_' . $exist[trim($insert_update_where_field, '`')] . '_';
                            }
                            $exist_update_value_key = rtrim($exist_update_value_key, '_');

                            # 检测需要更新的条目
                            foreach ($insert_or_update_items as $insert_key => $insert) {
                                $insert_data_value_key = '';
                                foreach ($this->insert_update_where_fields as $update_field) {
                                    $insert_data_value_key .= $update_field . '_' . $insert[trim($update_field, '`')] . '_';
                                }
                                $insert_data_value_key = rtrim($insert_data_value_key, '_');
                                # 检测数据和需要更新的条件项值命中，说明需要更新
                                if ($insert_data_value_key == $exist_update_value_key) {
                                    # 如果与存在的值相同的，卸载要更新的项目
                                    unset($insert_or_update_items[$insert_key]);
                                    $exist_update_where = '';
                                    foreach ($this->insert_update_where_fields as $insert_update_where_field) {
                                        # 拼接联合键
                                        $exist_update_where .= '`' . $insert_update_where_field . '` = ' . $this->quote((string)$insert[$insert_update_where_field]) . ' AND ';
                                        unset($insert[$insert_update_where_field]);
                                    }
                                    $exist_update_where = rtrim($exist_update_where, 'AND ');
                                    if ($insert) {
                                        # 如果有打算更新的字段，则根据需要保留数据，其他字段数据排除
                                        if (!empty($this->insert_update_fields)) {
                                            foreach ($insert as $field_key => $field_value) {
                                                if (!in_array($field_key, $this->insert_update_fields)) {
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

                    # 其他不存在的，直接合并到插入
                    if (count($insert_or_update_items) > 0) {
                        $insert_items = array_merge($insert_items, $insert_or_update_items);
                    }

                    # 有变更则更新
                    if (count($insert_updates) > 0) {
                        $insert_updates_index = 0;
                        foreach ($insert_updates as $insert_update_where => $insert_update) {
                            $insert_updates_index++;
                            $update_inserts_sql .= "UPDATE {$this->table} SET ";
                            foreach ($insert_update as $insert_update_field => $insert_update_value) {
                                $insert_bound_key = ':' . md5("{$insert_update_field}_field_{$insert_update_where}_{$insert_updates_index}");
                                $this->bound_values[$insert_bound_key] = (string)$insert_update_value;
                                $update_inserts_sql .= '`' . $insert_update_field . '` = ' . $insert_bound_key . ', ';
                            }
                            $update_inserts_sql = rtrim($update_inserts_sql, ', ') . ' ' . $insert_update_where . '; ';
                        }
                    }
                }
                # 主键为空时新增
                $identity_inserts_sql = '';
                $identity_inserts_fields = str_replace(['(', ')', ' '], '', $this->fields);

                $identity_inserts_fields = explode(',', $identity_inserts_fields);

                foreach ($identity_inserts_fields as $identity_inserts_field_key => $identity_inserts_field) {
                    $identity_inserts_field = str_replace(' ', '', $identity_inserts_field);
                    $identity_inserts_fields[$identity_inserts_field_key] = trim($identity_inserts_field, '`');
                }

                $identity_inserts_fields = '`' . implode('`,`', $identity_inserts_fields) . '`';
                $values = '';
                $has_identify_field_insert = false;
                $has_no_identify_field_insert = false;
                foreach ($insert_items as $insert_key => $insert) {
                    $insert_key += 1;
                    if ($this->identity_field && empty($insert[$this->identity_field])) {
                        unset($insert[$this->identity_field]);
                        $insert_fields = array_keys($insert);
                        $insert_fields = '`' . implode('`,`', $insert_fields) . '`';
                        $identity_inserts_sql .= "INSERT INTO {$this->table} ({$insert_fields}) VALUES (";
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
                            $this->bound_values[$insert_bound_key] = (string)$insert_value;
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
                    $sql .= "INSERT INTO {$this->table} {$this->fields} VALUES {$values}";
                }
                if (empty($values) && empty($identity_inserts_sql) && empty($update_inserts_sql)) {
                    $this->sql = $sql;
                    return;
                }
                break;
            case 'delete':
                $sql = "DELETE FROM {$this->table} {$wheres} {$this->additional_sql}";
                break;
            case 'update':
                # 设置where条件
                $identity_values = array_column($this->updates, $this->identity_field);
                if ($identity_values) {
                    $identity_values_str = '';
                    foreach ($identity_values as $key => $identityValue) {
                        $identity_values_key = ':' . md5('update_identity_values_key' . $key);
                        $identity_values_str .= $identity_values_key . ',';
                        $this->bound_values[$identity_values_key] = (string)$identityValue;
                    }
                    $identity_values_str = rtrim($identity_values_str, ',');
                    $wheres .= ($wheres ? ' AND ' : 'WHERE ') . "`$this->identity_field` IN ($identity_values_str)";
                }

                # 排除没有条件值的更新
                if (empty($wheres)) {
                    throw new DbException(__('请设置更新条件：第一种方式，->where($condition)设置，第二种方式，更新数据中包含条件值（默认为字段id,可自行设置->update($arg1,$arg2)第二参数指定根据数组中的某个字段值作为依赖条件更新。）'));
                }

                # 配置更新语句
                $updates = '';
                # 累加累减
                if ($this->dec_inc_updates) {
                    foreach ($this->dec_inc_updates as $dec_inc_update_field => $dec_inc_update_value) {
                        $updates .= "`$dec_inc_update_field` = `$dec_inc_update_field` {$dec_inc_update_value},";
                    }
                }
                # 多条更新
                if ($this->updates) {
                    # 存在$identity_values 表示多维数组更新
                    if ($identity_values) {
                        $keys = array_keys(current($this->updates));
                        foreach ($keys as $column) {
                            $updates .= sprintf("`%s` = CASE `%s` \n", $column, $this->identity_field);
                            foreach ($this->updates as $update_key => $line) {
                                # 主键值
                                $update_key += 1;
                                $identity_field_column_key = ':' . md5("{$this->identity_field}_{$column}_key_{$update_key}");
                                $this->bound_values[$identity_field_column_key] = (string)$line[$this->identity_field];

                                # 更新键值
                                $identity_field_column_value = ':' . md5("update_{$column}_value_{$update_key}");
                                $this->bound_values[$identity_field_column_value] = (string)$line[$column];
                                # 组装
                                $updates .= sprintf('WHEN %s THEN %s ', $identity_field_column_key, $identity_field_column_value);
                                //                            $updates .= sprintf("WHEN '%s' THEN '%s' \n", $line[$this->identity_field], $identity_field_column_value);
                            }
                            $updates .= 'END,';
                        }
                    } else { # 普通单条更新
                        if (1 < count($this->updates)) {
                            throw new SqlParserException(__('更新条数大于一条时请使用示例更新：$query->table("demo")->identity("id")->update(["id"=>1,"name"=>"测试1"])->update(["id"=>2,"name"=>"测试2"])或者update中指定条件字段id：$query->table("demo")->update([["id"=>1,"name"=>"测试1"],["id"=>2,"name"=>"测试2"]],"id")'));
                        }
                        foreach ($this->updates[0] as $update_field => $field_value) {
                            $update_key = ':' . md5($update_field);
                            $update_field = $this->parserFiled($update_field);
                            $this->bound_values[$update_key] = (string)$field_value;
                            $updates .= "`$update_field` = $update_key,";
                        }
                    }
                }
                if ($this->single_updates) {
                    foreach ($this->single_updates as $update_field => $update_value) {
                        $update_field = $this->parserFiled($update_field);
                        $update_key = ':' . md5($update_field);
                        $this->bound_values[$update_key] = (string)$update_value;
                        $updates .= "`$update_field`=$update_key,";
                    }
                }
                if (!$updates) {
                    throw new QueryException(__('无法解析更新数据！多记录更新数据：%1，单记录更新数据：%2', [var_export($this->updates, true), var_export($this->single_updates, true)]));
                }
                $updates = rtrim($updates, ',');

                $sql = "UPDATE {$this->table} {$alias} SET {$updates} {$wheres} {$this->additional_sql} ";
                break;
            case 'find':
            case 'select':
            default:
                $sql = "SELECT {$this->fields} FROM {$this->table} {$alias} {$joins} {$wheres} {$this->group_by} {$this->having} {$this->additional_sql} {$order} {$this->limit}";
                break;
        };
        # 预置sql
        $sql = $this::formatSql($sql);
        $this->sql = $sql;
//        if (str_contains(strtolower($sql), 'bo_CN')) {
//            dd($this->getSql());
//        }
        if (!$this->batch) {
            $this->PDOStatement = $this->getLink()->prepare($sql);
        }
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
            # 以()号隔开
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
            # 以逗号隔开
            if (str_contains($field, ',')) {
                $field = explode(',', $field);
                foreach ($field as &$f) {
                    $f = self::parserFiled($f);
                }
                $field = implode(',', $field);
                if (str_contains($field, '``')) {
                    return str_replace('``', '`', $field);
                }
                return $field;
            }
            if (str_starts_with($field, '"') || str_starts_with($field, "'")) {
                if (str_contains($field, '``')) {
                    return str_replace('``', '`', $field);
                }
                return $field;
            }
            # 如果没有空格，也没有.和等于符号【单纯字段】直接加上·
            if (!str_contains($field, ' ') && !str_contains($field, '=')) {
//                $field = str_replace('`', '', $field);
                if (str_contains($field, '.')) {
                    $field = '`' . str_replace('.', '`.`', $field) . '`';
                }
                if (str_contains($field, '``')) {
                    return str_replace('``', '`', $field);
                }
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
        } elseif (is_array($field)) {
            foreach ($field as $field_key => $value) {
                unset($field[$field_key]);
                $field_key = self::parserFiled($field_key);
                $field[$field_key] = $value;
            }
        }
        if (str_contains($field, '``')) {
            return str_replace('``', '`', $field);
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
        if (DEV && DEBUG) {
            d(debug_backtrace());
        }
        throw new DbException($words);
    }

    protected function getSqlWithBounds(string $sql, array $bindings = [], bool $format = false): string
    {
        if (empty($bindings)) {
            $bindings = $this->bound_values;
        }
        foreach ($bindings as $key => $binding) {
            $binding = $this->quote($binding);
            $sql = str_replace($key, $binding, $sql);
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
        return $sql;
    }
}
