<?php

declare(strict_types=1);

namespace Weline\Queue\Service\AsyncEvent;

use Weline\Framework\Api\Event\AsyncEventDeliveryMaintenanceInterface;

final class AsyncEventQueueReconciler
{
    public function __construct(
        private readonly AsyncEventDeliveryMaintenanceInterface $maintenance,
    ) {
    }

    /** @return array<string,array<string,int>> */
    public function run(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return [
            'relay' => $this->maintenance->relayOutbox($limit),
            'reconcile' => $this->maintenance->reconcileTransport($limit),
            'due' => $this->maintenance->provisionDue($limit),
            'timeout' => $this->maintenance->terminateTimedOut($limit),
        ];
    }

    /** @return array{deliveries:int,outboxes:int} */
    public function collectGarbage(int $limit = 50): array
    {
        return $this->maintenance->collectGarbage(max(1, min(500, $limit)));
    }
}
