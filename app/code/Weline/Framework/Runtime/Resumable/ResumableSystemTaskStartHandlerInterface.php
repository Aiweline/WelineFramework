<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Opt-in server-only start boundary for a registered task handler.
 *
 * It is deliberately distinct from ResumableTaskStartHandlerInterface so the
 * runtime task QueryProvider can never expose this task type to a browser.
 */
interface ResumableSystemTaskStartHandlerInterface extends ResumableTaskHandlerInterface
{
    /**
     * @param array<string|int,mixed> $input
     */
    public function prepareSystemStart(array $input): TaskStartRequest;
}
