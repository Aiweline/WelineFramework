<?php

declare(strict_types=1);

namespace Weline\Framework\Event;

use Weline\Framework\Database\DbManagerFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;

final class ObserverExecutionPolicy
{
    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly DbManagerFactory $dbManager,
    ) {
    }

    public function executeIsolated(string $eventName, string $observerKey, callable $callback): bool
    {
        $connection = $this->dbManager->create();
        try {
            if ($this->transactions->isActive($connection)) {
                $this->transactions->withSavepoint(
                    $connection,
                    'observer_' . $observerKey,
                    $callback,
                );
            } else {
                $callback();
            }
            return true;
        } catch (\Throwable $exception) {
            unset($exception, $eventName);
            w_log_warning(
                'event_observer_isolated_failed',
                [
                    'observer_key' => $observerKey,
                    'error_code' => 'observer_execution_failed',
                ],
                'event_dispatch.log',
            );
            return false;
        }
    }

    public function markCriticalFailure(\Throwable $cause): void
    {
        $connection = $this->dbManager->create();
        if ($this->transactions->isActive($connection)) {
            $this->transactions->markRollbackOnly($connection, $cause);
        }
    }
}
