<?php

declare(strict_types=1);

namespace Weline\DataTable\Helper;

use Weline\Framework\Database\Connection\ConnectionInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestResetException;

class TransactionManager
{
    private const REQUEST_STATE_KEY = 'datatable.transaction_manager.state.v1';

    private static function getConnection(): ConnectionInterface
    {
        $state = self::requestState();
        if (!$state['connection'] instanceof ConnectionInterface) {
            /** @var ConnectionFactory $connectionFactory */
            $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
            $connector = $connectionFactory->getConnectorAdapter()->create();
            try {
                $connection = $connector->getWrappedConnection();
            } catch (\Throwable $connectionFailure) {
                try {
                    $connector->close();
                } catch (\Throwable $closeFailure) {
                    throw new \RuntimeException(
                        sprintf(
                            'Wrapped connection acquisition failed (%s: %s); connector close also failed (%s: %s).',
                            $connectionFailure::class,
                            $connectionFailure->getMessage(),
                            $closeFailure::class,
                            $closeFailure->getMessage(),
                        ),
                        0,
                        $connectionFailure,
                    );
                }
                throw $connectionFailure;
            }

            $state['connector'] = $connector;
            $state['connection'] = $connection;
            self::storeRequestState($state);
        }

        return $state['connection'];
    }

    public static function beginTransaction(string $name = ''): bool
    {
        try {
            $connection = self::getConnection();
            $state = self::requestState();

            if ($state['transaction_level'] === 0) {
                if (!$connection->beginTransaction()) {
                    return false;
                }

                $state['transaction_level'] = 1;
                $state['transaction_stack'][] = [
                    'name' => $name !== '' ? $name : 'main_transaction',
                    'level' => $state['transaction_level'],
                    'started_at' => microtime(true),
                ];
                self::storeRequestState($state);

                self::log('Transaction started', $name);
                return true;
            }

            $savepointName = self::sanitizeSavepointName(
                $name !== '' ? $name : 'sp_' . ($state['transaction_level'] + 1)
            );
            $connection->execute(sprintf('SAVEPOINT %s', $savepointName));

            $state['transaction_level']++;
            $state['savepoints'][] = $savepointName;
            $state['transaction_stack'][] = [
                'name' => $savepointName,
                'level' => $state['transaction_level'],
                'started_at' => microtime(true),
                'is_savepoint' => true,
            ];
            self::storeRequestState($state);

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
            $state = self::requestState();
            if ($state['transaction_level'] === 0) {
                self::log('No active transaction to commit', $name);
                return false;
            }

            $connection = self::getConnection();
            $lastTransaction = end($state['transaction_stack']);
            $lastTransaction = is_array($lastTransaction) ? $lastTransaction : null;

            if ($state['transaction_level'] === 1) {
                if (!$connection->commit()) {
                    return false;
                }

                array_pop($state['transaction_stack']);
                $state['transaction_level'] = 0;
                $state['savepoints'] = [];
                self::storeRequestState($state);

                $duration = $lastTransaction ? microtime(true) - (float) $lastTransaction['started_at'] : 0.0;
                self::log('Transaction committed', $lastTransaction['name'] ?? $name, sprintf('Duration: %.6fs', $duration));
                return true;
            }

            $savepointName = end($state['savepoints']);
            if ($savepointName === null) {
                return false;
            }
            if (!is_string($savepointName) || $savepointName === '') {
                return false;
            }

            $connection->execute(sprintf('RELEASE SAVEPOINT %s', $savepointName));
            array_pop($state['savepoints']);
            array_pop($state['transaction_stack']);
            $state['transaction_level']--;
            self::storeRequestState($state);

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
            $state = self::requestState();
            if ($state['transaction_level'] === 0) {
                self::log('No active transaction to rollback', $name);
                return false;
            }

            $connection = self::getConnection();
            $lastTransaction = end($state['transaction_stack']);
            $lastTransaction = is_array($lastTransaction) ? $lastTransaction : null;

            if ($state['transaction_level'] === 1) {
                if (!$connection->rollBack()) {
                    return false;
                }

                array_pop($state['transaction_stack']);
                $state['transaction_level'] = 0;
                $state['savepoints'] = [];
                self::storeRequestState($state);

                $duration = $lastTransaction ? microtime(true) - (float) $lastTransaction['started_at'] : 0.0;
                self::log('Transaction rolled back', $lastTransaction['name'] ?? $name, sprintf('Duration: %.6fs', $duration));
                return true;
            }

            $savepointName = end($state['savepoints']);
            if ($savepointName === null) {
                return false;
            }
            if (!is_string($savepointName) || $savepointName === '') {
                return false;
            }

            $connection->execute(sprintf('ROLLBACK TO SAVEPOINT %s', $savepointName));
            array_pop($state['savepoints']);
            array_pop($state['transaction_stack']);
            $state['transaction_level']--;
            self::storeRequestState($state);

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
        return self::requestState()['transaction_level'];
    }

    public static function inTransaction(): bool
    {
        return self::getTransactionLevel() > 0;
    }

    /**
     * @return array<int, array{name:string,level:int,started_at:float,is_savepoint?:bool}>
     */
    public static function getTransactionStack(): array
    {
        return self::requestState()['transaction_stack'];
    }

    /**
     * @return array<int, string>
     */
    public static function getSavepoints(): array
    {
        return self::requestState()['savepoints'];
    }

    public static function rollbackAll(): bool
    {
        try {
            $state = self::requestState();
            if ($state['transaction_level'] > 0) {
                self::getConnection()->rollBack();
                $state['transaction_level'] = 0;
                $state['transaction_stack'] = [];
                $state['savepoints'] = [];
                self::storeRequestState($state);
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
        $state = self::requestState();
        $totalDuration = 0.0;
        foreach ($state['transaction_stack'] as $transaction) {
            $totalDuration += microtime(true) - (float) $transaction['started_at'];
        }

        return [
            'current_level' => $state['transaction_level'],
            'active_transactions' => count($state['transaction_stack']),
            'savepoints_count' => count($state['savepoints']),
            'total_duration' => $totalDuration,
            'in_transaction' => $state['transaction_level'] > 0,
        ];
    }

    public static function cleanup(): void
    {
        $state = self::requestState();
        $connection = $state['connection'];
        $connector = $state['connector'];
        $failures = [];
        $unsafeConnector = false;

        try {
            if ($connection instanceof ConnectionInterface && $connection->inTransaction()) {
                if (!$connection->rollBack()) {
                    $markedUnhealthy = self::markConnectionUnhealthy($connection);
                    $failures[] = [
                        'stage' => 'rollback',
                        'exception' => new \RuntimeException(
                            'DataTable request transaction rollback returned false during cleanup.'
                        ),
                    ];
                    if (!$markedUnhealthy) {
                        $failures[] = [
                            'stage' => 'mark_connection_unhealthy',
                            'exception' => new \RuntimeException(
                                'DataTable request connection could not be marked unhealthy after rollback failure.'
                            ),
                        ];
                        $unsafeConnector = $connector instanceof ConnectorInterface;
                    }
                    self::log('Failed to rollback transaction during cleanup', 'cleanup');
                }
            }
        } catch (\Throwable $throwable) {
            if ($connection instanceof ConnectionInterface) {
                if (!self::markConnectionUnhealthy($connection)) {
                    $failures[] = [
                        'stage' => 'mark_connection_unhealthy',
                        'exception' => new \RuntimeException(
                            'DataTable request connection could not be marked unhealthy after rollback exception.'
                        ),
                    ];
                    $unsafeConnector = $connector instanceof ConnectorInterface;
                }
            }
            RequestResetException::append($failures, 'rollback', $throwable);
            self::log('Failed to rollback transaction during cleanup', 'cleanup', $throwable->getMessage());
        }

        if (!$unsafeConnector) {
            RequestContext::remove(self::REQUEST_STATE_KEY);
        }
        if ($connector instanceof ConnectorInterface && !$unsafeConnector) {
            try {
                $connector->close();
            } catch (\Throwable $throwable) {
                RequestResetException::append($failures, 'connector_close', $throwable);
                w_log_warning('[TransactionManager] Failed to close request connector: ' . $throwable->getMessage());
            }
        }

        self::log(
            $unsafeConnector
                ? 'Transaction state retained because connector release is unsafe'
                : 'Transaction state cleaned up',
            'cleanup'
        );

        if ($failures !== []) {
            throw new RequestResetException('datatable_transaction_manager_cleanup', $failures);
        }
    }

    /**
     * @return array{
     *     connector: ConnectorInterface|null,
     *     connection: ConnectionInterface|null,
     *     transaction_stack: array<int, array{name:string,level:int,started_at:float,is_savepoint?:bool}>,
     *     savepoints: array<int, string>,
     *     transaction_level: int
     * }
     */
    private static function requestState(): array
    {
        $state = RequestContext::get(self::REQUEST_STATE_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }

        return [
            'connector' => ($state['connector'] ?? null) instanceof ConnectorInterface
                ? $state['connector']
                : null,
            'connection' => ($state['connection'] ?? null) instanceof ConnectionInterface
                ? $state['connection']
                : null,
            'transaction_stack' => is_array($state['transaction_stack'] ?? null)
                ? array_values($state['transaction_stack'])
                : [],
            'savepoints' => is_array($state['savepoints'] ?? null)
                ? array_values($state['savepoints'])
                : [],
            'transaction_level' => max(0, (int)($state['transaction_level'] ?? 0)),
        ];
    }

    private static function storeRequestState(array $state): void
    {
        RequestContext::set(self::REQUEST_STATE_KEY, $state);
    }

    private static function markConnectionUnhealthy(ConnectionInterface $connection): bool
    {
        try {
            ConnectionPool::markConnectionUnhealthy($connection->getPdo());
            return true;
        } catch (\Throwable $throwable) {
            w_log_warning(
                '[TransactionManager] Failed to mark request connection unhealthy: ' . $throwable->getMessage()
            );
            return false;
        }
    }

    private static function log(string $action, string $name = '', string $details = ''): void
    {
        $logMessage = sprintf(
            '[TransactionManager] %s - Name: %s, Level: %d, Details: %s',
            $action,
            $name,
            self::getTransactionLevel(),
            $details
        );

        w_log_info($logMessage);
    }

    private static function sanitizeSavepointName(string $value): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            return 'sp_' . (self::getTransactionLevel() + 1);
        }

        if (preg_match('/^[0-9]/', $normalized)) {
            $normalized = 'sp_' . $normalized;
        }

        return $normalized;
    }
}
