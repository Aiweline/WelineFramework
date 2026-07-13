<?php

declare(strict_types=1);

namespace Weline\Server\Runtime\Resumable;

use DateTimeImmutable;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessIdentity;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessProbe;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerCommand;

/**
 * CAS-backed persistence and launch bridge required by RuntimeTaskWatchdog.
 *
 * Implementations must use the persisted task fencing generation in every
 * write. claimRecovery() is the only method permitted to advance it and must
 * return null when another watchdog/runner already owns the transition. A
 * failed launch must revoke its token before exposing runnerLeaseReleased.
 */
interface RuntimeWatchdogGatewayInterface
{
    /** @return iterable<RuntimeWatchdogSubject> */
    public function dueSubjects(DateTimeImmutable $now, int $limit): iterable;

    public function recordProcessProbe(RuntimeWatchdogSubject $subject, RuntimeProcessProbe $probe, DateTimeImmutable $now): void;

    /** Explicit cancel or client-lease expiry: never recover this task. */
    public function requestCooperativeStop(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void;

    /** Runner heartbeat loss: stop at a checkpoint, then recover if allowed. */
    public function requestRecoveryStop(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void;

    /**
     * The old process is definitely gone and this was an explicit/lease stop.
     * Implementations transition cancel_requested to cancelled or expired once.
     */
    public function finalizeStop(RuntimeWatchdogSubject $subject, RuntimeProcessProbe $probe, DateTimeImmutable $now): void;

    /**
     * Atomically claims a new fencing generation and returns its command.
     * The command must contain a fresh launch id and no business input.
     */
    public function claimRecovery(RuntimeWatchdogSubject $subject, DateTimeImmutable $now): ?RuntimeRunnerCommand;

    public function recordRunnerLaunched(
        RuntimeWatchdogSubject $subject,
        RuntimeProcessIdentity $process,
        DateTimeImmutable $now,
    ): void;

    public function recordRunnerLaunchFailure(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void;

    public function recordForceTermination(RuntimeWatchdogSubject $subject, RuntimeProcessProbe $probe, DateTimeImmutable $now): void;

    /** Unknown identity is a fail-closed state; it must be surfaced, not retried blindly. */
    public function recordRecoveryBlocked(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void;
}
