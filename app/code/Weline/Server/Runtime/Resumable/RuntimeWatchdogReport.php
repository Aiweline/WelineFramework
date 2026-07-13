<?php

declare(strict_types=1);

namespace Weline\Server\Runtime\Resumable;

/**
 * Per-tick observability result. It is intentionally aggregate-only so task
 * ids are not accidentally emitted to shared WLS logs.
 */
final class RuntimeWatchdogReport
{
    public int $inspected = 0;
    public int $leaseExpiryStopsRequested = 0;
    public int $recoveryStopsRequested = 0;
    public int $forceTerminations = 0;
    public int $recoveriesLaunched = 0;
    public int $recoveriesBlocked = 0;
    public int $launchFailures = 0;
}
