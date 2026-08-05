<?php

declare(strict_types=1);

namespace Weline\Queue;

use Weline\Queue\Model\Queue;

/**
 * Optional extension for recoverable consumers that must update their payload
 * in the same fenced CAS that returns a dead Worker generation to pending.
 */
interface DeadWorkerRecoveryPatchQueueInterface extends DeadWorkerRecoverableQueueInterface
{
    /**
     * Return consumer-owned Queue fields that must be committed atomically with
     * the recovery transition. QueueDispatchService currently accepts content
     * only and rejects every other field fail-closed.
     *
     * @return array<string,mixed>
     */
    public function deadWorkerRecoveryPatch(Queue $queue, int $deadPid, string $workerOutput): array;
}
