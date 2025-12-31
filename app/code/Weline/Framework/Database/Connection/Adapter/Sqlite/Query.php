<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Sqlite;

use PDO;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Manager\ObjectManager;

abstract class Query extends \Weline\Framework\Database\Connection\Api\Sql\Query
{
    use SqlTrait;
    
    // 重试配置
    private const MAX_RETRY_ATTEMPTS = 5;
    private const RETRY_DELAY_MS = 100;

    /**
     * 获取数据库连接
     */
    abstract public function getLink(): PDO;

    public function splitSqlStatements($sql)
    {
        // 正则表达式匹配不在引号内的分号
        $pattern = '/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/';

        // 使用正则表达式拆分SQL语句
        $statements = preg_split($pattern, $sql);

        // 去除每个语句的前后空格
        $statements = $statements ? array_map('trim', $statements) : [];

        // 过滤掉空语句
        return array_filter($statements);
    }

    public function fetch(string $model_class = ''): mixed
    {
        // Development SQL logging - log SQL with actual values
        try {
            if (Env::get('log.dev_sql.enabled', false)) {
                $log_file = Env::get('log.dev_sql.file', 'dev_sql');
                // Get SQL with bound values replaced
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($log_file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
        }
        
        // Database query logging - only if enabled
        try {
            if (Env::get('log.db.enabled', false)) {
                $file = Env::get('log.db.file', 'db');
                // Use compact standard format: [timestamp] [QUERY] source - SQL
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
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
            // 使用重试机制执行批量插入
            $origin_data = $this->executeWithRetry(function() {
                $result = $this->getLink()->exec($this->getSql());
                if ($result === false) {
                    return false;
                } else {
                    return $this->getLink()->lastInsertId();
                }
            });
            $this->reset();
        } else {
            # SQLITE 不支持多结果集：将SQL语句打散，并逐条执行后返回结果集
            $sql = $this->getSqlWithBounds($this->sql);
            $statements = $this->splitSqlStatements($sql);
            
            if (count($statements) == 1) {
                // 使用重试机制执行单条语句
                $origin_data = $this->executeWithRetry(function() {
                    $this->PDOStatement = $this->getLink()->prepare($this->sql);
                    $this->PDOStatement->execute($this->bound_values);
                    return $this->PDOStatement->fetchAll(PDO::FETCH_ASSOC);
                });
            } else {
                // 使用重试机制执行多条语句
                $origin_data = [];
                foreach ($statements as $statement) {
                    $state_res = $this->executeWithRetry(function() use ($statement) {
                        $result = $this->getLink()->exec($statement);
                        if ($result !== false) {
                            return $this->getLink()->lastInsertId();
                        }
                        return $result;
                    });
                    $origin_data[] = $state_res;
                }
            }
        }
        $this->batch = false;
        # sqlite 不支持多结果集
        $data = [];
        if ($model_class and is_array($origin_data)) {
            foreach ($origin_data as $origin_datum) {
                $data[] = ObjectManager::make($model_class, ['data' => $origin_datum], '__construct');
            }
        } else {
            $data = $origin_data;
        }

        switch ($this->fetch_type) {
            case 'find':
                $result = array_shift($data);
                if ($this->find_fields) {
                    if ($result) {
                        if (str_contains($this->find_fields, ',')) {
                            $fields = explode(',', $this->find_fields);
                            $fields_data = [];
                            foreach ($fields as $field) {
                                if (str_contains($field, '.')) {
                                    $field = explode('.', $field);
                                    $field = $field[1];
                                }
                                $fields_data[$field] = $result[$field] ?? null;
                            }
                            $result = $fields_data;
                        } else {
                            $field = $this->find_fields;
                            if (str_contains($field, '.')) {
                                $field = explode('.', $field);
                                $field = $field[1];
                            }
                            $result = $result[$field] ?? null;
                        }
                    }
                    $this->find_fields = '';
                    break;
                }
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
                $result = (bool)$data;
                break;
            default:
                throw new Exception(__('错误的获取类型。fetch之前必须有操作函数，操作函数包含（find,update,delete,select,query,insert,find）函数。'));
        }
        $this->fetch_type = '';
        
        // Development SQL logging - log SQL with actual values
        try {
            if (Env::get('log.dev_sql.enabled', false)) {
                $log_file = Env::get('log.dev_sql.file', 'dev_sql');
                // Get SQL with bound values replaced
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($log_file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
        }
        
        // Database query logging - only if enabled
        try {
            if (Env::get('log.db.enabled', false)) {
                $file = Env::get('log.db.file', 'db');
                // Use compact standard format: [timestamp] [QUERY] source - SQL
                $sqlWithValues = $this->getSqlWithBounds($this->sql);
                Env::log($file, $sqlWithValues, 'QUERY', true, true, 0);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors during bootstrap
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
        if ($this->table_alias !== 'main_table') $this->alias('main_table');
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
        $PDOStatement = $this->getLink()->prepare("DELETE FROM $table");
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

    /**
     * 执行带重试机制的数据库操作
     */
    protected function executeWithRetry(callable $operation, array $params = []): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                return call_user_func_array($operation, $params);
                
            } catch (\PDOException $e) {
                $lastException = $e;
                $attempts++;
                
                // 如果是数据库锁定错误，进行重试
                if ($this->isDatabaseLockedError($e) && $attempts < self::MAX_RETRY_ATTEMPTS) {
                    $this->waitBeforeRetry($attempts);
                    continue;
                }
                
                // 如果不是锁定错误或达到最大重试次数，抛出异常
                break;
            }
        }

        throw new Exception("数据库操作失败，已重试 {$attempts} 次。最后错误: " . $lastException->getMessage());
    }

    /**
     * 检查是否是数据库锁定错误
     */
    protected function isDatabaseLockedError(\PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'database is locked') || 
               str_contains($message, 'database table is locked') ||
               str_contains($message, 'sqlite_busy') ||
               $e->getCode() === 5; // SQLITE_BUSY
    }

    /**
     * 重试前等待
     */
    protected function waitBeforeRetry(int $attempt): void
    {
        // 指数退避算法：每次重试等待时间递增
        $delay = self::RETRY_DELAY_MS * pow(2, $attempt - 1);
        $maxDelay = 1000; // 最大延迟1秒
        $delay = min($delay, $maxDelay);
        
        // 添加随机抖动避免惊群效应
        $jitter = rand(0, intval($delay * 0.1));
        $delay += $jitter;
        
        usleep($delay * 1000); // 转换为微秒
    }

    /**
     * 归档数据 - SQLite 兼容版本
     *
     * @param string $period ['all'=>'全部','today'=>'今天','yesterday'=>'昨天','current_week'=>'这周','near_week'=>'最近一周','last_week'=>'上周','near_month'=>'近三十天','current_month'=>'本月','last_month'=>'上一月','quarter'=>'本季度','last_quarter'=>'上个季度','current_year'=>'今年','last_year'=>'上一年']
     * @param string $field [默认按照'create_time'字段归档，可指定归档字段]
     *
     * @return $this
     * @throws Exception
     */
    public function period(string $period, string $field = 'create_time'): static
    {
        # 提取$period中包含的数字
        $period_number = preg_replace('/\D/', '', $period);
        if ($period_number) {
            $period = str_replace($period_number, '{number}', $period);
        }
        $period_number = intval($period_number);

        if (!is_int(strpos($field, '.'))) {
            $field = $this->table_alias . '.' . $field;
        }
        
        switch ($period) {
            case 'all':
                break;
            case 'today':
                #今天
                $this->where("date({$field}) = date('now')");
                break;
            case 'yesterday':
            case 'last_day':
                #昨天
                $this->where("date({$field}) = date('now', '-1 day')");
                break;
            case 'the_day_{number}_days_ago':
                #提取数字指定几天前的那一天
                $this->where("date({$field}) = date('now', '-{$period_number} day')");
                break;
            case 'current_week':
                #查询当前这周的数据
                $this->where("strftime('%Y%W', {$field}) = strftime('%Y%W', 'now')");
                break;
            case 'near_week':
                #近7天
                $this->where("date('now', '-7 day') <= date({$field})");
                break;
            case 'last_week':
                #查询上周的数据
                $this->where("strftime('%Y%W', {$field}) = strftime('%Y%W', date('now', '-7 day'))");
                break;
            case 'the_week_{number}_weeks_ago':
                #提取数字指定几周之前的那个周
                $daysAgo = $period_number * 7;
                $this->where("strftime('%Y%W', {$field}) = strftime('%Y%W', date('now', '-{$daysAgo} day'))");
                break;
            case 'near_month':
                #近30天
                $this->where("date('now', '-30 day') <= date({$field})");
                break;
            case 'current_month':
                # 本月
                $this->where("strftime('%Y%m', {$field}) = strftime('%Y%m', 'now')");
                break;
            case 'last_month':
                #上一月
                $this->where("strftime('%Y%m', {$field}) = strftime('%Y%m', date('now', 'start of month', '-1 month'))");
                break;
            case 'the_month_{number}_months_ago':
                #提取数字指定几个月份之前的月份
                $this->where("strftime('%Y%m', {$field}) = strftime('%Y%m', date('now', 'start of month', '-{$period_number} month'))");
                break;
            case 'quarter':
                #查询本季度数据
                $currentMonth = "CAST(strftime('%m', 'now') AS INTEGER)";
                $quarterStart = "(({$currentMonth} - 1) / 3 * 3 + 1)";
                $this->where("CAST(strftime('%m', {$field}) AS INTEGER) >= {$quarterStart} AND CAST(strftime('%m', {$field}) AS INTEGER) < ({$quarterStart} + 3) AND strftime('%Y', {$field}) = strftime('%Y', 'now')");
                break;
            case 'last_quarter':
                #查询上季度数据
                $lastQuarterMonth = "CAST(strftime('%m', date('now', '-3 month')) AS INTEGER)";
                $quarterStart = "(({$lastQuarterMonth} - 1) / 3 * 3 + 1)";
                $this->where("CAST(strftime('%m', {$field}) AS INTEGER) >= {$quarterStart} AND CAST(strftime('%m', {$field}) AS INTEGER) < ({$quarterStart} + 3) AND strftime('%Y', {$field}) = strftime('%Y', date('now', '-3 month'))");
                break;
            case 'the_quarter_{number}_quarters_ago':
                #提取数字指定几个季度前那个季度
                $monthsAgo = $period_number * 3;
                $targetMonth = "CAST(strftime('%m', date('now', '-{$monthsAgo} month')) AS INTEGER)";
                $quarterStart = "(({$targetMonth} - 1) / 3 * 3 + 1)";
                $this->where("CAST(strftime('%m', {$field}) AS INTEGER) >= {$quarterStart} AND CAST(strftime('%m', {$field}) AS INTEGER) < ({$quarterStart} + 3) AND strftime('%Y', {$field}) = strftime('%Y', date('now', '-{$monthsAgo} month'))");
                break;
            case 'current_year':
                #查询本年数据
                $this->where("strftime('%Y', {$field}) = strftime('%Y', 'now')");
                break;
            case 'last_year':
                #查询上年数据
                $this->where("strftime('%Y', {$field}) = strftime('%Y', date('now', '-1 year'))");
                break;
            case 'the_year_{number}_years_ago':
                #提取数字指定几年前的那年
                $this->where("strftime('%Y', {$field}) = strftime('%Y', date('now', '-{$period_number} year'))");
                break;
            default:
        }
        return $this;
    }
}
