<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Decides whether a task's continued execution is owned by browser leases or
 * by a trusted server-side scheduler.
 *
 * System-owned work is intentionally an explicit opt-in. It cannot be
 * created through the browser task-start contract and it is never stopped
 * merely because no EventSource or client lease exists.
 */
enum TaskLivenessMode: string
{
    case CLIENT_LEASE = 'client_lease';
    case SYSTEM = 'system';

    public function requiresClientLease(): bool
    {
        return $this === self::CLIENT_LEASE;
    }
}
