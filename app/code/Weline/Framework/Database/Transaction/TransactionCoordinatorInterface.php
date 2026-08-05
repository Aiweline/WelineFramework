<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Transaction;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;

interface TransactionCoordinatorInterface
{
    public function run(ConnectionFactory $connection, callable $callback): mixed;

    public function isActive(ConnectionFactory $connection): bool;

    public function markRollbackOnly(ConnectionFactory $connection, ?Throwable $cause = null): void;

    public function afterCommit(ConnectionFactory $connection, string $key, callable $callback): void;

    public function afterRollback(ConnectionFactory $connection, string $key, callable $callback): void;

    public function withSavepoint(ConnectionFactory $connection, string $purpose, callable $callback): mixed;
}
