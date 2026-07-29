<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\System\Process\Processer;

/**
 * Stable, host-coordinated pure-WLS fallback port allocator.
 */
final class GatewayPortLeaseAllocator
{
    private const MIN_PORT = 20000;
    private const MAX_PORT = 29999;
    private const RESERVATION_TTL = 120;

    private readonly string $leaseDirectory;

    public function __construct(
        private readonly ProjectIdentityStore $projects = new ProjectIdentityStore(),
        ?string $leaseDirectory = null,
    ) {
        $this->leaseDirectory = $leaseDirectory
            ?? $this->projects->hostStateRoot() . DIRECTORY_SEPARATOR . 'fallback-leases';
    }

    public function allocate(string $instanceName): int
    {
        $reservation = $this->reserveBound(
            $instanceName,
            static function (int $port): mixed {
                $socket = @\stream_socket_server(
                    'tcp://127.0.0.1:' . $port,
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                );
                if (!\is_resource($socket)) {
                    return false;
                }
                return $socket;
            },
        );
        return (int)$reservation['port'];
    }

    /**
     * The callback must bind and retain the selected socket before returning
     * true. The host allocation lock remains held until the RESERVED lease is
     * durable, closing the multi-project probe-to-bind gap.
     *
     * A true return value means the callback retained the bound socket. A
     * socket resource is retained by the allocator until the RESERVED lease is
     * durable and then closed (used by pre-start selection).
     *
     * @param callable(int):mixed $binder
     * @return array{project_uuid:string,instance:string,port:int,lease_id:string,state:string}
     */
    public function reserveBound(string $instanceName, callable $binder): array
    {
        return $this->withAllocationLock(function () use ($instanceName, $binder): array {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $current = $this->readLease($file);
            if ($current !== null
                && \in_array((string)($current['state'] ?? ''), ['ACTIVE', 'DRAINING'], true)
                && $this->leaseProcessAlive($current)
            ) {
                throw new \RuntimeException(
                    'The WLS fallback identity already owns a live port lease.'
                );
            }

            $occupied = $this->occupiedLeasePorts($identity);
            $preferred = (int)($current['port'] ?? 0);
            $start = $preferred >= self::MIN_PORT && $preferred <= self::MAX_PORT
                ? $preferred
                : self::MIN_PORT
                    + (\hexdec(\substr(\hash('sha256', $identity), 0, 8))
                        % (self::MAX_PORT - self::MIN_PORT + 1));
            for ($offset = 0; $offset <= self::MAX_PORT - self::MIN_PORT; $offset++) {
                $port = self::MIN_PORT
                    + (($start - self::MIN_PORT + $offset)
                        % (self::MAX_PORT - self::MIN_PORT + 1));
                if (isset($occupied[$port])) {
                    continue;
                }
                try {
                    $bound = $binder($port);
                } catch (\Throwable) {
                    $bound = false;
                }
                $temporaryReservation = \is_resource($bound) ? $bound : null;
                if ($bound !== true && $temporaryReservation === null) {
                    continue;
                }
                $lease = [
                    'schema_version' => 3,
                    'project_uuid' => $projectUuid,
                    'instance' => $instanceName,
                    'port' => $port,
                    'lease_id' => \bin2hex(\random_bytes(16)),
                    'state' => 'RESERVED',
                    'master_pid' => \getmypid(),
                    'worker_pid' => 0,
                    'launch_id' => '',
                    'workers' => [],
                    'reserved_at' => \gmdate(DATE_ATOM),
                    'reserved_timestamp' => \time(),
                    'confirmed_at' => null,
                    'draining_at' => null,
                ];
                try {
                    $this->publishLease($file, $lease);
                } finally {
                    if (\is_resource($temporaryReservation)) {
                        @\fclose($temporaryReservation);
                    }
                }
                return $lease;
            }
            throw new \RuntimeException(
                'No free pure-WLS fallback port is available in 20000-29999.'
            );
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function confirm(
        string $instanceName,
        int $port,
        int $workerPid,
        string $launchId,
    ): array {
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $workerPid,
            $launchId,
        ): array {
            if ($port < self::MIN_PORT || $port > self::MAX_PORT || $workerPid < 1) {
                throw new \RuntimeException('Fallback lease confirmation identity is invalid.');
            }
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            $sameOwner = $lease !== null
                && \hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                && \hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                && (int)($lease['port'] ?? 0) === $port
                && (int)($lease['master_pid'] ?? 0) === \getmypid();
            $workers = $lease !== null ? $this->normaliseWorkers($lease) : [];
            foreach ($workers as $worker) {
                if ((int)($worker['pid'] ?? 0) === $workerPid
                    && \hash_equals((string)($worker['launch_id'] ?? ''), $launchId)
                ) {
                    return $lease;
                }
            }
            $state = (string)($lease['state'] ?? '');
            $mayJoinPool = $sameOwner
                && \in_array($state, ['RESERVED', 'ACTIVE'], true);
            if ($lease === null
                || !$mayJoinPool
            ) {
                throw new \RuntimeException('Fallback READY does not match the reserved host lease.');
            }
            $workers = \array_values(\array_filter(
                $workers,
                fn (array $worker): bool => $this->processAlive((int)($worker['pid'] ?? 0)),
            ));
            $workers[] = [
                'pid' => $workerPid,
                'launch_id' => $launchId,
                'confirmed_at' => \gmdate(DATE_ATOM),
                'confirmed_timestamp' => \time(),
            ];
            $lease['state'] = 'ACTIVE';
            $lease['schema_version'] = 3;
            $lease['worker_pid'] = $workerPid;
            $lease['launch_id'] = $launchId;
            $lease['workers'] = $workers;
            $lease['confirmed_at'] = \gmdate(DATE_ATOM);
            $lease['confirmed_timestamp'] = \time();
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function markDraining(string $instanceName, int $port): array
    {
        return $this->withAllocationLock(function () use ($instanceName, $port): array {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            if ($lease === null
                || (int)($lease['port'] ?? 0) !== $port
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
            ) {
                throw new \RuntimeException('Fallback drain does not match the active host lease.');
            }
            if ((string)($lease['state'] ?? '') !== 'DRAINING') {
                if (!\hash_equals('ACTIVE', (string)($lease['state'] ?? ''))) {
                    throw new \RuntimeException('Only an active fallback lease may begin draining.');
                }
                $lease['state'] = 'DRAINING';
                $lease['draining_at'] = \gmdate(DATE_ATOM);
                $lease['draining_timestamp'] = \time();
                $this->publishLease($file, $lease);
            }
            return $lease;
        });
    }

    public function cancelReservation(string $instanceName, int $port): void
    {
        $this->withAllocationLock(function () use ($instanceName, $port): void {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            if ($lease === null
                || (int)($lease['port'] ?? 0) !== $port
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
            ) {
                return;
            }
            $lease['state'] = 'RELEASED';
            $lease['released_at'] = \gmdate(DATE_ATOM);
            $lease['released_timestamp'] = \time();
            $this->publishLease($file, $lease);
        });
    }

    public function release(string $instanceName, int $port): void
    {
        $this->withAllocationLock(function () use ($instanceName, $port): void {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            if ($lease === null
                || (int)($lease['port'] ?? 0) !== $port
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
            ) {
                return;
            }
            $state = (string)($lease['state'] ?? '');
            if ($state === 'RELEASED') {
                return;
            }
            if (!\in_array($state, ['RESERVED', 'ACTIVE', 'DRAINING'], true)) {
                return;
            }
            $lease['state'] = 'RELEASED';
            $lease['worker_pid'] = 0;
            $lease['launch_id'] = '';
            $lease['workers'] = [];
            $lease['released_at'] = \gmdate(DATE_ATOM);
            $lease['released_timestamp'] = \time();
            $this->publishLease($file, $lease);
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function status(string $instanceName): ?array
    {
        $projectUuid = $this->projects->projectUuid();
        return $this->readLease($this->leaseFile($projectUuid . ':' . $instanceName));
    }

    /**
     * @return array<int,true>
     */
    private function occupiedLeasePorts(string $ownIdentity): array
    {
        $occupied = [];
        foreach ((array)@\glob($this->leaseDirectory . DIRECTORY_SEPARATOR . '*.json') as $file) {
            if (!\is_string($file) || \is_link($file)) {
                continue;
            }
            $lease = $this->readLease($file);
            if ($lease === null || !$this->leaseIsLive($lease)) {
                continue;
            }
            $identity = (string)($lease['project_uuid'] ?? '') . ':'
                . (string)($lease['instance'] ?? '');
            if (\hash_equals($ownIdentity, $identity)
                && \hash_equals('RESERVED', (string)($lease['state'] ?? ''))
                && (int)($lease['master_pid'] ?? 0) === \getmypid()
            ) {
                continue;
            }
            $port = (int)($lease['port'] ?? 0);
            if ($port >= self::MIN_PORT && $port <= self::MAX_PORT) {
                $occupied[$port] = true;
            }
        }
        return $occupied;
    }

    /**
     * @param array<string,mixed> $lease
     */
    private function leaseIsLive(array $lease): bool
    {
        $state = (string)($lease['state'] ?? '');
        if ($state === 'RESERVED') {
            $reservedAt = (int)($lease['reserved_timestamp'] ?? 0);
            return $reservedAt > 0
                && \time() - $reservedAt <= self::RESERVATION_TTL
                && $this->processAlive((int)($lease['master_pid'] ?? 0));
        }
        return \in_array($state, ['ACTIVE', 'DRAINING'], true)
            && $this->leaseProcessAlive($lease);
    }

    /**
     * @param array<string,mixed> $lease
     */
    private function leaseProcessAlive(array $lease): bool
    {
        foreach ($this->normaliseWorkers($lease) as $worker) {
            if ($this->processAlive((int)($worker['pid'] ?? 0))) {
                return true;
            }
        }
        return $this->processAlive((int)($lease['master_pid'] ?? 0))
            && \hash_equals('RESERVED', (string)($lease['state'] ?? ''));
    }

    /**
     * @param array<string,mixed> $lease
     * @return list<array{pid:int,launch_id:string,confirmed_at?:mixed,confirmed_timestamp?:mixed}>
     */
    private function normaliseWorkers(array $lease): array
    {
        $workers = [];
        foreach ((array)($lease['workers'] ?? []) as $worker) {
            if (!\is_array($worker)) {
                continue;
            }
            $pid = (int)($worker['pid'] ?? 0);
            $launchId = \trim((string)($worker['launch_id'] ?? ''));
            if ($pid < 1 || $launchId === '') {
                continue;
            }
            $workers[$pid . ':' . $launchId] = [
                'pid' => $pid,
                'launch_id' => $launchId,
                'confirmed_at' => $worker['confirmed_at'] ?? null,
                'confirmed_timestamp' => $worker['confirmed_timestamp'] ?? null,
            ];
        }
        $legacyPid = (int)($lease['worker_pid'] ?? 0);
        $legacyLaunchId = \trim((string)($lease['launch_id'] ?? ''));
        if ($legacyPid > 0 && $legacyLaunchId !== '') {
            $workers[$legacyPid . ':' . $legacyLaunchId] ??= [
                'pid' => $legacyPid,
                'launch_id' => $legacyLaunchId,
                'confirmed_at' => $lease['confirmed_at'] ?? null,
                'confirmed_timestamp' => $lease['confirmed_timestamp'] ?? null,
            ];
        }
        return \array_values($workers);
    }

    private function processAlive(int $pid): bool
    {
        return $pid > 0 && Processer::isRunningByPid($pid);
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    private function withAllocationLock(callable $callback): mixed
    {
        if (\is_link($this->leaseDirectory)
            || (!\is_dir($this->leaseDirectory)
                && !@\mkdir($this->leaseDirectory, 0700, true)
                && !\is_dir($this->leaseDirectory))
        ) {
            throw new \RuntimeException('Unable to create WLS fallback lease directory.');
        }
        @\chmod($this->leaseDirectory, 0700);
        $lockPath = $this->leaseDirectory . DIRECTORY_SEPARATOR . 'allocation.lock';
        if (\is_link($lockPath)) {
            throw new \RuntimeException('WLS fallback allocation lock must not be a symbolic link.');
        }
        $lock = @\fopen($lockPath, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to acquire WLS fallback port allocation lock.');
        }
        @\chmod($lockPath, 0600);
        try {
            return $callback();
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    private function leaseFile(string $identity): string
    {
        return $this->leaseDirectory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $identity), 0, 24) . '.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readLease(string $file): ?array
    {
        if (!\is_file($file) || \is_link($file)) {
            return null;
        }
        $encoded = @\file_get_contents($file);
        $lease = \is_string($encoded) ? \json_decode($encoded, true) : null;
        return \is_array($lease) ? $lease : null;
    }

    /**
     * @param array<string,mixed> $lease
     */
    private function publishLease(string $file, array $lease): void
    {
        if (\is_link($file)) {
            throw new \RuntimeException('WLS fallback lease target must not be a symbolic link.');
        }
        $encoded = \json_encode(
            $lease,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode WLS fallback port lease.');
        }
        $temporary = $file . '.tmp-' . \bin2hex(\random_bytes(8));
        $stream = @\fopen($temporary, 'x+b');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Unable to stage WLS fallback port lease.');
        }
        try {
            @\chmod($temporary, 0600);
            if (@\fwrite($stream, $encoded) !== \strlen($encoded)
                || !@\fflush($stream)
                || (\function_exists('fsync') && !@\fsync($stream))
            ) {
                throw new \RuntimeException('Unable to persist WLS fallback port lease.');
            }
        } finally {
            @\fclose($stream);
        }
        if (!@\rename($temporary, $file)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish WLS fallback port lease.');
        }
        @\chmod($file, 0600);
    }
}
