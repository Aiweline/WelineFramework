<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Transaction;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Cold-bootstrap bridge used before generated/framework/modules.php exists.
 * Runtime authority remains the Framework etc/module.php provider mapping.
 */
final class TransactionCoordinatorInterfaceFactory implements FactoryObjectInterface
{
    public function create(): TransactionCoordinatorInterface
    {
        $coordinator = ObjectManager::getInstance(TransactionCoordinator::class);
        if (!$coordinator instanceof TransactionCoordinatorInterface) {
            throw new \RuntimeException('Transaction coordinator violates its contract.');
        }
        return $coordinator;
    }
}
