<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Service\Query\FrontendQueryException;

/**
 * Worker state in a cache adapter protected by an exclusive file lock.
 *
 * Used when the pool adapter lacks compareAndSet (e.g. plain file). Under WLS
 * the same pool is usually hijacked to wls_memory (atomic); this path keeps
 * CLI/FPM and non-atomic drivers correct without falling back to store.json.
 */
final class LockedCacheFrontendWorkerStateStore implements FrontendWorkerStateStoreInterface
{
    private const STATE_KEY = 'worker_state.v1';
    private const MAX_STATE_BYTES = 8388608;
    private const DEFAULT_TTL_SECONDS = 86400;
    private const DEFAULT_LOCK_TIMEOUT_MS = 120;

    public function __construct(
        private readonly CacheAdapterInterface $adapter,
        private readonly string $lockFile,
        private readonly string $driverName = 'cache',
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly int $lockTimeoutMs = self::DEFAULT_LOCK_TIMEOUT_MS,
        private readonly bool $sharedTopology = false,
    ) {
        if ($this->driverName === '' || $this->ttlSeconds < 600 || $this->ttlSeconds > 604800) {
            throw new \InvalidArgumentException('Invalid locked Worker cache store configuration.');
        }
        if ($this->lockFile === '' || $this->lockTimeoutMs < 1) {
            throw new \InvalidArgumentException('Invalid Worker cache lock configuration.');
        }
    }

    public function transaction(callable $callback): mixed
    {
        $directory = \dirname($this->lockFile);
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker session cache lock directory is unavailable.',
                503,
            );
        }

        $handle = @\fopen($this->lockFile, 'c+b');
        if ($handle === false) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker session cache lock is unavailable.',
                503,
            );
        }

        $deadline = \hrtime(true) + ($this->lockTimeoutMs * 1_000_000);
        $locked = false;
        try {
            do {
                if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                \usleep(1000);
            } while (\hrtime(true) < $deadline);

            if (!$locked) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker session cache lock timed out.',
                    503,
                );
            }

            if (\method_exists($this->adapter, 'recoverRemoteProbe')) {
                $this->adapter->recoverRemoteProbe();
            }

            if ($this->adapter instanceof CacheAdapterHealthInterface && !$this->adapter->isAvailable()) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session cache is unavailable.',
                    503,
                );
            }

            $existing = $this->adapter->get(self::STATE_KEY);
            if ($existing !== null && !\is_array($existing)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session cache is invalid.',
                    503,
                );
            }

            $store = $existing ?? [];
            $result = $callback($store);
            $this->assertStatePayload($store);
            if (!$this->adapter->set(self::STATE_KEY, $store, $this->ttlSeconds)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session cache write failed.',
                    503,
                );
            }

            return $result;
        } finally {
            if ($locked) {
                @\flock($handle, LOCK_UN);
            }
            @\fclose($handle);
        }
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    public function isShared(): bool
    {
        return $this->sharedTopology;
    }

    /** @param array<string, mixed> $store */
    private function assertStatePayload(array $store): void
    {
        try {
            $encoded = \json_encode(
                $store,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Shared worker session cache is not serializable.',
                503,
                $exception,
            );
        }

        if (\strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new FrontendQueryException(
                'worker_capacity_exhausted',
                'Shared worker session cache exceeds the storage limit.',
                503,
            );
        }
    }
}
