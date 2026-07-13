<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Public management boundary. Implementations must start an independent
 * runner and must not fall back to execution inside an SSE request.
 */
interface ResumableTaskRuntimeInterface
{
    /**
     * @param array<string|int, mixed> $input
     */
    public function start(
        string $typeCode,
        array $input,
        TaskOwner $owner,
        TaskPolicy $policy,
        string $businessKey,
    ): TaskHandle;

    public function status(string $taskId, TaskOwner $owner): TaskSnapshot;

    public function renew(string $taskId, string $leaseId, TaskOwner $owner): TaskLease;

    public function cancel(
        string $taskId,
        TaskOwner $owner,
        string $intentId,
        string $reason = '',
    ): TaskSnapshot;
}
