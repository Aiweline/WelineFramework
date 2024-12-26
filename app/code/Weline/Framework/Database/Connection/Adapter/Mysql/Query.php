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

    protected ?PDOStatement $PDOStatement = null;
    public string $sql = '';
    public string $additional_sql = '';

    public string $fetch_type = '';

    public array $pagination = ['page' => 1, 'pageSize' => 20, 'totalSize' => 0, 'lastPage' => 0];

    public string $backup_file = '';


    public function identity(string $field): QueryInterface
    {
        $this->identity_field = $field;
        return $this;
    }

    public function table(string $table_name): QueryInterface
    {
        $this->table = $this->getTable($table_name);
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

    public function find(string $find_fields=''): QueryInterface
    {
        if($find_fields){
            $this->find_fields = $find_fields;
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
            if (DEV && !isset(self::init_vars[$attr_var_name])) {
                $this->exceptionHandle(__('不支持的清理类型：%1 支持的初始化类型：%2', [$attr_var_name, var_export(self::init_vars, true)]));
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
                $this->exceptionHandle(__('不支持的清理类型：%1 支持的初始化类型：%2', [$attr_var_name, var_export(self::init_vars, true)]));
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
            $backupFile = Env::backup_dir . 'db' . DS . $table . DS . $originTable . '_' . date('Y-m-d H:i:s') . '.sql';
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
