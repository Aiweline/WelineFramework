<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Database;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\TransactionContext;

/**
 * Keep SEO writes transactional across the current and legacy framework
 * transaction runtimes used by downstream site repositories.
 */
final class SeoTransactionRunner
{
    public function __construct(private readonly TransactionCoordinator $transactions)
    {
    }

    public function run(ConnectionFactory $connection, callable $callback): mixed
    {
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
