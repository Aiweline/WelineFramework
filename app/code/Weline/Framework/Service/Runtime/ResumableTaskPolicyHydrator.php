<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\TaskPolicy;

/** @internal Maps the persisted policy snapshot back to its immutable DTO. */
final class ResumableTaskPolicyHydrator
{
    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): TaskPolicy
    {
        $defaults = TaskPolicy::defaults();
        return new TaskPolicy(
            leaseTtlSeconds: self::positive($data, 'lease_ttl_seconds', $defaults->leaseTtlSeconds),
            leaseRenewalSeconds: self::positive($data, 'lease_renewal_seconds', $defaults->leaseRenewalSeconds),
            maxRecoveries: max(0, (int)($data['max_recoveries'] ?? $defaults->maxRecoveries)),
            checkpointMaxIntervalSeconds: self::positive($data, 'checkpoint_max_interval_seconds', $defaults->checkpointMaxIntervalSeconds),
            runnerHeartbeatSeconds: self::positive($data, 'runner_heartbeat_seconds', $defaults->runnerHeartbeatSeconds),
            runnerLeaseSeconds: self::positive($data, 'runner_lease_seconds', $defaults->runnerLeaseSeconds),
            cooperativeStopGraceSeconds: self::positive($data, 'cooperative_stop_grace_seconds', $defaults->cooperativeStopGraceSeconds),
            forceKillGraceSeconds: self::positive($data, 'force_kill_grace_seconds', $defaults->forceKillGraceSeconds),
            terminalRetentionSeconds: self::positive($data, 'terminal_retention_seconds', $defaults->terminalRetentionSeconds),
            maxEvents: self::positive($data, 'max_events', $defaults->maxEvents),
            maxEventBacklogBytes: self::positive($data, 'max_event_backlog_bytes', $defaults->maxEventBacklogBytes),
            eventCoalesceIntervalMilliseconds: self::positive($data, 'event_coalesce_interval_milliseconds', $defaults->eventCoalesceIntervalMilliseconds),
            eventCoalesceMaxBytes: self::positive($data, 'event_coalesce_max_bytes', $defaults->eventCoalesceMaxBytes),
            recoveryEnabled: array_key_exists('recovery_enabled', $data) ? (bool)$data['recovery_enabled'] : $defaults->recoveryEnabled,
        );
    }

    /** @param array<string,mixed> $data */
    private static function positive(array $data, string $key, int $default): int
    {
        return max(1, (int)($data[$key] ?? $default));
    }
}
