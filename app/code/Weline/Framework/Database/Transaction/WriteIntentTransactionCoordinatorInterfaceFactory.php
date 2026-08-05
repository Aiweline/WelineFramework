<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Transaction;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

/** Cold-bootstrap bridge for the write-intent transaction contract. */
final class WriteIntentTransactionCoordinatorInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WriteIntentTransactionCoordinatorInterface
    {
        $coordinator = ObjectManager::getInstance(TransactionCoordinator::class);
        if (!$coordinator instanceof WriteIntentTransactionCoordinatorInterface) {
            throw new \RuntimeException('Write-intent transaction coordinator violates its contract.');
        }
        return $coordinator;
    }
}
