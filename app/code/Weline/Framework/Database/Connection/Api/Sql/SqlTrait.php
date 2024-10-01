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
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Exception\QueryException;
use Weline\Framework\Database\Exception\SqlParserException;

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
    public ConnectorInterface $connection;
    public string $db_name = 'default';

    public function __sleep()
    {
        return ['db_name', 'connection'];
    }


    public function getTable($table_name): string
    {
        if (str_contains($table_name, ' ')) {
            $table_name   = preg_replace_callback('/\s+/', function ($matches) {
                return ' ';
            }, $table_name);
            $table_names  = explode(' ', $table_name);
            $table_name   = $table_names[0];
            $alias_name   = $table_names[1] ?? $this->table_alias;
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
        $alias = $this->table_alias ? 'AS '.$this->table_alias : '';
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
                $where_cond  = $where[1];
                $where_field = $where[0];
                if (str_contains($where_field, '.')) {
                    $where_field_arr = explode('.', $where_field);
                    $where_field     = array_pop($where_field_arr);
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
            $logic  = 'AND ';
            foreach ($this->wheres as $key => $where) {
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
                        # 是sql的字段不添加字段引号(没有值则是sql)
                        if (null === $where[2]) {
                            $wheres .= '(' . $where[0] . ') ' . $logic;
                        } else {
                            $quote = '`';
                            # 复杂参数转化
                            if (str_contains($where[0], '(')) {
                                $quote = '';
                                $param = str_replace('(', '_', $param);
                            }
                            if (str_contains($where[0], ')')) {
                                $quote = '';
                                $param = str_replace(')', '_', $param);
                            }
                            if (str_contains($where[0], ',')) {
                                $quote = '';
                                $param = str_replace(',', '_', $param);
                            }
                            $where[0] = $quote . (($quote === '`') ? str_replace('.', '`.`', $where[0]) : $where[0]) . $quote;
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
                                            $set_where_key_param                      = $param . '_' . str_replace(' ', '_', $where[1]) . '_' . $in_where_key;
                                            $this->bound_values[$set_where_key_param] = (string)$item;
                                            $set_where                                .= $set_where_key_param . ',';
                                        }
                                        $where[2] = rtrim($set_where, ',') . ')';
                                        break;
                                    }
                                    // no break
                                default:
                                    $this->bound_values[$param] = (string)$where[2];
                                    $where[2]                   = $param;
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
                if ($this->exist_update_sql) {
                    $exist_update_fields = explode(',', $this->exist_update_sql);
                    $sql = "SELECT * FROM {$this->table} WHERE ";
                    foreach ($this->insert as $insert_key => $insert) {
                        $sql .= '(';
                        foreach ($exist_update_fields as $update_field) {
                            $sql .= $update_field . ' = "' . $insert[$update_field] . '" AND ';
                        }
                        $sql = rtrim($sql, 'AND ') . ') OR ';
                    }
                    $sql = rtrim($sql, 'OR ');
                    # 查询数据，看看是否存在
                    $exists = $this->getLink()->query($sql)->fetchAll();
                    if (count($exists) > 0) {
                        # 存在，检查数据，有变更则更新
                        $insert_updates = [];
                        # 对比数据是否有变更
                        foreach ($exists as $exist) {
                            # 设计一个联合键值字符串，用于比较插入数据和要更新的数据
                            $exist_update_value_key = '';
                            foreach ($exist_update_fields as $update_field) {
                                $exist_update_value_key .= $update_field.'_' . $exist[$update_field] . '_';
                            }
                            $exist_update_value_key = rtrim($exist_update_value_key, '_');
                            foreach ($this->insert as $insert_key => $insert) {
                                $insert_data_value_key = '';
                                foreach ($exist_update_fields as $update_field) {
                                    $insert_data_value_key .= $update_field.'_' . $insert[$update_field] . '_';
                                }
                                $insert_data_value_key = rtrim($insert_data_value_key, '_');
                                if ($insert_data_value_key == $exist_update_value_key) {
                                    # 如果与存在的值相同的，卸载要插入的数据标记为要更新的数据
                                    unset($this->insert[$insert_key]);
                                    $exist_update_where = 'where ';
                                    foreach ($exist_update_fields as $update_field) {
                                        $exist_update_where .= $update_field . ' = "' . addcslashes($insert[$update_field], '"') . '" AND ';
                                        unset($insert[$update_field]);
                                    }
                                    $exist_update_where = rtrim($exist_update_where, 'AND ');
                                    # 如果还有主键,根据主键更新，否则根据设置的条件更新，以便更新更加精准
                                    if (isset($insert[$this->identity_field])) {
                                        $main_key_exist_update_where = 'where ' . $this->identity_field . ' = "' . addcslashes($insert[$this->identity_field], '"') . '"';
                                        foreach ($exist_update_fields as $exist_update_field) {
                                            if (!in_array($exist_update_field, $added_insert_update_fields)) {
                                                $main_key_exist_update_where .= $exist_update_field . ' = "' .addcslashes($insert[$exist_update_field], '"') . '" AND ';
                                            }
                                        }
                                        $main_key_exist_update_where = rtrim($main_key_exist_update_where, 'AND ');
                                        $insert_updates[$main_key_exist_update_where] = $insert;
                                    } elseif ($this->_unit_primary_keys) {
                                        # 联合主键存在时，加入联合主键
                                        $unit_primary_key_insert_update_where = 'where ';
                                        $added_insert_update_fields = [];
                                        foreach ($this->_unit_primary_keys as $unit_primary_key) {
                                            $added_insert_update_fields[] = $unit_primary_key;
                                            $unit_primary_key_insert_update_where .= $unit_primary_key . ' = "' .addcslashes($insert[$unit_primary_key], '"') . '" AND ';
                                        }
                                        foreach ($exist_update_fields as $exist_update_field) {
                                            if (!in_array($exist_update_field, $added_insert_update_fields)) {
                                                $unit_primary_key_insert_update_where .= $exist_update_field . ' = "' .addcslashes($insert[$exist_update_field], '"') . '" AND ';
                                            }
                                        }
                                        $unit_primary_key_insert_update_where = rtrim($unit_primary_key_insert_update_where, 'AND ');
                                        $insert_updates[$unit_primary_key_insert_update_where] = $insert;
                                    } else {
                                        $insert_updates[$exist_update_where] = $insert;
                                    }
                                }
                            }
                            # 有变更则更新
                            if (count($insert_updates) > 0) {
                                $sql = "";
                                foreach ($insert_updates as $insert_update_where => $insert_update) {
                                    $sql .= "UPDATE {$this->table} SET ";
                                    foreach ($insert_update as $insert_update_field => $insert_update_value) {
                                        $sql .= $insert_update_field . ' = "' . addcslashes($insert_update_value, '"') . '", ';
                                    }
                                    $sql .= rtrim($sql, ', ') . ' ' . $insert_update_where . '; ';
                                }
                                $this->getLink()->query($sql)->fetchAll();
                            }
                        }
                        foreach ($exists as $exist) {
                            $sql .= '(';
                            foreach ($exist as $update_field => $update_value) {
                                $sql .= $update_field . ' = "' . $update_value . '" AND ';
                            }
                            $sql = rtrim($sql, 'AND ') . ') OR ';
                        }

                    }
                }
                $values = '';
                foreach ($this->insert as $insert_key => $insert) {
                    $insert_key += 1;
                    $values     .= '(';
                    foreach ($insert as $insert_field => $insert_value) {
                        $insert_bound_key                      = ':' . md5("{$insert_field}_field_{$insert_key}");
                        $this->bound_values[$insert_bound_key] = (string)$insert_value;
                        $values                                .= "$insert_bound_key , ";
                    }
                    $values = rtrim($values, ', ');
                    $values .= '),';
                }
                $values = rtrim($values, ',');
                $sql    = "INSERT INTO {$this->table} {$this->fields} VALUES {$values}";
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
                        $identity_values_key                      = ':' . md5('update_identity_values_key'.$key);
                        $identity_values_str                     .= $identity_values_key. ',';
                        $this->bound_values[$identity_values_key] = (string)$identityValue;
                    }
                    $identity_values_str = rtrim($identity_values_str, ',');
                    $wheres .= ($wheres ? ' AND ' : 'WHERE ') . "$this->identity_field IN ($identity_values_str)";
                }

                # 排除没有条件值的更新
                if (empty($wheres)) {
                    throw new DbException(__('请设置更新条件：第一种方式，->where($condition)设置，第二种方式，更新数据中包含条件值（默认为字段id,可自行设置->update($arg1,$arg2)第二参数指定根据数组中的某个字段值作为依赖条件更新。）'));
                }

                # 配置更新语句
                $updates = '';
                # 多条更新
                if ($this->updates) {
                    # 存在$identity_values 表示多维数组更新
                    if ($identity_values) {
                        $keys = array_keys(current($this->updates));
                        foreach ($keys as $column) {
                            $updates .= sprintf("`%s` = CASE `%s` \n", $column, $this->identity_field);
                            foreach ($this->updates as $update_key => $line) {
                                # 主键值
                                $update_key                                     += 1;
                                $identity_field_column_key                      = ':' . md5("{$this->identity_field}_{$column}_key_{$update_key}");
                                $this->bound_values[$identity_field_column_key] = (string)$line[$this->identity_field];

                                # 更新键值
                                $identity_field_column_value                      = ':' . md5("update_{$column}_value_{$update_key}");
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
                            $update_key                      = ':' . md5($update_field);
                            $update_field                    = $this->parserFiled($update_field);
                            $this->bound_values[$update_key] = (string)$field_value;
                            $updates                         .= "$update_field = $update_key,";
                        }
                    }
                } elseif ($this->single_updates) {
                    foreach ($this->single_updates as $update_field => $update_value) {
                        $update_field                    = $this->parserFiled($update_field);
                        $update_key                      = ':' . md5($update_field);
                        $this->bound_values[$update_key] = (string)$update_value;
                        $updates                         .= "$update_field=$update_key,";
                    }
                } else {
                    throw new QueryException(__('无法解析更新数据！多记录更新数据：%1，单记录更新数据：%2', [var_export($this->updates, true), var_export($this->single_updates, true)]));
                }
                $updates = rtrim($updates, ',');

                $sql = "UPDATE {$this->table} {$alias} SET {$updates} {$wheres} {$this->additional_sql} ";
                break;
            case 'find':
            case 'select':
            default:
                $sql = "SELECT {$this->fields} FROM {$this->table} {$alias} {$joins} {$wheres} {$this->group_by} {$this->having} {$order} {$this->additional_sql} {$this->limit}";
                break;
        };
        # 预置sql
        $this->PDOStatement = $this->getLink()->prepare($sql);
        $this->sql          =  $sql;
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
    public function parserFiled(string|array &$field): string|array
    {
        if (is_array($field)) {
            foreach ($field as $field_key => $value) {
                unset($field[$field_key]);
                $field_key         = '`' . str_replace('.', '`.`', $field_key) . '`';
                $field[$field_key] = $value;
            }
        } else {
            if (is_string($field)) {
                $field = '`' . str_replace('.', '`.`', $field) . '`';
            }
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
            echo '<pre>';
            var_dump(debug_backtrace());
        }
        throw new DbException($words);
    }
}
