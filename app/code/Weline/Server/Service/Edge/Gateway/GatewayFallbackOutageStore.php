<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Persists the project Agent's current gateway outage observation.
 *
 * This is project-runtime derived state, not host enrollment state. Persisted
 * monotonic time may only be reused by the exact Agent-local outage identity.
 * Cross-Agent restoration is deliberately fail-closed: the current protocol
 * has no authenticated data-plane transition identity capable of proving that
 * the gateway did not recover and fail again while the Agent was absent.
 */
final class GatewayFallbackOutageStore
{
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
        float $monotonicNow,
    ): array {
        $this->assertInstanceName($instanceName);
        $launchId = \strtolower(\trim($launchId));
        $outageId = \strtolower(\trim($outageId));
        if ($masterPid < 1
            || $masterEpoch < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $outageId) !== 1
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
            $monotonicNow,
            $hostBootId,
        ): array {
            $state = $this->read($instanceName);
            $sameOutage = $state !== null
                && \hash_equals($instanceName, (string)($state['instance'] ?? ''))
                && (int)($state['master_pid'] ?? 0) === $masterPid
                && (int)($state['master_epoch'] ?? 0) === $masterEpoch
                && \hash_equals($launchId, (string)($state['launch_id'] ?? ''))
                && \hash_equals($outageId, (string)($state['outage_id'] ?? ''))
                && \hash_equals($hostBootId, (string)($state['host_boot_id'] ?? ''));
            $rawPersistedMonotonic = $state['down_since_monotonic'] ?? null;
            if ($sameOutage
                && (\is_int($rawPersistedMonotonic) || \is_float($rawPersistedMonotonic))
            ) {
                $persistedMonotonic = (float)$rawPersistedMonotonic;
                if (\is_finite($persistedMonotonic)
                    && $persistedMonotonic >= 0.0
                    && $persistedMonotonic <= $monotonicNow
                ) {
                    return [
                        'down_since_monotonic' => $persistedMonotonic,
                        'elapsed_seconds' => $monotonicNow - $persistedMonotonic,
                        'restored' => true,
                    ];
                }
            }
            $wallNow = \time();
            $this->publish($instanceName, [
                'schema_version' => 3,
                'instance' => $instanceName,
                'master_pid' => $masterPid,
                'master_epoch' => $masterEpoch,
                'launch_id' => $launchId,
                'outage_id' => $outageId,
                'host_boot_id' => $hostBootId,
                'down_since' => \gmdate(DATE_ATOM, $wallNow),
                'down_since_timestamp' => $wallNow,
                'down_since_monotonic' => $monotonicNow,
                'updated_at' => \gmdate(DATE_ATOM, $wallNow),
                'updated_timestamp' => $wallNow,
                'updated_monotonic' => $monotonicNow,
            ]);
            return [
                'down_since_monotonic' => $monotonicNow,
                'elapsed_seconds' => 0.0,
                'restored' => false,
            ];
        });
    }

    public function clear(string $instanceName): void
    {
        $this->assertInstanceName($instanceName);
        $this->withLock($instanceName, function () use ($instanceName): void {
            $file = $this->stateFile($instanceName);
            GatewayProjectStateFilesystem::removeRegular(
                $file,
                'gateway Agent outage state',
            );
        });
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    private function withLock(string $instanceName, callable $callback): mixed
    {
        $this->assertInstanceName($instanceName);
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
            \Closure::fromCallable($callback),
        );
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
        if ((int)($state['schema_version'] ?? 0) !== 3) {
            return null;
        }
        if (
            !\hash_equals($instanceName, (string)($state['instance'] ?? ''))
            || (int)($state['master_pid'] ?? 0) < 1
            || (int)($state['master_epoch'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $state['launch_id'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $state['outage_id'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $state['host_boot_id'] ?? ''
            )) !== 1
            || !\is_int($state['down_since_timestamp'] ?? null)
            || (int)$state['down_since_timestamp'] < 1
            || !\is_int($state['updated_timestamp'] ?? null)
            || (int)$state['updated_timestamp'] < (int)$state['down_since_timestamp']
            || !$this->validPersistedMonotonic($state['down_since_monotonic'] ?? null)
            || !$this->validPersistedMonotonic($state['updated_monotonic'] ?? null)
            || (float)$state['updated_monotonic'] < (float)$state['down_since_monotonic']
        ) {
            throw new \RuntimeException('Gateway Agent outage state is malformed.');
        }
        return $state;
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
