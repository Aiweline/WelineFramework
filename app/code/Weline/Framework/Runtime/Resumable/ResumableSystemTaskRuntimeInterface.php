<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Trusted server-only start boundary for liveness that is not owned by a
 * browser lease. It deliberately has no lease, replay, or browser owner API.
 */
interface ResumableSystemTaskRuntimeInterface
{
    /**
     * @param array<string|int,mixed> $input
     */
    public function startSystem(
        string $typeCode,
        array $input,
        TaskOwner $owner,
        TaskPolicy $policy,
        string $businessKey,
    ): TaskSnapshot;
}
