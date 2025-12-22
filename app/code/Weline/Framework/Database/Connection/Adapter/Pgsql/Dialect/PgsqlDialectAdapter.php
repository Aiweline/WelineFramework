<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Dialect\GenericDialectAdapter;
use Weline\Framework\Database\Connection\Api\Sql\Query;
use Weline\Framework\Database\Exception\DbException;

class PgsqlDialectAdapter extends GenericDialectAdapter
{
    public function compile(Query $query, string $action): string
    {
        $sql = parent::compile($query, $action);
        // 如果 SQL 为空，不需要转换和准备语句
        if (empty($sql)) {
            return $query->sql;
        }
        $converted = $this->convertBackticks($sql);
        if ($converted !== $sql) {
            $query->sql = $converted;
            // 确保转换后的 SQL 不为空才准备语句
            if (!empty($converted)) {
                $query->PDOStatement = $query->getLink()->prepare($converted);
            } else {
                $query->PDOStatement = null;
            }
        }
        return $query->sql;
    }

    protected function buildUpdate(Query $query, string $wheres): string
    {
        $formatter = $query->getIdentifierFormatter();
        $identity_values = array_column($query->updates, $query->identity_field);
        if ($identity_values) {
            $identity_values_str = '';
            foreach ($identity_values as $key => $identityValue) {
                $identity_values_key = ':' . md5('update_identity_values_key' . $key);
                $identity_values_str .= $identity_values_key . ',';
                $query->bound_values[$identity_values_key] = (string)$identityValue;
            }
            $identity_values_str = rtrim($identity_values_str, ',');
            $identity_field_quoted = $formatter->quote($query->identity_field);
            $wheres .= ($wheres ? ' AND ' : 'WHERE ') . "{$identity_field_quoted} IN ($identity_values_str)";
        }

        if (empty($wheres)) {
            throw new DbException(__('请设置更新条件：第一种方式，->where($condition)设置，第二种方式，更新数据中包含条件值（默认为字段id,可自行设置->update($arg1,$arg2)第二参数指定根据数组中的某个字段值作为依赖条件更新。）'));
        }

        $updates = '';
        if ($query->dec_inc_updates) {
            foreach ($query->dec_inc_updates as $dec_inc_update_field => $dec_inc_update_value) {
                $field_quoted = $formatter->quote($dec_inc_update_field);
                $updates .= "{$field_quoted} = {$field_quoted} {$dec_inc_update_value},";
            }
        }
        if ($query->updates) {
            if ($identity_values) {
                $keys = array_keys(current($query->updates));
                $identity_field_quoted = $formatter->quote($query->identity_field);
                foreach ($keys as $column) {
                    $column_quoted = $formatter->quote($column);
                    $updates .= sprintf("%s = CASE %s \n", $column_quoted, $identity_field_quoted);
                    foreach ($query->updates as $update_key => $line) {
                        $update_key += 1;
                        $identity_field_column_key = ':' . md5("{$query->identity_field}_{$column}_key_{$update_key}");
                        $query->bound_values[$identity_field_column_key] = (string)$line[$query->identity_field];
                        $identity_field_column_value = ':' . md5("update_{$column}_value_{$update_key}");
                        $value = $line[$column];
                        $query->bound_values[$identity_field_column_value] = (string)$value;
                        
                        // PostgreSQL 类型转换：如果列名看起来像整数列，添加 CAST
                        // 检测列名模式：以 _id 结尾，或者是 id，或者是常见的整数列名
                        $castType = $this->getIntegerCastType($column);
                        if ($castType) {
                            // 在 PostgreSQL 中，使用 CAST 将参数转换为相应的整数类型
                            $updates .= sprintf('WHEN %s THEN CAST(%s AS %s) ', $identity_field_column_key, $identity_field_column_value, $castType);
                        } else {
                            $updates .= sprintf('WHEN %s THEN %s ', $identity_field_column_key, $identity_field_column_value);
                        }
                    }
                    $updates .= 'END,';
                }
            } else {
                if (1 < count($query->updates)) {
                    throw new \Exception(__('更新条数大于一条时请使用示例更新：$query->table("demo")->identity("id")->update(["id"=>1,"name"=>"测试1"])->update(["id"=>2,"name"=>"测试2"])或者update中指定条件字段id：$query->table("demo")->update([["id"=>1,"name"=>"测试1"],["id"=>2,"name"=>"测试2"]],"id")'));
                }
                foreach ($query->updates[0] as $update_field => $field_value) {
                    $update_key = ':' . md5($update_field);
                    $update_field_quoted = $formatter->quote($update_field);
                    // 处理数组值：序列化为 JSON
                    if (is_array($field_value)) {
                        $field_value = json_encode($field_value, JSON_UNESCAPED_UNICODE);
                    }
                    $query->bound_values[$update_key] = $field_value === null ? null : (string)$field_value;
                    $updates .= "{$update_field_quoted} = $update_key,";
                }
            }
        }
        if ($query->single_updates) {
            foreach ($query->single_updates as $update_field => $update_value) {
                $update_field_quoted = $formatter->quote($update_field);
                $update_key = ':' . md5($update_field);
                // 处理数组值：序列化为 JSON
                if (is_array($update_value)) {
                    $update_value = json_encode($update_value, JSON_UNESCAPED_UNICODE);
                }
                $query->bound_values[$update_key] = $update_value === null ? null : (string)$update_value;
                $updates .= "{$update_field_quoted}=$update_key,";
            }
        }
        if (!$updates) {
            throw new \Exception(__('无法解析更新数据！多记录更新数据：%{1}，单记录更新数据：%{2}', [var_export($query->updates, true), var_export($query->single_updates, true)]));
        }
        $updates = rtrim($updates, ',');

        return "UPDATE {$query->table} SET {$updates} {$wheres} {$query->additional_sql} ";
    }

    /**
     * 重写 buildInsert，为 PostgreSQL 添加 RETURNING 子句
     */
    protected function buildInsert(Query $query): string
    {
        $sql = parent::buildInsert($query);
        
        // 如果 SQL 为空，直接返回
        if (empty($sql)) {
            return $sql;
        }
        
        // 为 PostgreSQL 的 INSERT 语句添加 RETURNING 子句
        // 这样可以获取插入的 ID，即使手动指定了 ID 值
        if ($query->identity_field) {
            $formatter = $query->getIdentifierFormatter();
            $identity_field_quoted = $formatter->quote($query->identity_field);
            
            // 检查 SQL 中是否已经包含 RETURNING 子句
            if (stripos($sql, 'RETURNING') !== false) {
                return $sql;
            }
            
            // 移除末尾的分号（如果存在）
            $sql = rtrim(trim($sql), ';');
            
            // 检查是否是 INSERT 语句
            if (preg_match('/^\s*INSERT\s+INTO/i', $sql)) {
                // 在语句末尾添加 RETURNING 子句
                // 注意：不添加分号，因为 PostgreSQL prepared statement 不需要末尾分号
                $sql .= " RETURNING {$identity_field_quoted}";
            }
        }
        
        return $sql;
    }

    /**
     * 获取列名对应的整数类型 CAST（如果需要）
     * @param string $columnName
     * @return string|null 返回 'SMALLINT'、'INTEGER' 或 null
     */
    private function getIntegerCastType(string $columnName): ?string
    {
        $columnNameLower = strtolower($columnName);
        
        // smallint 类型的列模式（通常是标志位、状态等）
        $smallintPatterns = [
            '/^is_/',           // 以 is_ 开头（如 is_install, is_enabled）
            '/_status$/',       // 以 _status 结尾
            '/_type$/',         // 以 _type 结尾
            '/_flag$/',         // 以 _flag 结尾
            '/_enabled$/',      // 以 _enabled 结尾
            '/_active$/',       // 以 _active 结尾
            '/_disabled$/',     // 以 _disabled 结尾
            '/_visible$/',      // 以 _visible 结尾
            '/_hidden$/',       // 以 _hidden 结尾
            '/_deleted$/',      // 以 _deleted 结尾
        ];
        
        foreach ($smallintPatterns as $pattern) {
            if (preg_match($pattern, $columnNameLower)) {
                return 'SMALLINT';
            }
        }
        
        // integer 类型的列模式（通常是 ID、计数等）
        $integerPatterns = [
            '/_id$/',           // 以 _id 结尾
            '/^id$/',           // 就是 id
            '/_count$/',        // 以 _count 结尾
            '/_num$/',          // 以 _num 结尾
            '/_number$/',       // 以 _number 结尾
            '/_quantity$/',     // 以 _quantity 结尾
            '/_amount$/',       // 以 _amount 结尾
            '/_price$/',        // 以 _price 结尾
            '/_total$/',        // 以 _total 结尾
            '/_sum$/',          // 以 _sum 结尾
            '/_size$/',         // 以 _size 结尾
            '/_length$/',       // 以 _length 结尾
            '/_width$/',        // 以 _width 结尾
            '/_height$/',       // 以 _height 结尾
            '/_weight$/',       // 以 _weight 结尾
            '/_order$/',        // 以 _order 结尾
            '/_sort$/',         // 以 _sort 结尾
            '/_level$/',        // 以 _level 结尾
        ];
        
        foreach ($integerPatterns as $pattern) {
            if (preg_match($pattern, $columnNameLower)) {
                return 'INTEGER';
            }
        }
        
        return null;
    }

    private function convertBackticks(string $sql): string
    {
        return preg_replace_callback(
            '/`([^`]+)`/',
            static function (array $matches): string {
                $identifier = $matches[1];
                if (str_contains($identifier, '.')) {
                    $parts = explode('.', $identifier);
                    return '"' . implode('"."', $parts) . '"';
                }
                return '"' . $identifier . '"';
            },
            $sql
        );
    }
}

