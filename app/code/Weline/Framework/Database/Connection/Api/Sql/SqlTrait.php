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
        return $this->connection;
    }

    public function getConnector(): ConnectorInterface
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

    public function setConnector(ConnectorInterface $connection): void
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
     */
    private function prepareSql($action): void
    {
        $this->dialectAdapter->compile($this, $action);
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
                    $field = str_replace('``', '`', $field);
                }
                $field = str_replace('`*`', '*', $field);
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
}
