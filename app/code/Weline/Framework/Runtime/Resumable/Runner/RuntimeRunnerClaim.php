<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use InvalidArgumentException;

/**
 * Result of atomically claiming a task generation for execution.
 */
final class RuntimeRunnerClaim
{
    public function __construct(
        public readonly string $taskId,
        public readonly int $fencingGeneration,
        public readonly string $runnerId,
        public readonly int $attempt,
    ) {
        if (trim($this->taskId) === '' || $this->fencingGeneration < 1 || trim($this->runnerId) === '' || $this->attempt < 1) {
            throw new InvalidArgumentException('Invalid Runtime Runner claim.');
        }
    }
}
