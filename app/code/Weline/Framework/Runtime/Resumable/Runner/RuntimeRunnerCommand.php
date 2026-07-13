<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use InvalidArgumentException;

/**
 * Builds the detached CLI invocation for one task generation.
 *
 * The command intentionally carries only stable identity values. Task input,
 * user/session data, credentials and SSE state remain in durable storage.
 */
final class RuntimeRunnerCommand
{
    public function __construct(
        public readonly string $projectRoot,
        public readonly RuntimeRunnerInvocation $invocation,
    ) {
        if (trim($this->projectRoot) === '') {
            throw new InvalidArgumentException('Runtime Runner project root is required.');
        }
    }

    public function toShellCommand(): string
    {
        $process = $this->invocation->process;
        $bin = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w';

        return escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($bin)
            . ' runtime:task:run'
            . ' --task-id=' . escapeshellarg($process->taskId)
            . ' --generation=' . $process->generation
            . ' --epoch=' . $process->generation
            . ' --runner-id=' . escapeshellarg($this->invocation->runnerId)
            . ' --name=' . escapeshellarg($process->processName)
            . ' --launch-id=' . escapeshellarg($process->launchId);
    }
}
