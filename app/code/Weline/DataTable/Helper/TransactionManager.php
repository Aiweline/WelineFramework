<?php
/**
 * 事务管理器
 */

namespace Weline\DataTable\Helper;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection;

class TransactionManager
{
    private static ?Connection $connection = null;
    private static array $transactionStack = [];
    private static array $savepoints = [];
    private static int $transactionLevel = 0;

    /**
     * 获取数据库连接
     */
    private static function getConnection(): Connection
    {
        if (self::$connection === null) {
            $connectionFactory = \Weline\Framework\App\ObjectManager::getInstance()
                ->get(ConnectionFactory::class);
            self::$connection = $connectionFactory->getConnection();
        }
        return self::$connection;
    }

    /**
     * 开始事务
     * 
     * @param string $name 事务名称（可选）
     * @return bool 是否成功开始事务
     */
    public static function beginTransaction(string $name = ''): bool
    {
        try {
            $connection = self::getConnection();
            
            if (self::$transactionLevel === 0) {
                // 开始主事务
                $result = $connection->beginTransaction();
                if ($result) {
                    self::$transactionLevel++;
                    self::$transactionStack[] = [
                        'name' => $name ?: 'main_transaction',
                        'level' => self::$transactionLevel,
                        'started_at' => microtime(true)
                    ];
                    
                    self::log('Transaction started', $name);
                    return true;
                }
            } else {
                // 创建保存点
                $savepointName = $name ?: 'sp_' . (self::$transactionLevel + 1);
                $connection->exec("SAVEPOINT {$savepointName}");
                
                self::$transactionLevel++;
                self::$savepoints[] = $savepointName;
                self::$transactionStack[] = [
                    'name' => $savepointName,
                    'level' => self::$transactionLevel,
                    'started_at' => microtime(true),
                    'is_savepoint' => true
                ];
                
                self::log('Savepoint created', $savepointName);
                return true;
            }
        } catch (\Exception $e) {
            self::log('Failed to begin transaction', $name, $e->getMessage());
            return false;
        }
        
        return false;
    }

    /**
     * 提交事务
     * 
     * @param string $name 事务名称（可选）
     * @return bool 是否成功提交
     */
    public static function commit(string $name = ''): bool
    {
        try {
            if (self::$transactionLevel === 0) {
                self::log('No active transaction to commit', $name);
                return false;
            }

            $connection = self::getConnection();
            $lastTransaction = array_pop(self::$transactionStack);
            
            if (self::$transactionLevel === 1) {
                // 提交主事务
                $result = $connection->commit();
                if ($result) {
                    self::$transactionLevel = 0;
                    self::$savepoints = [];
                    
                    $duration = microtime(true) - $lastTransaction['started_at'];
                    self::log('Transaction committed', $lastTransaction['name'], "Duration: {$duration}s");
                    return true;
                }
            } else {
                // 释放保存点
                $savepointName = array_pop(self::$savepoints);
                $connection->exec("RELEASE SAVEPOINT {$savepointName}");
                
                self::$transactionLevel--;
                
                $duration = microtime(true) - $lastTransaction['started_at'];
                self::log('Savepoint released', $savepointName, "Duration: {$duration}s");
                return true;
            }
        } catch (\Exception $e) {
            self::log('Failed to commit transaction', $name, $e->getMessage());
            return false;
        }
        
        return false;
    }

    /**
     * 回滚事务
     * 
     * @param string $name 事务名称（可选）
     * @return bool 是否成功回滚
     */
    public static function rollback(string $name = ''): bool
    {
        try {
            if (self::$transactionLevel === 0) {
                self::log('No active transaction to rollback', $name);
                return false;
            }

            $connection = self::getConnection();
            $lastTransaction = array_pop(self::$transactionStack);
            
            if (self::$transactionLevel === 1) {
                // 回滚主事务
                $result = $connection->rollback();
                if ($result) {
                    self::$transactionLevel = 0;
                    self::$savepoints = [];
                    
                    $duration = microtime(true) - $lastTransaction['started_at'];
                    self::log('Transaction rolled back', $lastTransaction['name'], "Duration: {$duration}s");
                    return true;
                }
            } else {
                // 回滚到保存点
                $savepointName = array_pop(self::$savepoints);
                $connection->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
                
                self::$transactionLevel--;
                
                $duration = microtime(true) - $lastTransaction['started_at'];
                self::log('Rolled back to savepoint', $savepointName, "Duration: {$duration}s");
                return true;
            }
        } catch (\Exception $e) {
            self::log('Failed to rollback transaction', $name, $e->getMessage());
            return false;
        }
        
        return false;
    }

    /**
     * 执行事务操作
     * 
     * @param callable $callback 要执行的操作
     * @param string $name 事务名称
     * @return mixed 操作结果
     * @throws \Exception
     */
    public static function executeInTransaction(callable $callback, string $name = '')
    {
        if (!self::beginTransaction($name)) {
            throw new \RuntimeException('Failed to begin transaction');
        }

        try {
            $result = $callback();
            
            if (!self::commit($name)) {
                throw new \RuntimeException('Failed to commit transaction');
            }
            
            return $result;
        } catch (\Exception $e) {
            self::rollback($name);
            throw $e;
        }
    }

    /**
     * 获取当前事务级别
     * 
     * @return int 事务级别
     */
    public static function getTransactionLevel(): int
    {
        return self::$transactionLevel;
    }

    /**
     * 检查是否在事务中
     * 
     * @return bool 是否在事务中
     */
    public static function inTransaction(): bool
    {
        return self::$transactionLevel > 0;
    }

    /**
     * 获取事务栈信息
     * 
     * @return array 事务栈
     */
    public static function getTransactionStack(): array
    {
        return self::$transactionStack;
    }

    /**
     * 获取保存点列表
     * 
     * @return array 保存点列表
     */
    public static function getSavepoints(): array
    {
        return self::$savepoints;
    }

    /**
     * 强制回滚所有事务
     * 
     * @return bool 是否成功
     */
    public static function rollbackAll(): bool
    {
        try {
            if (self::$transactionLevel > 0) {
                $connection = self::getConnection();
                $connection->rollback();
                
                self::$transactionLevel = 0;
                self::$transactionStack = [];
                self::$savepoints = [];
                
                self::log('All transactions rolled back', 'force_rollback');
                return true;
            }
            return true;
        } catch (\Exception $e) {
            self::log('Failed to rollback all transactions', 'force_rollback', $e->getMessage());
            return false;
        }
    }

    /**
     * 记录事务日志
     * 
     * @param string $action 操作类型
     * @param string $name 事务名称
     * @param string $details 详细信息
     */
    private static function log(string $action, string $name = '', string $details = ''): void
    {
        $logMessage = sprintf(
            '[TransactionManager] %s - Name: %s, Level: %d, Details: %s',
            $action,
            $name,
            self::$transactionLevel,
            $details
        );
        
        // 这里可以使用框架的日志系统
        error_log($logMessage);
    }

    /**
     * 获取事务统计信息
     * 
     * @return array 统计信息
     */
    public static function getStatistics(): array
    {
        $totalDuration = 0;
        $transactionCount = count(self::$transactionStack);
        
        foreach (self::$transactionStack as $transaction) {
            $totalDuration += microtime(true) - $transaction['started_at'];
        }
        
        return [
            'current_level' => self::$transactionLevel,
            'active_transactions' => $transactionCount,
            'savepoints_count' => count(self::$savepoints),
            'total_duration' => $totalDuration,
            'in_transaction' => self::inTransaction()
        ];
    }

    /**
     * 清理事务状态（用于测试或异常情况）
     */
    public static function cleanup(): void
    {
        self::$transactionLevel = 0;
        self::$transactionStack = [];
        self::$savepoints = [];
        self::$connection = null;
        
        self::log('Transaction state cleaned up', 'cleanup');
    }
}
