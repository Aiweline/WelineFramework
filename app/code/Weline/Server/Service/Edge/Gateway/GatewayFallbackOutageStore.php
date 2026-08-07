<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Persists the project Agent's current gateway outage observation.
 *
 * This is project-runtime derived state, not host enrollment state. Persisted
 * Monotonic time may be reused across an Agent restart only when the same
 * boot/Master/route observation was refreshed within three heartbeat periods.
 * A missing refresh is an unknown interval and starts a new complete window.
 */
final class GatewayFallbackOutageStore
{
    private const LOCK_WAIT_SECONDS = 0.25;
    private const SCHEMA_VERSION = 4;
    private const MAX_OBSERVATION_GAP_SECONDS = 30.0;

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory
            ?? BP . 'var' . DIRECTORY_SEPARATOR . 'server'
                . DIRECTORY_SEPARATOR . 'gateway-v2'
                . DIRECTORY_SEPARATOR . 'agent-outages';
    }

    /**
     * @return array{down_since_monotonic:float,elapsed_seconds:float,restored:bool}
     */
    public function markDown(
        string $instanceName,
        int $masterPid,
        int $masterEpoch,
        string $launchId,
        string $outageId,
        string $observationDigest,
        float $monotonicNow,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->assertInstanceName($instanceName);
        $launchId = \strtolower(\trim($launchId));
        $outageId = \strtolower(\trim($outageId));
        $observationDigest = \strtolower(\trim($observationDigest));
        if ($masterPid < 1
            || $masterEpoch < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $outageId) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $observationDigest) !== 1
            || !\is_finite($monotonicNow)
            || $monotonicNow < 0.0
        ) {
            throw new \InvalidArgumentException('Gateway Agent outage identity or time is invalid.');
        }
        $hostBootId = GatewayHostBootIdentity::current();
        return $this->withLock($instanceName, function () use (
            $instanceName,
            $masterPid,
            $masterEpoch,
            $launchId,
            $outageId,
            $observationDigest,
            $monotonicNow,
            $hostBootId,
            $deadlineMonotonic,
        ): array {
            $this->lockWaitTimeout($deadlineMonotonic);
            $state = $this->read($instanceName);
            $sameObservation = $state !== null
                && \hash_equals($instanceName, (string)($state['instance'] ?? ''))
                && (int)($state['master_pid'] ?? 0) === $masterPid
                && (int)($state['master_epoch'] ?? 0) === $masterEpoch
                && \hash_equals($launchId, (string)($state['launch_id'] ?? ''))
                && \hash_equals($hostBootId, (string)($state['host_boot_id'] ?? ''))
                && \hash_equals(
                    $observationDigest,
                    (string)($state['observation_digest'] ?? ''),
                );
            $sameContinuity = $sameObservation
                && \hash_equals(
                    $outageId,
                    (string)($state['outage_id'] ?? ''),
                );
            $rawPersistedMonotonic = $state['down_since_monotonic'] ?? null;
            $rawLastObserved = $state['last_observed_down_monotonic'] ?? null;
            $restored = false;
            $pausedUnknownGap = false;
            $downSinceMonotonic = $monotonicNow;
            $observationSequence = 1;
            if ($sameObservation
                && (\is_int($rawPersistedMonotonic) || \is_float($rawPersistedMonotonic))
                && (\is_int($rawLastObserved) || \is_float($rawLastObserved))
            ) {
                $persistedMonotonic = (float)$rawPersistedMonotonic;
                $lastObserved = (float)$rawLastObserved;
                $gap = $monotonicNow - $lastObserved;
                if (\is_finite($persistedMonotonic)
                    && \is_finite($lastObserved)
                    && $persistedMonotonic >= 0.0
                    && $lastObserved >= $persistedMonotonic
                    && $lastObserved <= $monotonicNow
                    && $persistedMonotonic <= $monotonicNow
                    && $gap >= 0.0
                    && $gap <= self::MAX_OBSERVATION_GAP_SECONDS
                ) {
                    $priorSequence = (int)($state['observation_sequence'] ?? 0);
                    if ($priorSequence > 0 && $priorSequence < PHP_INT_MAX) {
                        if ($sameContinuity) {
                            // The same Agent continuity id is retained only
                            // while that Agent has maintained its explicit
                            // failure-probe freshness contract.
                            $downSinceMonotonic = $persistedMonotonic;
                        } else {
                            // A restart has no observations for the gap. Keep
                            // only duration confirmed before the previous
                            // Agent disappeared and pause the unknown interval.
                            $confirmedElapsed = \max(
                                0.0,
                                $lastObserved - $persistedMonotonic,
                            );
                            $downSinceMonotonic = \max(
                                0.0,
                                $monotonicNow - $confirmedElapsed,
                            );
                            $pausedUnknownGap = true;
                        }
                        $observationSequence = $priorSequence + 1;
                        $restored = true;
                    }
                }
            }
            $wallNow = \time();
            $downWall = $restored && !$pausedUnknownGap
                ? (int)($state['down_since_timestamp'] ?? $wallNow)
                : \max(
                    1,
                    $wallNow - (int)\floor(
                        $monotonicNow - $downSinceMonotonic,
                    ),
                );
            $this->lockWaitTimeout($deadlineMonotonic);
            $this->publish($instanceName, [
                'schema_version' => self::SCHEMA_VERSION,
                'instance' => $instanceName,
                'master_pid' => $masterPid,
                'master_epoch' => $masterEpoch,
                'launch_id' => $launchId,
                'outage_id' => $outageId,
                'observation_digest' => $observationDigest,
                'observation_sequence' => $observationSequence,
                'host_boot_id' => $hostBootId,
                'down_since' => \gmdate(DATE_ATOM, $downWall),
                'down_since_timestamp' => $downWall,
                'down_since_monotonic' => $downSinceMonotonic,
                'last_observed_down_monotonic' => $monotonicNow,
                'updated_at' => \gmdate(DATE_ATOM, $wallNow),
                'updated_timestamp' => $wallNow,
                'updated_monotonic' => $monotonicNow,
            ]);
            return [
                'down_since_monotonic' => $downSinceMonotonic,
                'elapsed_seconds' => $monotonicNow - $downSinceMonotonic,
                'restored' => $restored,
            ];
        }, $deadlineMonotonic);
    }

    public function clear(
        string $instanceName,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $this->assertInstanceName($instanceName);
        $this->withLock($instanceName, function () use (
            $instanceName,
            $deadlineMonotonic,
        ): void {
            $this->lockWaitTimeout($deadlineMonotonic);
            $file = $this->stateFile($instanceName);
            GatewayProjectStateFilesystem::removeRegular(
                $file,
                'gateway Agent outage state',
            );
        }, $deadlineMonotonic);
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    private function withLock(
        string $instanceName,
        callable $callback,
        ?float $deadlineMonotonic = null,
    ): mixed
    {
        $this->assertInstanceName($instanceName);
        $waitTimeout = $this->lockWaitTimeout($deadlineMonotonic);
        $this->ensureDirectory($this->directory);
        $status = @\lstat($this->directory);
        if (!\is_array($status)
            || \is_link($this->directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Gateway Agent outage state directory is unsafe.');
        }
        if (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($this->directory, 0700)) {
            throw new \RuntimeException('Unable to secure gateway Agent outage state directory.');
        }
        $lockPath = $this->directory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $instanceName), 0, 24) . '.lock';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPath,
            function () use ($callback, $instanceName, $deadlineMonotonic): mixed {
                $this->lockWaitTimeout($deadlineMonotonic);
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $this->stateFile($instanceName),
                    16_384,
                    'gateway Agent outage state',
                    function (string $encoded) use ($instanceName): void {
                        $state = \json_decode($encoded, true);
                        if (!\is_array($state)) {
                            throw new \RuntimeException(
                                'Gateway Agent outage recovery target is malformed.'
                            );
                        }
                        $this->assertReadableState($state, $instanceName);
                    },
                );
                return $callback();
            },
            waitTimeoutSeconds: $waitTimeout,
        );
    }

    private function lockWaitTimeout(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return self::LOCK_WAIT_SECONDS;
        }
        if (!\is_finite($deadlineMonotonic) || $deadlineMonotonic < 0.0) {
            throw new \RuntimeException('Gateway Agent outage deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('Gateway Agent outage deadline was exhausted.');
        }
        return \min(self::LOCK_WAIT_SECONDS, $remaining);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read(string $instanceName): ?array
    {
        $file = $this->stateFile($instanceName);
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $file,
            16_384,
            'gateway Agent outage state',
        );
        if ($encoded === null) {
            return null;
        }
        $state = \json_decode($encoded, true);
        if (!\is_array($state)) {
            throw new \RuntimeException('Gateway Agent outage state is malformed.');
        }
        // Older schemas had no Agent-local outage identity or exact host boot
        // fence. They can never shorten the current monotonic window.
        if ((int)($state['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            $this->assertReadableState($state, $instanceName);
            return null;
        }
        $this->assertCurrentState($state, $instanceName);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function assertReadableState(array $state, string $instanceName): void
    {
        $schema = $state['schema_version'] ?? null;
        if ($schema === self::SCHEMA_VERSION) {
            $this->assertCurrentState($state, $instanceName);
            return;
        }
        if ($schema !== 3) {
            throw new \RuntimeException('Gateway Agent outage state is malformed.');
        }
        $expected = [
            'schema_version',
            'instance',
            'master_pid',
            'master_epoch',
            'launch_id',
            'outage_id',
            'host_boot_id',
            'down_since',
            'down_since_timestamp',
            'down_since_monotonic',
            'updated_at',
            'updated_timestamp',
            'updated_monotonic',
        ];
        $actual = \array_keys($state);
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        if ($actual !== $expected
            || !\hash_equals($instanceName, (string)($state['instance'] ?? ''))
            || !\is_int($state['master_pid'] ?? null)
            || (int)$state['master_pid'] < 1
            || !\is_int($state['master_epoch'] ?? null)
            || (int)$state['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($state['launch_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($state['outage_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($state['host_boot_id'] ?? '')) !== 1
            || !$this->validPersistedMonotonic($state['down_since_monotonic'] ?? null)
            || !$this->validPersistedMonotonic($state['updated_monotonic'] ?? null)
        ) {
            throw new \RuntimeException('Gateway Agent outage state is malformed.');
        }
    }

    /** @param array<string,mixed> $state */
    private function assertCurrentState(array $state, string $instanceName): void
    {
        $expected = [
            'schema_version',
            'instance',
            'master_pid',
            'master_epoch',
            'launch_id',
            'outage_id',
            'observation_digest',
            'observation_sequence',
            'host_boot_id',
            'down_since',
            'down_since_timestamp',
            'down_since_monotonic',
            'last_observed_down_monotonic',
            'updated_at',
            'updated_timestamp',
            'updated_monotonic',
        ];
        $actual = \array_keys($state);
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        if (
            $actual !== $expected
            || ($state['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !\hash_equals($instanceName, (string)($state['instance'] ?? ''))
            || !\is_int($state['master_pid'] ?? null)
            || (int)$state['master_pid'] < 1
            || !\is_int($state['master_epoch'] ?? null)
            || (int)$state['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $state['launch_id'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $state['outage_id'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $state['observation_digest'] ?? ''
            )) !== 1
            || !\is_int($state['observation_sequence'] ?? null)
            || (int)$state['observation_sequence'] < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $state['host_boot_id'] ?? ''
            )) !== 1
            || !\is_int($state['down_since_timestamp'] ?? null)
            || (int)$state['down_since_timestamp'] < 1
            || !\is_int($state['updated_timestamp'] ?? null)
            || (int)$state['updated_timestamp'] < (int)$state['down_since_timestamp']
            || !$this->validPersistedMonotonic($state['down_since_monotonic'] ?? null)
            || !$this->validPersistedMonotonic($state['updated_monotonic'] ?? null)
            || !$this->validPersistedMonotonic(
                $state['last_observed_down_monotonic'] ?? null,
            )
            || (float)$state['updated_monotonic'] < (float)$state['down_since_monotonic']
            || (float)$state['last_observed_down_monotonic']
                < (float)$state['down_since_monotonic']
            || !\is_string($state['down_since'] ?? null)
            || \strlen((string)$state['down_since']) > 128
            || \strtotime((string)$state['down_since']) === false
            || !\is_string($state['updated_at'] ?? null)
            || \strlen((string)$state['updated_at']) > 128
            || \strtotime((string)$state['updated_at']) === false
        ) {
            throw new \RuntimeException('Gateway Agent outage state is malformed.');
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private function publish(string $instanceName, array $state): void
    {
        $file = $this->stateFile($instanceName);
        $encoded = \json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode gateway Agent outage state.');
        }
        GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600);
    }

    private function stateFile(string $instanceName): string
    {
        return $this->directory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $instanceName), 0, 24) . '.json';
    }

    private function assertInstanceName(string $instanceName): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,511}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('Gateway Agent outage instance name is invalid.');
        }
    }

    private function validPersistedMonotonic(mixed $value): bool
    {
        return (\is_int($value) || \is_float($value))
                && \is_finite((float)$value)
                && (float)$value >= 0.0;
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === ''
            || \str_contains($directory, "\0")
            || (!$this->isAbsolutePath($directory))
        ) {
            throw new \RuntimeException('Gateway Agent outage state directory path is invalid.');
        }
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException('Gateway Agent outage state directory is unsafe.');
            }
            $parent = \dirname($directory);
            if ($parent === $directory) {
                throw new \RuntimeException('Gateway Agent outage state directory has no safe parent.');
            }
            $this->ensureDirectory($parent);
            if (!@\mkdir($directory, 0700)) {
                throw new \RuntimeException('Unable to create gateway Agent outage state directory.');
            }
            $status = @\lstat($directory);
        }
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Gateway Agent outage state directory is unsafe.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || \str_starts_with($path, '\\\\');
    }
}
