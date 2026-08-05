<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Transaction;

use Throwable;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\WriteIntentQueryInterface;
use Weline\Framework\Database\Connection\ConnectionInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\Exception\RollbackOnlyException;
use Weline\Framework\Database\Transaction\Exception\TransactionStateException;
use Weline\Framework\Database\Transaction\Exception\UnsupportedAsyncTransactionConnectionException;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Runtime\RequestContext;

final class TransactionCoordinator implements WriteIntentTransactionCoordinatorInterface
{
    public function run(ConnectionFactory $connection, callable $callback): mixed
    {
        return $this->runWithIntent($connection, $callback, false);
    }

    public function runWrite(ConnectionFactory $connection, callable $callback): mixed
    {
        return $this->runWithIntent($connection, $callback, true);
    }

    private function runWithIntent(
        ConnectionFactory $connection,
        callable $callback,
        bool $writeIntent,
    ): mixed
    {
        $connector = $connection->getConnector();
        $state = TransactionContext::transactionState($connector);
        if ($state !== null) {
            self::assertStatePdo($state, $connector);
            if ($writeIntent && !$state->isWriteIntent()) {
                throw new TransactionStateException('transaction_write_intent_upgrade_unsupported');
            }
        }
        $query = $state?->ownerQuery() ?? $connector->getQuery();

        try {
            if ($writeIntent) {
                if (!$query instanceof WriteIntentQueryInterface) {
                    throw new TransactionStateException('transaction_write_intent_query_unsupported');
                }
                $query->beginWriteTransaction();
            } else {
                $query->beginTransaction();
            }
            $result = $callback();
            $query->commit();
            return $result;
        } catch (Throwable $exception) {
            $state = TransactionContext::transactionState($connector);
            if ($state !== null) {
                $state->markRollbackOnly($exception);
                TransactionContext::storeTransactionState($connector, $state);
                try {
                    $query->rollBack();
                } catch (Throwable $rollbackFailure) {
                    self::logFailure('run_rollback', $rollbackFailure, [
                        'original_exception' => $exception::class,
                    ]);
                }
            }
            throw $exception;
        } finally {
            $query->clearQuery();
        }
    }

    public function isActive(ConnectionFactory $connection): bool
    {
        $connector = $connection->getConnector();
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            return false;
        }
        self::assertStatePdo($state, $connector);
        return true;
    }

    public function isWriteIntent(ConnectionFactory $connection): bool
    {
        $connector = $connection->getConnector();
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            return false;
        }
        self::assertStatePdo($state, $connector);
        return $state->isWriteIntent();
    }

    public function markRollbackOnly(ConnectionFactory $connection, ?Throwable $cause = null): void
    {
        $connector = $connection->getConnector();
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            throw new TransactionStateException(__('当前连接没有活动事务，无法标记仅回滚'));
        }

        self::assertStatePdo($state, $connector);
        $state->markRollbackOnly($cause);
        TransactionContext::storeTransactionState($connector, $state);
    }

    public function afterCommit(ConnectionFactory $connection, string $key, callable $callback): void
    {
        $connector = $connection->getConnector();
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            self::assertNoUnmanagedTransaction($connector);
            $callback();
            return;
        }

        self::assertStatePdo($state, $connector);
        $state->addAfterCommit($key, $callback);
        TransactionContext::storeTransactionState($connector, $state);
    }

    public function afterRollback(ConnectionFactory $connection, string $key, callable $callback): void
    {
        $connector = $connection->getConnector();
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            throw new TransactionStateException(__('当前连接没有活动事务，无法登记回滚回调'));
        }

        self::assertStatePdo($state, $connector);
        $state->addAfterRollback($key, $callback);
        TransactionContext::storeTransactionState($connector, $state);
    }

    public function withSavepoint(ConnectionFactory $connection, string $purpose, callable $callback): mixed
    {
        $connector = $connection->getConnector();
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            throw new TransactionStateException(__('保存点只能在活动事务中使用'));
        }

        self::assertStatePdo($state, $connector);
        $owner = $state->ownerQuery();
        $ownerConnector = self::connectorForQuery($owner);
        self::assertOwnerAndPdo($state, $owner, $ownerConnector);

        $snapshot = $state->savepointSnapshot();
        $savepoint = $state->nextSavepointName($purpose);
        TransactionContext::storeTransactionState($connector, $state);
        $physical = $ownerConnector->getWrappedConnection();

        try {
            self::executeControlStatement($physical, 'SAVEPOINT ' . $savepoint);
        } catch (Throwable $exception) {
            if (TransactionContext::transactionState($connector) === $state) {
                $state->markRollbackOnly($exception);
                TransactionContext::storeTransactionState($connector, $state);
            }
            throw $exception;
        }

        $state->enterSavepointScope();
        TransactionContext::storeTransactionState($connector, $state);
        try {
            $result = $callback();
            if (TransactionContext::transactionState($connector) !== $state) {
                throw new TransactionStateException(__('保存点回调改变了事务所有权'));
            }
            if ($state->depth() !== $snapshot['depth'] + 1) {
                throw new TransactionStateException(__('保存点回调结束时事务嵌套深度不平衡'));
            }
            if (
                $state->isRollbackOnly() !== $snapshot['rollback_only']
                || $state->rollbackCause() !== $snapshot['rollback_cause']
            ) {
                throw new TransactionStateException(__('保存点回调尝试回滚或结束外层事务'));
            }
        } catch (Throwable $callbackFailure) {
            try {
                self::executeControlStatement($physical, 'ROLLBACK TO SAVEPOINT ' . $savepoint);
                self::executeControlStatement($physical, 'RELEASE SAVEPOINT ' . $savepoint);
            } catch (Throwable $savepointFailure) {
                if (TransactionContext::transactionState($connector) === $state) {
                    $state->markRollbackOnly($savepointFailure);
                    TransactionContext::storeTransactionState($connector, $state);
                }
                self::logFailure('savepoint_rollback', $savepointFailure, [
                    'callback_exception' => $callbackFailure::class,
                    'savepoint' => $savepoint,
                ]);
                throw new TransactionStateException(
                    __('保存点回滚失败，事务已标记为仅回滚'),
                    0,
                    $savepointFailure
                );
            }
            if (TransactionContext::transactionState($connector) === $state) {
                $state->restoreSavepointSnapshot($snapshot);
                TransactionContext::storeTransactionState($connector, $state);
            }
            throw $callbackFailure;
        }

        $state->leaveSavepointScope();
        TransactionContext::storeTransactionState($connector, $state);
        try {
            self::executeControlStatement($physical, 'RELEASE SAVEPOINT ' . $savepoint);
        } catch (Throwable $exception) {
            if (TransactionContext::transactionState($connector) === $state) {
                $state->markRollbackOnly($exception);
                TransactionContext::storeTransactionState($connector, $state);
            }
            throw $exception;
        }

        return $result;
    }

    /**
     * Adapter bridge. Public transaction method signatures remain unchanged;
     * adapters pass only their original physical operation into this state machine.
     */
    public static function beginQuery(
        QueryInterface $query,
        callable $physicalBegin,
        bool $writeIntent = false,
    ): void
    {
        $connector = self::tryConnectorForQuery($query);
        if ($connector === null) {
            $physicalBegin();
            return;
        }
        $state = TransactionContext::transactionState($connector);
        if ($state !== null) {
            self::assertOwnerAndPdo($state, $query, $connector);
            if ($writeIntent && !$state->isWriteIntent()) {
                throw new TransactionStateException('transaction_write_intent_upgrade_unsupported');
            }
            $state->incrementDepth();
            TransactionContext::storeTransactionState($connector, $state);
            return;
        }

        $physical = $connector->getWrappedConnection();
        if ($physical->inTransaction()) {
            throw new TransactionStateException(__('检测到未受事务协调器管理的活动 PDO 事务'));
        }

        TransactionContext::enter($connector, $query);
        try {
            $physicalBegin();
            if (!$physical->inTransaction()) {
                throw new TransactionStateException(__('物理事务开启失败，PDO 未进入事务状态'));
            }
            TransactionContext::storeTransactionState(
                $connector,
                new TransactionState($query, spl_object_id($physical->getPdo()), $writeIntent)
            );
        } catch (Throwable $exception) {
            try {
                if ($physical->inTransaction()) {
                    $physical->rollBack();
                }
            } catch (Throwable $rollbackFailure) {
                self::logFailure('begin_cleanup', $rollbackFailure, [
                    'original_exception' => $exception::class,
                ]);
            }
            TransactionContext::leave($connector, $query);
            throw $exception;
        }
    }

    public static function commitQuery(
        QueryInterface $query,
        callable $physicalCommit,
        callable $physicalRollback
    ): void {
        $connector = self::tryConnectorForQuery($query);
        if ($connector === null) {
            $physicalCommit();
            return;
        }
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            $physicalCommit();
            return;
        }

        self::assertOwnerAndPdo($state, $query, $connector);
        self::assertOutsideSavepointBoundary($state, $connector, 'commit');
        if ($state->depth() > 1) {
            $state->decrementDepth();
            TransactionContext::storeTransactionState($connector, $state);
            return;
        }

        if ($state->isRollbackOnly()) {
            $rollbackCause = $state->rollbackCause();
            $rollbackFailure = null;
            $disconnectFailure = null;
            try {
                $physicalRollback();
            } catch (Throwable $exception) {
                $rollbackFailure = $exception;
                if (ConnectionPool::isDisconnectException($exception)) {
                    $disconnectFailure = $exception;
                }
            }
            $callbacks = $state->takeAfterRollbackCallbacks();
            self::detach($connector, $query);
            if ($disconnectFailure !== null) {
                self::discardDisconnectedConnector($connector, $disconnectFailure);
            }
            self::invokeCallbacks($callbacks, 'after_rollback');
            if ($rollbackFailure !== null) {
                self::logFailure('rollback_only_physical_rollback', $rollbackFailure);
            }
            throw new RollbackOnlyException($rollbackCause ?? $rollbackFailure);
        }

        try {
            if (!$connector->getWrappedConnection()->inTransaction()) {
                throw new TransactionStateException(__('协调器状态为活动，但 PDO 事务已丢失'));
            }
            $physicalCommit();
            if ($connector->getWrappedConnection()->inTransaction()) {
                throw new TransactionStateException(__('物理提交完成后 PDO 仍处于事务状态'));
            }
        } catch (Throwable $commitFailure) {
            $disconnectFailure = ConnectionPool::isDisconnectException($commitFailure)
                ? $commitFailure
                : null;
            try {
                $physicalRollback();
            } catch (Throwable $rollbackFailure) {
                if ($disconnectFailure === null && ConnectionPool::isDisconnectException($rollbackFailure)) {
                    $disconnectFailure = $rollbackFailure;
                }
                self::logFailure('commit_failure_rollback', $rollbackFailure, [
                    'commit_exception' => $commitFailure::class,
                ]);
            }
            $callbacks = $state->takeAfterRollbackCallbacks();
            self::detach($connector, $query);
            if ($disconnectFailure !== null) {
                self::discardDisconnectedConnector($connector, $disconnectFailure);
            }
            self::invokeCallbacks($callbacks, 'after_rollback');
            throw $commitFailure;
        }

        $callbacks = $state->takeAfterCommitCallbacks();
        self::detach($connector, $query);
        self::invokeCallbacks($callbacks, 'after_commit');
    }

    public static function rollBackQuery(QueryInterface $query, callable $physicalRollback): void
    {
        $connector = self::tryConnectorForQuery($query);
        if ($connector === null) {
            $physicalRollback();
            return;
        }
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            $physicalRollback();
            return;
        }

        self::assertOwnerAndPdo($state, $query, $connector);
        self::assertOutsideSavepointBoundary($state, $connector, 'rollback');
        if ($state->depth() > 1) {
            $state->decrementDepth();
            $state->markRollbackOnly();
            TransactionContext::storeTransactionState($connector, $state);
            return;
        }

        $rollbackFailure = null;
        try {
            $physicalRollback();
        } catch (Throwable $exception) {
            $rollbackFailure = $exception;
        }
        $callbacks = $state->takeAfterRollbackCallbacks();
        self::detach($connector, $query);
        if ($rollbackFailure !== null && ConnectionPool::isDisconnectException($rollbackFailure)) {
            self::discardDisconnectedConnector($connector, $rollbackFailure);
        }
        self::invokeCallbacks($callbacks, 'after_rollback');
        if ($rollbackFailure !== null) {
            throw $rollbackFailure;
        }
    }

    public static function markQueryRollbackOnly(QueryInterface $query, ?Throwable $cause = null): void
    {
        $connector = self::tryConnectorForQuery($query);
        if ($connector === null) {
            return;
        }
        $state = TransactionContext::transactionState($connector);
        if ($state === null) {
            return;
        }
        if ($state->ownerQuery() !== $query) {
            throw new \LogicException(__('同一数据库连接范围内不能并行开启两个独立事务'));
        }
        $state->markRollbackOnly($cause);
        TransactionContext::storeTransactionState($connector, $state);
    }

    /** Force an active request-scoped transaction to a physical rollback. */
    public static function cleanupQuery(QueryInterface $query): void
    {
        $connector = self::tryConnectorForQuery($query);
        if ($connector === null) {
            $query->rollBack();
            return;
        }
        $state = TransactionContext::transactionState($connector);
        if ($state !== null) {
            self::logWarning('leaked_transaction', [
                'depth' => $state->depth(),
                'pdo_object_id' => $state->pdoObjectId(),
                'request_id' => RequestContext::getId(),
            ]);
            $state->forceOutermost();
            TransactionContext::storeTransactionState($connector, $state);
        }
        try {
            $query->rollBack();
        } catch (Throwable $exception) {
            self::logFailure('cleanup_rollback', $exception);
            throw $exception;
        }
    }

    private static function connectorForQuery(QueryInterface $query): ConnectorInterface
    {
        $connector = self::tryConnectorForQuery($query);
        if ($connector === null) {
            throw new TransactionStateException(__('查询对象无法提供有效的数据库连接器'));
        }
        return $connector;
    }

    private static function tryConnectorForQuery(QueryInterface $query): ?ConnectorInterface
    {
        if ($query instanceof ConnectorInterface) {
            return $query;
        }
        if (!method_exists($query, 'getConnector')) {
            return null;
        }

        try {
            $connector = $query->getConnector();
        } catch (Throwable) {
            return null;
        }
        return $connector instanceof ConnectorInterface ? $connector : null;
    }

    private static function assertOwnerAndPdo(
        TransactionState $state,
        QueryInterface $query,
        ConnectorInterface $connector
    ): void {
        if ($state->ownerQuery() !== $query) {
            throw new \LogicException(__('同一数据库连接范围内不能并行开启两个独立事务'));
        }

        self::assertStatePdo($state, $connector);
    }

    private static function assertStatePdo(
        TransactionState $state,
        ConnectorInterface $connector
    ): void {
        $pdoObjectId = spl_object_id($connector->getWrappedConnection()->getPdo());
        if ($pdoObjectId !== $state->pdoObjectId()) {
            $exception = new UnsupportedAsyncTransactionConnectionException(
                __('活动事务检测到同配置的第二个 PDO，事务已标记为仅回滚')
            );
            $state->markRollbackOnly($exception);
            TransactionContext::storeTransactionState($connector, $state);
            throw $exception;
        }
    }

    private static function assertNoUnmanagedTransaction(ConnectorInterface $connector): void
    {
        if ($connector->getWrappedConnection()->inTransaction()) {
            throw new TransactionStateException(__('检测到未受事务协调器管理的活动 PDO 事务'));
        }
    }

    private static function assertOutsideSavepointBoundary(
        TransactionState $state,
        ConnectorInterface $connector,
        string $operation
    ): void {
        if (!$state->isAtSavepointBoundary()) {
            return;
        }

        $exception = new TransactionStateException(
            __('保存点回调不能直接%{1}外层事务', [$operation])
        );
        $state->markRollbackOnly($exception);
        TransactionContext::storeTransactionState($connector, $state);
        throw $exception;
    }

    private static function executeControlStatement(ConnectionInterface $connection, string $sql): void
    {
        $statement = $connection->prepare($sql);
        if (!$statement->execute()) {
            throw new TransactionStateException(__('事务控制语句执行失败'));
        }
    }

    private static function detach(ConnectorInterface $connector, QueryInterface $query): void
    {
        TransactionContext::removeTransactionState($connector);
        TransactionContext::leave($connector, $query);
    }

    /** @param array<string, callable> $callbacks */
    private static function invokeCallbacks(array $callbacks, string $phase): void
    {
        foreach ($callbacks as $key => $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                unset($key);
                if ($phase === 'after_commit' && function_exists('w_log_error')) {
                    \w_log_error(
                        'transaction_after_commit_callback_failed',
                        ['error_code' => self::throwableErrorCode($exception)],
                        'database_transaction',
                    );
                    continue;
                }
                self::logFailure($phase, $exception);
            }
        }
    }

    private static function discardDisconnectedConnector(
        ConnectorInterface $connector,
        Throwable $disconnectFailure,
    ): void {
        try {
            $pdo = $connector->getWrappedConnection()->getPdo();
            ConnectionPool::markConnectionUnhealthy($pdo);
            ConnectionPool::discardConnection($pdo, $connector->getConfigProvider());
        } catch (Throwable $discardFailure) {
            self::logFailure('disconnect_discard', $discardFailure, [
                'disconnect_exception' => $disconnectFailure::class,
            ]);
        } finally {
            try {
                $connector->close();
            } catch (Throwable $closeFailure) {
                self::logFailure('disconnect_close', $closeFailure, [
                    'disconnect_exception' => $disconnectFailure::class,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $context */
    private static function logFailure(string $operation, Throwable $exception, array $context = []): void
    {
        if (!function_exists('w_log_error')) {
            return;
        }
        unset($context);
        \w_log_error(
            '[TransactionCoordinator] transaction_control_failed',
            ['error_code' => substr(preg_replace('/[^a-z0-9_]+/', '_', strtolower($operation)), 0, 64)],
            'database_transaction'
        );
    }

    /** @param array<string, mixed> $context */
    private static function logWarning(string $operation, array $context = []): void
    {
        if (!function_exists('w_log_warning')) {
            return;
        }
        unset($context);
        \w_log_warning(
            '[TransactionCoordinator] transaction_state_warning',
            ['error_code' => substr(preg_replace('/[^a-z0-9_]+/', '_', strtolower($operation)), 0, 64)],
            'database_transaction'
        );
    }

    private static function throwableErrorCode(Throwable $exception): string
    {
        $shortName = (new \ReflectionClass($exception))->getShortName();
        $normalized = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
        $normalized = (string)preg_replace('/[^a-z0-9_]+/', '_', $normalized);
        return substr(trim($normalized, '_'), 0, 64) ?: 'callback_failed';
    }
}
