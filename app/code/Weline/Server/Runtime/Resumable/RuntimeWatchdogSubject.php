<?php

declare(strict_types=1);

namespace Weline\Server\Runtime\Resumable;

use InvalidArgumentException;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessIdentity;

/**
 * Read-only watchdog projection of one durable task row.
 *
 * The ORM adapter computes deadlines and state flags before creating this DTO;
 * the watchdog therefore has no database or HTTP dependency.
 */
final class RuntimeWatchdogSubject
{
    public function __construct(
        public readonly string $taskId,
        public readonly RuntimeProcessIdentity $process,
        public readonly bool $isTerminal,
        public readonly bool $allClientLeasesExpired,
        public readonly bool $runnerHeartbeatExpired,
        public readonly bool $stopRequested,
        public readonly bool $recoveryStopRequested,
        public readonly bool $cooperativeStopDeadlineReached,
        public readonly bool $forceTerminationConfirmationExpired,
        public readonly bool $recoveryEligible,
        /**
         * The persistence adapter invalidated an unstarted/failed launch token.
         * It is safe to recover only after this flag is true; pid=0 by itself
         * is ambiguous because a platform launcher can still be starting it.
         */
        public readonly bool $runnerLeaseReleased = false,
    ) {
        if (trim($this->taskId) === '') {
            throw new InvalidArgumentException('Runtime watchdog subject task id is required.');
        }
        if ($this->taskId !== $this->process->taskId) {
            throw new InvalidArgumentException('Runtime watchdog subject and process task id must match.');
        }
        if ($this->recoveryStopRequested && !$this->stopRequested) {
            throw new InvalidArgumentException('A recovery stop must also be a cooperative stop request.');
        }
        if ($this->runnerLeaseReleased && $this->process->pid > 0) {
            throw new InvalidArgumentException('A released Runner lease cannot retain a live pid.');
        }
    }
}
