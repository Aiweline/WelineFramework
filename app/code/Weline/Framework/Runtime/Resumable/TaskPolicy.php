<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Policy values are persisted with the task so restart behavior remains
 * deterministic even if global defaults later change.
 */
final readonly class TaskPolicy
{
    public const DEFAULT_LEASE_TTL_SECONDS = 600;
    public const DEFAULT_LEASE_RENEWAL_SECONDS = 30;
    public const DEFAULT_MAX_RECOVERIES = 3;
    public const DEFAULT_CHECKPOINT_MAX_INTERVAL_SECONDS = 15;
    public const DEFAULT_RUNNER_HEARTBEAT_SECONDS = 5;
    public const DEFAULT_RUNNER_LEASE_SECONDS = 15;
    public const DEFAULT_STOP_GRACE_SECONDS = 30;
    public const DEFAULT_FORCE_KILL_GRACE_SECONDS = 5;
    public const DEFAULT_TERMINAL_RETENTION_SECONDS = 86_400;
    public const DEFAULT_MAX_EVENTS = 50_000;
    public const DEFAULT_MAX_EVENT_BACKLOG_BYTES = 52_428_800;
    public const DEFAULT_EVENT_COALESCE_INTERVAL_MILLISECONDS = 250;
    public const DEFAULT_EVENT_COALESCE_MAX_BYTES = 32_768;

    public function __construct(
        public int $leaseTtlSeconds = self::DEFAULT_LEASE_TTL_SECONDS,
        public int $leaseRenewalSeconds = self::DEFAULT_LEASE_RENEWAL_SECONDS,
        public int $maxRecoveries = self::DEFAULT_MAX_RECOVERIES,
        public int $checkpointMaxIntervalSeconds = self::DEFAULT_CHECKPOINT_MAX_INTERVAL_SECONDS,
        public int $runnerHeartbeatSeconds = self::DEFAULT_RUNNER_HEARTBEAT_SECONDS,
        public int $runnerLeaseSeconds = self::DEFAULT_RUNNER_LEASE_SECONDS,
        public int $cooperativeStopGraceSeconds = self::DEFAULT_STOP_GRACE_SECONDS,
        public int $forceKillGraceSeconds = self::DEFAULT_FORCE_KILL_GRACE_SECONDS,
        public int $terminalRetentionSeconds = self::DEFAULT_TERMINAL_RETENTION_SECONDS,
        public int $maxEvents = self::DEFAULT_MAX_EVENTS,
        public int $maxEventBacklogBytes = self::DEFAULT_MAX_EVENT_BACKLOG_BYTES,
        public int $eventCoalesceIntervalMilliseconds = self::DEFAULT_EVENT_COALESCE_INTERVAL_MILLISECONDS,
        public int $eventCoalesceMaxBytes = self::DEFAULT_EVENT_COALESCE_MAX_BYTES,
        public bool $recoveryEnabled = true,
    ) {
        foreach ([
            'lease ttl' => $this->leaseTtlSeconds,
            'lease renewal' => $this->leaseRenewalSeconds,
            'checkpoint interval' => $this->checkpointMaxIntervalSeconds,
            'runner heartbeat' => $this->runnerHeartbeatSeconds,
            'runner lease' => $this->runnerLeaseSeconds,
            'cooperative stop grace' => $this->cooperativeStopGraceSeconds,
            'force kill grace' => $this->forceKillGraceSeconds,
            'terminal retention' => $this->terminalRetentionSeconds,
            'max events' => $this->maxEvents,
            'max event backlog bytes' => $this->maxEventBacklogBytes,
            'event coalesce interval' => $this->eventCoalesceIntervalMilliseconds,
            'event coalesce max bytes' => $this->eventCoalesceMaxBytes,
        ] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException("Task policy {$name} must be positive.");
            }
        }
        if ($this->maxRecoveries < 0) {
            throw new \InvalidArgumentException('Task policy max recoveries cannot be negative.');
        }
        if ($this->leaseRenewalSeconds > $this->leaseTtlSeconds) {
            throw new \InvalidArgumentException('Task policy lease renewal cannot exceed lease TTL.');
        }
        if ($this->runnerHeartbeatSeconds > $this->runnerLeaseSeconds) {
            throw new \InvalidArgumentException('Task policy runner heartbeat cannot exceed runner lease.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'lease_ttl_seconds' => $this->leaseTtlSeconds,
            'lease_renewal_seconds' => $this->leaseRenewalSeconds,
            'max_recoveries' => $this->maxRecoveries,
            'checkpoint_max_interval_seconds' => $this->checkpointMaxIntervalSeconds,
            'runner_heartbeat_seconds' => $this->runnerHeartbeatSeconds,
            'runner_lease_seconds' => $this->runnerLeaseSeconds,
            'cooperative_stop_grace_seconds' => $this->cooperativeStopGraceSeconds,
            'force_kill_grace_seconds' => $this->forceKillGraceSeconds,
            'terminal_retention_seconds' => $this->terminalRetentionSeconds,
            'max_events' => $this->maxEvents,
            'max_event_backlog_bytes' => $this->maxEventBacklogBytes,
            'event_coalesce_interval_milliseconds' => $this->eventCoalesceIntervalMilliseconds,
            'event_coalesce_max_bytes' => $this->eventCoalesceMaxBytes,
            'recovery_enabled' => $this->recoveryEnabled,
        ];
    }
}
