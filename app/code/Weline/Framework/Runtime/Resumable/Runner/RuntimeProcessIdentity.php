<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Frozen identity for one independently launched resumable-task Runner.
 *
 * A PID alone must never be trusted: it may have been reused by the OS.  The
 * process name, random launch id, generation and observed live command are
 * kept together so force termination can fail closed.
 */
final class RuntimeProcessIdentity
{
    private const PROCESS_NAME_PREFIX = 'weline-runtime-task-';

    public function __construct(
        public readonly string $taskId,
        public readonly int $generation,
        public readonly string $processName,
        public readonly string $launchId,
        public readonly int $pid = 0,
        public readonly string $liveCommand = '',
        public readonly ?DateTimeImmutable $startedAt = null,
    ) {
        if (trim($this->taskId) === '' || str_contains($this->taskId, "\n") || str_contains($this->taskId, "\r")) {
            throw new InvalidArgumentException('Runtime task id must be a non-empty single-line value.');
        }
        if ($this->generation < 1) {
            throw new InvalidArgumentException('Runtime Runner generation must be positive.');
        }
        if ($this->processName !== self::buildProcessName($this->taskId, $this->generation)) {
            throw new InvalidArgumentException('Runtime Runner process name must match its task and generation.');
        }
        if (trim($this->launchId) === '') {
            throw new InvalidArgumentException('Runtime Runner launch id is required.');
        }
        if ($this->pid < 0) {
            throw new InvalidArgumentException('Runtime Runner pid cannot be negative.');
        }
    }

    public static function forTask(string $taskId, int $generation, ?string $launchId = null): self
    {
        $launchId ??= bin2hex(random_bytes(16));

        return new self(
            taskId: $taskId,
            generation: $generation,
            processName: self::buildProcessName($taskId, $generation),
            launchId: $launchId,
        );
    }

    public static function buildProcessName(string $taskId, int $generation): string
    {
        if ($generation < 1) {
            throw new InvalidArgumentException('Runtime Runner generation must be positive.');
        }

        // Do not put the public task id directly in process listings or logs.
        return self::PROCESS_NAME_PREFIX . substr(hash('sha256', $taskId), 0, 20) . '-g' . $generation;
    }

    public function withStartedProcess(int $pid, string $liveCommand, DateTimeImmutable $startedAt): self
    {
        if ($pid < 1) {
            throw new InvalidArgumentException('A started Runtime Runner must have a positive pid.');
        }

        return new self(
            taskId: $this->taskId,
            generation: $this->generation,
            processName: $this->processName,
            launchId: $this->launchId,
            pid: $pid,
            liveCommand: trim($liveCommand),
            startedAt: $startedAt,
        );
    }

    /**
     * Canonical managed-record identity consumed by Processer identity probes.
     */
    public function managedPname(): string
    {
        return '--name=' . $this->processName
            . ' --launch-id=' . rawurlencode($this->launchId)
            . ' --epoch=' . $this->generation;
    }

    public function canSafelyTerminate(): bool
    {
        return $this->pid > 0 && $this->liveCommand !== '';
    }
}
