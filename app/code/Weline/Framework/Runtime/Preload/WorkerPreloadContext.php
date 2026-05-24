<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

final class WorkerPreloadContext
{
    public const PHASE_BOOTSTRAP = 'bootstrap';
    public const PHASE_DEFERRED = 'deferred';
    public const PHASE_TRAFFIC_WARMUP = 'traffic_warmup';

    public function __construct(
        private string $phase,
        private string $role,
        private int $workerId,
        private string $instanceName,
        private int $pid,
        private string $runtimeMode,
        private array $attributes = []
    ) {
    }

    public static function fromGlobals(string $phase, string $runtimeMode): self
    {
        $role = (string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker');
        $workerId = (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0);
        $instanceName = (string)($_SERVER['WLS_INSTANCE_NAME'] ?? $_ENV['WLS_INSTANCE_NAME'] ?? \getenv('WLS_INSTANCE_NAME') ?: '');

        return new self(
            $phase,
            \strtolower(\trim($role)) ?: 'worker',
            $workerId,
            $instanceName,
            \function_exists('getmypid') ? (int)\getmypid() : 0,
            $runtimeMode
        );
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function workerId(): int
    {
        return $this->workerId;
    }

    public function instanceName(): string
    {
        return $this->instanceName;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function runtimeMode(): string
    {
        return $this->runtimeMode;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
