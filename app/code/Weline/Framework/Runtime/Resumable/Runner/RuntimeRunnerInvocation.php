<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Weline\Framework\System\Process\Processer;

/**
 * Arguments passed from the CLI command into the independently running task
 * process. It contains no request, session or SSE connection state.
 */
final class RuntimeRunnerInvocation
{
    public function __construct(
        public readonly RuntimeProcessIdentity $process,
        public readonly string $runnerId,
    ) {
        if (trim($this->runnerId) === '') {
            throw new InvalidArgumentException('Runtime Runner id is required.');
        }
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function fromArgs(array $args): self
    {
        $taskId = trim((string) ($args['task-id'] ?? $args['task_id'] ?? ''));
        $generation = (int) ($args['generation'] ?? $args['epoch'] ?? 0);
        $processName = trim((string) ($args['name'] ?? ''));
        $launchId = trim((string) ($args['launch-id'] ?? $args['launch_id'] ?? ''));
        $runnerId = trim((string) ($args['runner-id'] ?? $args['runner_id'] ?? ''));

        if ($processName === '') {
            $processName = RuntimeProcessIdentity::buildProcessName($taskId, $generation);
        }

        return new self(
            new RuntimeProcessIdentity($taskId, $generation, $processName, $launchId),
            $runnerId,
        );
    }

    /**
     * Must be called by the CLI Runner before it acquires its durable fence.
     * The child PID is authoritative even when a platform launcher returned a
     * transient helper PID to its parent.
     */
    public function withCurrentProcessIdentity(): self
    {
        $pid = getmypid() ?: 0;
        if ($pid < 1) {
            throw new RuntimeException('Unable to resolve the current Runtime Runner pid.');
        }

        $liveCommand = trim(Processer::getProcessCommandLine($pid, true));
        if ($liveCommand === '') {
            throw new RuntimeException('Unable to capture the Runtime Runner live process identity.');
        }

        return new self(
            $this->process->withStartedProcess($pid, $liveCommand, new DateTimeImmutable('now')),
            $this->runnerId,
        );
    }
}
