<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Transaction;

use Weline\Framework\Database\ConnectionFactory;

/** Additive transaction capability; the base coordinator contract remains compatible. */
interface WriteIntentTransactionCoordinatorInterface extends TransactionCoordinatorInterface
{
    public function runWrite(ConnectionFactory $connection, callable $callback): mixed;

    public function isWriteIntent(ConnectionFactory $connection): bool;
}
