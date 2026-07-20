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

    /**
     * Argv form for Processer::createDetachedPhpArgv(). Prefer this over the
     * shell string so the Runner can posix_setsid() out of the parent WLS
     * worker process group and survive request-fiber teardown.
     *
     * @return list<string>
     */
    public function toArgv(): array
    {
        $process = $this->invocation->process;
        $bin = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w';

        return [
            PHP_BINARY,
            $bin,
            'runtime:task:run',
            '--task-id=' . $process->taskId,
            '--generation=' . (string)$process->generation,
            '--epoch=' . (string)$process->generation,
            '--runner-id=' . $this->invocation->runnerId,
            '--name=' . $process->processName,
            '--launch-id=' . $process->launchId,
        ];
    }

    public function toShellCommand(): string
    {
        $argv = $this->toArgv();
        $php = array_shift($argv);
        $parts = [escapeshellarg((string)$php)];
        foreach ($argv as $argument) {
            // Keep flag tokens unquoted so Processer identity extractors and
            // legacy shell launchers still see --name=/--launch-id= prefixes.
            $parts[] = str_starts_with($argument, '--')
                ? $argument
                : escapeshellarg($argument);
        }

        return implode(' ', $parts);
    }
}
