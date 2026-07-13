<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

/** Starts a task in an isolated CLI Runner rather than an HTTP request Fiber. */
interface ResumableTaskRunnerLauncherInterface
{
    public function launch(string $taskId, bool $recovery = false): void;
}
