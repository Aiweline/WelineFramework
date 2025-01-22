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
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Manager\ObjectManager;

abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\Query
{
    use SqlTrait;

    public function splitSqlStatements($sql)
    {
        // 正则表达式匹配不在引号内的分号
        $pattern = '/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/';

        // 使用正则表达式拆分SQL语句
        $statements = preg_split($pattern, $sql);

        // 去除每个语句的前后空格
        $statements = array_map('trim', $statements);

        // 过滤掉空语句
        $statements = array_filter($statements);

        return $statements;
    }

    public function fetch(string $model_class = ''): mixed
    {
        if (Env::get('db_log.enabled') or DEBUG) {
            $file = Env::get('db_log.file');
            Env::log($file, $this->getSqlWithBounds($this->sql));
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
            $statements = $this->splitSqlStatements($sql);
            if (count($statements) == 1) {
                $result = $this->PDOStatement->execute($this->bound_values);
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
            Env::log($file, $this->sql);
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
