<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Service\Query\FrontendQueryException;

/**
 * Shared Worker state backed by an atomic cache adapter (normally Redis).
 *
 * The complete state snapshot is replaced with compare-and-set. This keeps
 * bootstrap consumption plus session creation, and session validation plus
 * nonce consumption, atomic across nodes without moving security assertions
 * out of FrontendWorkerSessionService.
 */
final class AtomicCacheFrontendWorkerStateStore implements FrontendWorkerStateStoreInterface
{
    private const STATE_KEY = 'worker_state.v1';
    private const MAX_CAS_ATTEMPTS = 16;
    private const MAX_STATE_BYTES = 8388608;
    private const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly AtomicCacheAdapterInterface $adapter,
        private readonly string $driverName = 'redis',
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly bool $sharedTopology = true,
    ) {
        if ($this->driverName === '' || $this->ttlSeconds < 600 || $this->ttlSeconds > 604800) {
            throw new \InvalidArgumentException('Invalid shared Worker state store configuration.');
        }
    }

    public function transaction(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existing = $this->adapter->get(self::STATE_KEY);
            if ($this->adapter instanceof CacheAdapterHealthInterface && !$this->adapter->isAvailable()) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session state is unavailable.',
                    503,
                );
            }
            if ($existing !== null && !\is_array($existing)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session state is invalid.',
                    503,
                );
            }

            $store = $existing ?? [];
            $result = $callback($store);
            $this->assertStatePayload($store);

            if ($this->adapter->compareAndSet(
                self::STATE_KEY,
                $existing,
                $store,
                $this->ttlSeconds,
            )) {
                return $result;
            }
            if ($this->adapter instanceof CacheAdapterHealthInterface && !$this->adapter->isAvailable()) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session state is unavailable.',
                    503,
                );
            }

            SchedulerSystem::usleep(\min(10000, 500 * $attempt));
        }

        throw new FrontendQueryException(
            'worker_store_unavailable',
            'Shared worker session state is busy or unavailable.',
            503,
        );
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
                'Shared worker session state is not serializable.',
                503,
                $exception,
            );
        }

        if (\strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new FrontendQueryException(
                'worker_capacity_exhausted',
                'Shared worker session state exceeds the storage limit.',
                503,
            );
        }
    }
}
