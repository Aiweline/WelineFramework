<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;

/**
 * Persistence adapter owned by the Runner boundary.
 *
 * Every mutating method must compare task id + fencing generation + runner id
 * + launch id. acquire() accepts only a pre-reserved identity and changes
 * starting/recovering to running in the same transaction.
 * Returning false means that this process has lost ownership and must stop
 * without writing another checkpoint, event or terminal result.
 */
interface RuntimeRunnerStoreInterface
{
    public function acquire(RuntimeRunnerInvocation $invocation, DateTimeImmutable $now): ?RuntimeRunnerClaim;

    public function heartbeat(RuntimeRunnerClaim $claim, DateTimeImmutable $now): bool;

    public function isStopRequested(RuntimeRunnerClaim $claim): bool;

    public function finish(RuntimeRunnerClaim $claim, RuntimeRunnerExecutionResult $result, DateTimeImmutable $now): void;
}
