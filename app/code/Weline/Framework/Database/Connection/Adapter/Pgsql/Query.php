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
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\SqlTrait;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Manager\ObjectManager;

abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\Query
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
     * 重写 query 方法，在执行前转换 MySQL 特有语法
     */
    public function query(string $sql): QueryInterface
    {
        // 转换 SHOW FULL COLUMNS FROM 语法
        $sql = self::convertShowColumnsToInformationSchema($sql);
        // 转换反引号为双引号
        $sql = self::convertBackticksToDoubleQuotes($sql);
        
        return parent::query($sql);
    }


    public function insertOld(array $data, array|string $update_fields = [], string $update_where_fields = '', bool $ignore_primary_key = false): QueryInterface
    {
        if (empty($data)) {
            throw new DbException('插入数据不能为空！');
        }
        if ($update_fields) {
            // PostgreSQL 使用 ON CONFLICT 语法
            $this->exist_update_sql = 'ON CONFLICT DO UPDATE SET ';
            if (is_string($update_fields)) {
                $exist_update_fields = explode(',', $update_fields);
                foreach ($exist_update_fields as $field) {
                    $field = trim(str_replace(['`', '"'], '', $field));
                    $this->exist_update_sql .= "\"{$field}\"=EXCLUDED.\"{$field}\",";
                }
            } else {
                foreach ($update_fields as $field) {
                    $field = trim(str_replace(['`', '"'], '', $field));
                    $this->exist_update_sql .= "\"{$field}\"=EXCLUDED.\"{$field}\",";
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
        if (count($this->insert ?? [])) {
            $first_insert = $this->insert[array_key_first($this->insert)];
            foreach ($first_insert as $field => $value) {
                $field = str_replace(['`', '"'], '', $field);
                $fields .= "\"{$field}\",";
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

    public function fields(string $fields): QueryInterface
    {
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

    public function order(string $field = '', string $sort = 'DESC'): QueryInterface
    {
        if (empty($field)) {
            $field = $this->identity_field;
        }
        // PostgreSQL 使用双引号
        if (!str_contains($field, '"') && !str_contains($field, '`')) {
            $field = self::parserFiled($field);
        }
        // 将反引号替换为双引号
        $field = str_replace('`', '"', $field);
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

    /**
     * 解析字段名，将反引号替换为双引号
     * PostgreSQL 使用双引号作为标识符
     */
    protected static function parserFiled(mixed &$field): mixed
    {
        if (!is_string($field)) {
            return $field;
        }
        
        // 去除反引号，添加双引号
        $field = str_replace('`', '', $field);
        // 如果包含点号，分别处理
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            $field = '"' . implode('"."', $parts) . '"';
        } else {
            $field = '"' . $field . '"';
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
            $schema = 'public';
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
                COALESCE(col_description(('{$schema}.{$table}')::regclass::oid, c.ordinal_position), '') AS \"Comment\"
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
    
}

