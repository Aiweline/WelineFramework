<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Model\Event\Outbox;

final class OutboxRelayScheduler
{
    public function __construct(
        private readonly Outbox $outboxModel,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly OutboxRelay $relay,
    ) {
    }

    public function afterCommit(int $outboxId): void
    {
        if ($outboxId < 1) {
            return;
        }
        $this->transactions->afterCommit(
            $this->outboxModel->getConnection(),
            'event_outbox_relay_' . $outboxId,
            function () use ($outboxId): void {
                $this->relay->relayId($outboxId);
            },
        );
    }
}
