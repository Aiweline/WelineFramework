<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Service;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\TransactionContext;

/**
 * 统一业务 DML runner：包装 TransactionContext::enter/leave 与同一 query 的 begin/commit/rollback。
 * 不调用不存在的 TransactionContext::run()。
 */
final class DatabaseTransactionRunner implements DatabaseTransactionRunnerInterface
{
    public function __construct(
        private readonly TransactionCoordinator $transactions,
    ) {
    }

    public function run(ConnectionFactory $connection, callable $callback): mixed
    {
        // Prefer coordinator when transactionState API is present (current runtime).
        if (method_exists(TransactionContext::class, 'transactionState')) {
            return $this->transactions->run($connection, $callback);
        }

        $connector = $connection->getConnector();
        $query = $connector->getQuery();
        TransactionContext::enter($connector, $query);
        try {
            $query->beginTransaction();
            $result = $callback();
            $query->commit();
            return $result;
        } catch (Throwable $exception) {
            try {
                $query->rollBack();
            } catch (Throwable) {
            }
            throw $exception;
        } finally {
            TransactionContext::leave($connector, $query);
            $query->clearQuery();
        }
    }
}
