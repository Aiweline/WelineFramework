<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Read-only, lease-bound event replay boundary for an SSE transport.
 *
 * Implementations must only read durable task state.  In particular this
 * interface must never execute a handler, create a runner, or renew a client
 * lease merely because an EventSource connection is open.
 */
interface ResumableTaskEventStreamInterface
{
    /**
     * @throws ResumableTaskAccessDeniedException when task, owner or lease is
     *         absent, expired, or does not match.
     */
    public function replay(
        string $taskId,
        string $leaseId,
        TaskOwner $owner,
        int $afterSequence,
        int $limit = 200,
    ): TaskEventReplay;
}
