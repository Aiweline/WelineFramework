<?php

declare(strict_types=1);

namespace Weline\DataTable\Helper;

use Weline\Framework\Database\Connection\ConnectionInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class TransactionManager
{
    private static ?ConnectionInterface $connection = null;

    /**
     * @var array<int, array{name:string,level:int,started_at:float,is_savepoint?:bool}>
     */
    private static array $transactionStack = [];

    /**
     * @var array<int, string>
     */
    private static array $savepoints = [];

    private static int $transactionLevel = 0;

    private static function getConnection(): ConnectionInterface
    {
        if (self::$connection === null) {
            /** @var ConnectionFactory $connectionFactory */
            $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
            self::$connection = $connectionFactory->getConnector()->getWrappedConnection();
        }

        return self::$connection;
    }

    public static function beginTransaction(string $name = ''): bool
    {
        try {
            $connection = self::getConnection();

            if (self::$transactionLevel === 0) {
                if (!$connection->beginTransaction()) {
                    return false;
                }

                self::$transactionLevel = 1;
                self::$transactionStack[] = [
                    'name' => $name !== '' ? $name : 'main_transaction',
                    'level' => self::$transactionLevel,
                    'started_at' => microtime(true),
                ];

                self::log('Transaction started', $name);
                return true;
            }

            $savepointName = self::sanitizeSavepointName($name !== '' ? $name : 'sp_' . (self::$transactionLevel + 1));
            $connection->execute(sprintf('SAVEPOINT %s', $savepointName));

            self::$transactionLevel++;
            self::$savepoints[] = $savepointName;
            self::$transactionStack[] = [
                'name' => $savepointName,
                'level' => self::$transactionLevel,
                'started_at' => microtime(true),
                'is_savepoint' => true,
            ];

            self::log('Savepoint created', $savepointName);
            return true;
        } catch (\Throwable $throwable) {
            self::log('Failed to begin transaction', $name, $throwable->getMessage());
            return false;
        }
    }

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
                if (!$connection->commit()) {
                    return false;
                }

                self::$transactionLevel = 0;
                self::$savepoints = [];

                $duration = $lastTransaction ? microtime(true) - (float) $lastTransaction['started_at'] : 0.0;
                self::log('Transaction committed', $lastTransaction['name'] ?? $name, sprintf('Duration: %.6fs', $duration));
                return true;
            }

            $savepointName = array_pop(self::$savepoints);
            if ($savepointName === null) {
                return false;
            }

            $connection->execute(sprintf('RELEASE SAVEPOINT %s', $savepointName));
            self::$transactionLevel--;

            $duration = $lastTransaction ? microtime(true) - (float) $lastTransaction['started_at'] : 0.0;
            self::log('Savepoint released', $savepointName, sprintf('Duration: %.6fs', $duration));
            return true;
        } catch (\Throwable $throwable) {
            self::log('Failed to commit transaction', $name, $throwable->getMessage());
            return false;
        }
    }

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
                if (!$connection->rollBack()) {
                    return false;
                }

                self::$transactionLevel = 0;
                self::$savepoints = [];

                $duration = $lastTransaction ? microtime(true) - (float) $lastTransaction['started_at'] : 0.0;
                self::log('Transaction rolled back', $lastTransaction['name'] ?? $name, sprintf('Duration: %.6fs', $duration));
                return true;
            }

            $savepointName = array_pop(self::$savepoints);
            if ($savepointName === null) {
                return false;
            }

            $connection->execute(sprintf('ROLLBACK TO SAVEPOINT %s', $savepointName));
            self::$transactionLevel--;

            $duration = $lastTransaction ? microtime(true) - (float) $lastTransaction['started_at'] : 0.0;
            self::log('Rolled back to savepoint', $savepointName, sprintf('Duration: %.6fs', $duration));
            return true;
        } catch (\Throwable $throwable) {
            self::log('Failed to rollback transaction', $name, $throwable->getMessage());
            return false;
        }
    }

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
        } catch (\Throwable $throwable) {
            self::rollback($name);
            throw $throwable;
        }
    }

    public static function getTransactionLevel(): int
    {
        return self::$transactionLevel;
    }

    public static function inTransaction(): bool
    {
        return self::$transactionLevel > 0;
    }

    /**
     * @return array<int, array{name:string,level:int,started_at:float,is_savepoint?:bool}>
     */
    public static function getTransactionStack(): array
    {
        return self::$transactionStack;
    }

    /**
     * @return array<int, string>
     */
    public static function getSavepoints(): array
    {
        return self::$savepoints;
    }

    public static function rollbackAll(): bool
    {
        try {
            if (self::$transactionLevel > 0) {
                self::getConnection()->rollBack();
                self::$transactionLevel = 0;
                self::$transactionStack = [];
                self::$savepoints = [];
                self::log('All transactions rolled back', 'force_rollback');
            }

            return true;
        } catch (\Throwable $throwable) {
            self::log('Failed to rollback all transactions', 'force_rollback', $throwable->getMessage());
            return false;
        }
    }

    /**
     * @return array{current_level:int,active_transactions:int,savepoints_count:int,total_duration:float,in_transaction:bool}
     */
    public static function getStatistics(): array
    {
        $totalDuration = 0.0;
        foreach (self::$transactionStack as $transaction) {
            $totalDuration += microtime(true) - (float) $transaction['started_at'];
        }

        return [
            'current_level' => self::$transactionLevel,
            'active_transactions' => count(self::$transactionStack),
            'savepoints_count' => count(self::$savepoints),
            'total_duration' => $totalDuration,
            'in_transaction' => self::inTransaction(),
        ];
    }

    public static function cleanup(): void
    {
        self::$transactionLevel = 0;
        self::$transactionStack = [];
        self::$savepoints = [];
        self::$connection = null;

        self::log('Transaction state cleaned up', 'cleanup');
    }

    private static function log(string $action, string $name = '', string $details = ''): void
    {
        $logMessage = sprintf(
            '[TransactionManager] %s - Name: %s, Level: %d, Details: %s',
            $action,
            $name,
            self::$transactionLevel,
            $details
        );

        w_log_info($logMessage);
    }

    private static function sanitizeSavepointName(string $value): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            return 'sp_' . (self::$transactionLevel + 1);
        }

        if (preg_match('/^[0-9]/', $normalized)) {
            $normalized = 'sp_' . $normalized;
        }

        return $normalized;
    }
}
