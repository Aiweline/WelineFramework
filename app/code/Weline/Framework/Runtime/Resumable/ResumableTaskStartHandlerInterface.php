<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Opt-in browser/start boundary for a registered task handler.
 *
 * A task may be executable by a Runner without being safe to start from a
 * browser-facing resource. Implement this contract to validate the current
 * owner, remove untrusted fields, choose a deterministic idempotency key, and
 * freeze the task policy before the durable task row is created.
 */
interface ResumableTaskStartHandlerInterface extends ResumableTaskHandlerInterface
{
    /**
     * @param array<string|int,mixed> $input
     *
     * @throws ResumableTaskAccessDeniedException when the owner is not allowed
     *         to start this task. The transport maps that to a uniform 404.
     */
    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest;
}
