<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\SqLite;

use PDO;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Manager\ObjectManager;

abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\Query
{
    use SqlTrait;

    public function fetch(string $model_class = ''): mixed
    {
        if (Env::get('db_log.enabled') or DEBUG) {
            $file = Env::get('db_log.file');
            $data = [
                'prepare_sql' => $this->getPrepareSql(false),
                'sql' => $this->getSql(false),
                'data' => $this->bound_values
            ];
            Env::log($file, json_encode($data));
        }
        if ($this->batch and $this->fetch_type == 'insert') {
            $origin_data = $this->getLink()->exec($this->getSql());
            if ($origin_data === false) {
                $result = false;
            } else {
                $result = $this->getLink()->lastInsertId();
            }
            $origin_data = [];
            $this->reset();
        } else {
            # SQLITE 不支持多结果集：智能将SQL语句打散，并逐条执行后返回结果集
            $sql = $this->getSqlWithBounds($this->sql);
            // 使用正则表达式匹配SQL语句
            preg_match_all('/((?:[^;\'"]|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")+);/', $sql, $matches);
            $result = $this->PDOStatement->execute($this->bound_values);
            // $matches[0] 包含所有匹配的语句
            $statements = $matches[0];
            if (count($statements) == 1) {
                $origin_data = $this->PDOStatement->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $origin_data = [];
                foreach ($statements as $statement) {
                    $state_res = $this->getLink()->exec($statement);
                    if ($state_res !== false) {
                        $state_res = $this->getLink()->lastInsertId();
                    }
                    $origin_data[] = $state_res;
                }
            }
        }
        $this->batch = false;
        # sqlite 不支持多结果集
        $data = [];
        if ($model_class) {
            foreach ($origin_data as $origin_datum) {
                $data[] = ObjectManager::make($model_class, ['data' => $origin_datum], '__construct');
            }
        } else {
            $data = $origin_data;
        }
        switch ($this->fetch_type) {
            case 'find':
                $result = array_shift($data);
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
                break;
            default:
                throw new Exception(__('错误的获取类型。fetch之前必须有操作函数，操作函数包含（find,update,delete,select,query,insert,find）函数。'));
                break;
        }
        $this->fetch_type = '';
        if (Env::get('db_log.enabled') or DEBUG) {
            $file = Env::get('db_log.file');
            $data = [
                'prepare_sql' => $this->getPrepareSql(false),
                'sql' => $this->getLastSql(false),
                'data' => $this->bound_values,
                'result' => $origin_data
            ];
            Env::log($file, json_encode($data));
        }
        //        $this->clear();
        $this->clearQuery();
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
        $PDOStatement = $this->getLink()->prepare("delete from TABLE $table");
        $PDOStatement->execute();
        return $this;
    }

    public function query(string $sql): QueryInterface
    {
        $sql = self::formatSql($sql);
        $this->reset();
        $this->sql = $sql;
        $this->fetch_type = __FUNCTION__;
        $this->PDOStatement = $this->getLink()->prepare($sql);
        return $this;
    }
}
