<?php

declare(strict_types=1);

namespace Weline\Queue;

use Weline\Queue\Model\Queue;

interface DeadWorkerRecoverableQueueInterface
{
    public function shouldRecoverDeadWorker(Queue $queue, int $deadPid, string $workerOutput): bool;

    public function deadWorkerRecoveryMessage(Queue $queue, int $deadPid, string $workerOutput): string;
}
