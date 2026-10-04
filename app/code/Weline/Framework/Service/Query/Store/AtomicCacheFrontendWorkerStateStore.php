<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\AtomicCacheConfirmationInterface;
use Weline\Framework\Cache\Exception\AtomicWriteOutcomeUnknownException;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\FreshCacheReadInterface;
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
    private const MAX_CAS_ATTEMPTS = 96;
    private const MAX_STATE_BYTES = 8388608;
    private const DEFAULT_TTL_SECONDS = 86400;
    private const PACKED_PREFIX = 'weline-worker-state-gzip.v1:';
    private const COMPRESSION_THRESHOLD_BYTES = 65536;

    public function __construct(
        private readonly AtomicCacheAdapterInterface $adapter,
        private readonly string $driverName = 'redis',
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly bool $sharedTopology = true,
        private readonly bool $compactSnapshots = true,
    ) {
        if ($this->driverName === '' || $this->ttlSeconds < 600 || $this->ttlSeconds > 604800) {
            throw new \InvalidArgumentException('Invalid shared Worker state store configuration.');
        }
    }

    public function transaction(callable $callback): mixed
    {
        $this->recoverRemoteIfPossible();
        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existingWire = $this->adapter instanceof FreshCacheReadInterface
                ? $this->adapter->getFresh(self::STATE_KEY)
                : $this->adapter->get(self::STATE_KEY);
            if ($this->isAdapterUnavailable()) {
                $this->recoverRemoteIfPossible();
                if ($this->isAdapterUnavailable()) {
                    throw new FrontendQueryException(
                        'worker_store_unavailable',
                        'Shared worker session state is unavailable.',
                        503,
                    );
                }
                // Cold Memory reconnect after same-request circuit trip needs
                // more than a few hundred microseconds before the next CAS.
                SchedulerSystem::usleep(\min(80_000, 2_000 * $attempt));
                continue;
            }
            $existing = $this->decodeSnapshot($existingWire);

            $store = $existing ?? [];
            $result = $callback($store);
            $this->assertStatePayload($store);
            if ($this->adapter instanceof FreshCacheReadInterface && $store === ($existing ?? [])
                && (!$this->compactSnapshots || $this->isPacked($existingWire))) {
                return $result;
            }
            $newWire = $this->encodeSnapshot($store, $this->compactSnapshots || $this->isPacked($existingWire));

            // 权威快照可作为只读事务的线性化点；未修改状态不续 TTL 或发送 CAS。
            if ($this->adapter instanceof FreshCacheReadInterface && $store === ($existing ?? [])
                && ($newWire === $existingWire || !\is_string($newWire))) {
                return $result;
            }

            try {
                $committed = $this->adapter->compareAndSet(
                    self::STATE_KEY,
                    $existingWire,
                    $newWire,
                    $this->ttlSeconds,
                );
            } catch (AtomicWriteOutcomeUnknownException $error) {
                $this->logCasFailure('atomic_exception', $error);
                // 重放一次性凭据消费会把本请求可能已提交的写入误判为重放攻击。
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session write outcome is unavailable.',
                    503,
                    $error,
                );
            }
            if ($committed) {
                return $result;
            }
            $replyConfirmed = $this->adapter instanceof AtomicCacheConfirmationInterface
                && $this->adapter->isLastCompareAndSetReplyConfirmed();
            if ($this->adapter instanceof AtomicCacheConfirmationInterface
                && $this->adapter->wasLastCompareAndSetNotDispatched()) {
                $this->recoverRemoteIfPossible();
            } elseif (!$replyConfirmed && $this->isAdapterUnavailable()) {
                $this->logCasFailure('adapter_unavailable_after_false');
                // 兼容适配器也可能把失去原回复压成 false；不得重新消费一次性凭据。
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Shared worker session write outcome is unavailable.',
                    503,
                    new AtomicWriteOutcomeUnknownException(
                        'Shared worker session CAS reply was lost after dispatch.',
                    ),
                );
            }

            // Shared bootstrap writes can briefly contend across workers.
            // Randomized backoff avoids synchronized retries re-colliding.
            SchedulerSystem::usleep(\random_int(1_000, \min(32_000, 2_000 * $attempt)));
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

    private function logCasFailure(string $stage, ?\Throwable $error = null): void
    {
        if (!\function_exists('w_log_error')) {
            return;
        }
        try {
            $requestId = \Weline\Framework\Runtime\RequestContext::isInitialized()
                ? (\Weline\Framework\Runtime\RequestContext::getId() ?? '') : '';
            \w_log_error('[FrontendWorkerState] stage=' . $stage
                . ' adapter=' . $this->adapter::class
                . ' exception=' . ($error === null ? 'none' : $error::class)
                . ' request_id=' . $requestId, [], 'wls_cache_cas');
        } catch (\Throwable) {
            // Diagnostics must preserve the original transaction result.
        }
    }

    private function isPacked(mixed $wire): bool
    {
        return \is_string($wire) && \str_starts_with($wire, self::PACKED_PREFIX);
    }

    /** Compare the exact stored wire value; resolve only the callback's state. */
    private function decodeSnapshot(mixed $wire): ?array
    {
        if ($wire === null || \is_array($wire)) { return $wire; }
        if ($this->isPacked($wire)) {
            try {
                $compressed = \base64_decode(\substr($wire, \strlen(self::PACKED_PREFIX)), true);
                $json = $compressed === false ? false : @\gzdecode($compressed, self::MAX_STATE_BYTES + 1);
                if ($json === false || \strlen($json) > self::MAX_STATE_BYTES) {
                    throw new \RuntimeException('Invalid packed Worker state.');
                }
                $state = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($state)) { throw new \RuntimeException('Invalid packed Worker state type.'); }
                return $state;
            } catch (\Throwable $error) {
                throw new FrontendQueryException('worker_store_unavailable', 'Shared worker session state is invalid.', 503, $error);
            }
        }
        throw new FrontendQueryException('worker_store_unavailable', 'Shared worker session state is invalid.', 503);
    }

    private function encodeSnapshot(array $state, bool $compact): array|string
    {
        if (!$compact || !\function_exists('gzencode')) { return $state; }
        $json = \json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (\strlen($json) < self::COMPRESSION_THRESHOLD_BYTES
            || \json_decode($json, true, 512, JSON_THROW_ON_ERROR) !== $state) {
            return $state;
        }
        $compressed = \gzencode($json, 1);
        if ($compressed === false) { return $state; }
        $packed = self::PACKED_PREFIX . \base64_encode($compressed);
        return \strlen($packed) < \strlen($json) ? $packed : $state;
    }

    public function isShared(): bool
    {
        return $this->sharedTopology;
    }

    private function recoverRemoteIfPossible(): void
    {
        if (\method_exists($this->adapter, 'recoverRemoteProbe')) {
            $this->adapter->recoverRemoteProbe();
        }
    }

    private function isAdapterUnavailable(): bool
    {
        return $this->adapter instanceof CacheAdapterHealthInterface && !$this->adapter->isAvailable();
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
