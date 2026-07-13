<?php

declare(strict_types=1);

namespace Weline\Server\Security;

use Weline\Server\Service\Contract\MemoryStateFacadeInterface;

/**
 * Instance-wide fixed-window limiter with small local token leases.
 *
 * Shared increments reserve a bounded token batch. If the shared service is
 * briefly unavailable, each Worker receives only its conservative share of
 * the configured limit; a failed state call never becomes unlimited traffic.
 */
final class GlobalRateLimiter
{
    private const COUNTER_NAMESPACE = 'wls.policy.rate';
    private const BAN_NAMESPACE = 'wls.policy.ban';
    private const PATH_SEEN_NAMESPACE = 'wls.policy.path_seen';
    private const PATH_COUNT_NAMESPACE = 'wls.policy.path_count';
    private const BAN_NEGATIVE_CACHE_SECONDS = 0.25;
    private const BAN_POSITIVE_CACHE_SECONDS = 1.0;
    private const BAN_POSITIVE_CACHE_MAX_ENTRIES = 4096;
    private const BAN_CAS_ATTEMPTS = 3;

    /** @var array<string, float> Process-local bans received over POLICY_STATE_DELTA. */
    private static array $distributedPositiveBans = [];

    private static ?\Closure $banDeltaPublisher = null;

    /** @var array<string, array{window:int,remaining:int}> */
    private array $leases = [];

    /** @var array<string, array{window:int,count:int}> */
    private array $fallback = [];

    /** @var array<string, array{window:int,paths:array<string,true>}> */
    private array $fallbackUniquePaths = [];

    /** @var array<string, float> */
    private array $positiveBans = [];

    /** @var array<string, float> */
    private array $negativeBans = [];

    private bool $sharedAvailable = true;

    private float $sharedRetryAt = 0.0;

    public function __construct(
        private ?MemoryStateFacadeInterface $state,
        private readonly int $readyWorkers,
        private readonly string $instanceName = 'default',
    ) {
    }

    public function attachState(?MemoryStateFacadeInterface $state): void
    {
        $this->state = $state;
        $this->sharedAvailable = $state !== null;
        $this->sharedRetryAt = 0.0;
    }

    public function shouldReconnectSharedState(): bool
    {
        return (!$this->sharedAvailable || $this->state === null) && \microtime(true) >= $this->sharedRetryAt;
    }

    public static function setBanDeltaPublisher(?callable $publisher): void
    {
        self::$banDeltaPublisher = $publisher === null ? null : \Closure::fromCallable($publisher);
    }

    public static function applyBanDelta(
        string $instanceName,
        string $ip,
        int $expiresAt,
        string $expectedInstanceName = '',
    ): bool {
        $instanceName = \trim($instanceName);
        $ip = self::normalizeIp($ip);
        if ($instanceName === '' || $ip === '' || $expiresAt <= \time()) {
            return false;
        }
        if ($expectedInstanceName !== '' && !\hash_equals($expectedInstanceName, $instanceName)) {
            return false;
        }

        self::rememberDistributedBan($instanceName, $ip, (float)$expiresAt);
        return true;
    }

    public function isBanned(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $now = \microtime(true);
        $positiveUntil = \max(
            (float)($this->positiveBans[$ip] ?? 0.0),
            (float)(self::$distributedPositiveBans[$this->distributedBanKey($ip)] ?? 0.0),
        );
        if ($positiveUntil > $now) {
            return true;
        }
        if ($positiveUntil > 0.0) {
            unset(
                $this->positiveBans[$ip],
                self::$distributedPositiveBans[$this->distributedBanKey($ip)],
            );
        }
        if ((float)($this->negativeBans[$ip] ?? 0.0) > $now) {
            return false;
        }
        if ($this->state === null || !$this->sharedAvailable) {
            return false;
        }
        try {
            $value = $this->state->get(self::BAN_NAMESPACE, $this->sharedKey($ip));
            if ($value !== null && $value !== false && $value !== 0 && $value !== '0') {
                $declaredExpiry = \is_numeric($value) ? (float)$value : 0.0;
                $this->rememberPositiveBan($ip, $declaredExpiry > $now
                    ? $declaredExpiry
                    : $now + self::BAN_POSITIVE_CACHE_SECONDS);
                unset($this->negativeBans[$ip]);
                return true;
            }
            $this->negativeBans[$ip] = $now + self::BAN_NEGATIVE_CACHE_SECONDS;
            return false;
        } catch (\Throwable) {
            $this->markSharedUnavailable();
            return false;
        }
    }

    public function ban(string $ip, int $ttl): void
    {
        if ($ip === '') {
            return;
        }
        $ip = self::normalizeIp($ip);
        if ($ip === '') {
            return;
        }
        $expiresAt = \time() + \max(1, $ttl);
        $expiresAt = \max(
            $expiresAt,
            (int)($this->positiveBans[$ip] ?? 0),
            (int)(self::$distributedPositiveBans[$this->distributedBanKey($ip)] ?? 0),
        );

        if ($this->state !== null && $this->sharedAvailable) {
            try {
                $key = $this->sharedKey($ip);
                for ($attempt = 0; $attempt < self::BAN_CAS_ATTEMPTS; $attempt++) {
                    $current = $this->state->get(self::BAN_NAMESPACE, $key);
                    $currentExpiry = \is_numeric($current) ? (int)$current : 0;
                    if ($currentExpiry >= $expiresAt) {
                        $expiresAt = $currentExpiry;
                        break;
                    }
                    if ($this->state->cas(
                        self::BAN_NAMESPACE,
                        $key,
                        $current,
                        $expiresAt,
                        \max(1, $expiresAt - \time()),
                    )) {
                        break;
                    }
                }
            } catch (\Throwable) {
                $this->markSharedUnavailable();
            }
        }

        $this->rememberPositiveBan($ip, (float)$expiresAt);
        self::rememberDistributedBan($this->instanceName, $ip, (float)$expiresAt);
        unset($this->negativeBans[$ip]);
        $publisher = self::$banDeltaPublisher;
        if ($publisher !== null) {
            try {
                $publisher($this->instanceName, $ip, $expiresAt);
            } catch (\Throwable) {
                // Ban enforcement must not depend on best-effort IPC fan-out.
            }
        }
    }

    /**
     * Remove one IP ban, or every ban owned by this WLS instance.
     *
     * Shared bans from all instances intentionally use one namespace for
     * O(1) lookups. A clear-all operation therefore deletes only keys under
     * this instance hash instead of clearing the whole namespace.
     */
    public function clearBans(?string $ip = null, bool $clearAll = false): bool
    {
        if ($clearAll) {
            $this->positiveBans = [];
            $this->negativeBans = [];
            self::clearDistributedBans($this->instanceName);

            if ($this->state === null || !$this->sharedAvailable) {
                return true;
            }

            $prefix = $this->sharedKey('');
            try {
                foreach (\array_keys($this->state->getAll(self::BAN_NAMESPACE)) as $key) {
                    $key = (string)$key;
                    if (\str_starts_with($key, $prefix)) {
                        $this->state->delete(self::BAN_NAMESPACE, $key);
                    }
                }
            } catch (\Throwable) {
                $this->markSharedUnavailable();
            }

            return true;
        }

        $ip = self::normalizeIp((string)$ip);
        if ($ip === '') {
            return false;
        }

        unset(
            $this->positiveBans[$ip],
            $this->negativeBans[$ip],
            self::$distributedPositiveBans[$this->distributedBanKey($ip)],
        );
        if ($this->state !== null && $this->sharedAvailable) {
            try {
                $this->state->delete(self::BAN_NAMESPACE, $this->sharedKey($ip));
            } catch (\Throwable) {
                $this->markSharedUnavailable();
            }
        }

        return true;
    }

    public function allow(string $scope, string $identity, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0 || $windowSeconds <= 0) {
            return true;
        }
        $now = \time();
        $window = intdiv($now, $windowSeconds);
        $leaseKey = $scope . '|' . $identity;
        $lease = $this->leases[$leaseKey] ?? null;
        if ($lease !== null && $lease['window'] === $window && $lease['remaining'] > 0) {
            $this->leases[$leaseKey]['remaining']--;
            return true;
        }

        if ($this->state !== null && $this->sharedAvailable) {
            $batch = $this->leaseSize($limit);
            $counterKey = $this->sharedKey(\hash('xxh3', $leaseKey) . ':' . $window);
            try {
                $newValue = $this->state->incr(
                    self::COUNTER_NAMESPACE,
                    $counterKey,
                    $batch,
                    $windowSeconds + 2,
                );
                if ($newValue !== null) {
                    $previous = $newValue - $batch;
                    $granted = \max(0, \min($batch, $limit - $previous));
                    $overReserved = $batch - $granted;
                    if ($overReserved > 0) {
                        try {
                            $this->state->decr(
                                self::COUNTER_NAMESPACE,
                                $counterKey,
                                $overReserved,
                                $windowSeconds + 2,
                            );
                        } catch (\Throwable) {
                        }
                    }
                    if ($granted <= 0) {
                        return false;
                    }
                    $this->leases[$leaseKey] = ['window' => $window, 'remaining' => $granted - 1];
                    return true;
                }
            } catch (\Throwable) {
                $this->markSharedUnavailable();
            }
        }

        $localLimit = \max(1, intdiv($limit, \max(1, $this->readyWorkers)));
        $bucket = $this->fallback[$leaseKey] ?? ['window' => $window, 'count' => 0];
        if ($bucket['window'] !== $window) {
            $bucket = ['window' => $window, 'count' => 0];
        }
        $bucket['count']++;
        $this->fallback[$leaseKey] = $bucket;
        return $bucket['count'] <= $localLimit;
    }

    /**
     * Count distinct paths per identity without multiplying the configured
     * threshold by the Worker count. A path is first claimed with CAS; only
     * the winner increments the instance-wide counter.
     */
    public function allowUniquePath(
        string $identity,
        string $path,
        int $limit,
        int $windowSeconds,
    ): bool {
        if ($identity === '' || $path === '' || $limit <= 0 || $windowSeconds <= 0) {
            return true;
        }
        $window = \intdiv(\time(), $windowSeconds);
        $identityHash = \hash('xxh3', $identity);
        $pathHash = \hash('xxh3', $path);
        $ttl = $windowSeconds + 2;

        if ($this->state !== null && $this->sharedAvailable) {
            $seenKey = $this->sharedKey($identityHash . ':' . $window . ':' . $pathHash);
            try {
                $claimed = $this->state->cas(self::PATH_SEEN_NAMESPACE, $seenKey, null, 1, $ttl);
                if (!$claimed) {
                    if ($this->state->exists(self::PATH_SEEN_NAMESPACE, $seenKey)) {
                        return true;
                    }
                    $this->markSharedUnavailable();
                } else {
                    $count = $this->state->incr(
                        self::PATH_COUNT_NAMESPACE,
                        $this->sharedKey($identityHash . ':' . $window),
                        1,
                        $ttl,
                    );
                    if ($count !== null) {
                        return $count <= $limit;
                    }
                    $this->markSharedUnavailable();
                }
            } catch (\Throwable) {
                $this->markSharedUnavailable();
            }
        }

        $localLimit = \max(1, \intdiv($limit, \max(1, $this->readyWorkers)));
        $bucket = $this->fallbackUniquePaths[$identity] ?? ['window' => $window, 'paths' => []];
        if ($bucket['window'] !== $window) {
            $bucket = ['window' => $window, 'paths' => []];
        }
        $bucket['paths'][$pathHash] = true;
        $this->fallbackUniquePaths[$identity] = $bucket;

        return \count($bucket['paths']) <= $localLimit;
    }

    private function leaseSize(int $limit): int
    {
        return match (true) {
            $limit < 32 => 1,
            $limit < 256 => 8,
            $limit < 2048 => 16,
            default => 32,
        };
    }

    private function markSharedUnavailable(): void
    {
        $this->sharedAvailable = false;
        $this->sharedRetryAt = \microtime(true) + 1.0;
    }

    private function sharedKey(string $key): string
    {
        return \hash('xxh3', $this->instanceName) . ':' . $key;
    }

    private function distributedBanKey(string $ip): string
    {
        return \hash('xxh3', $this->instanceName) . ':' . $ip;
    }

    private function rememberPositiveBan(string $ip, float $expiresAt): void
    {
        if (!isset($this->positiveBans[$ip]) && \count($this->positiveBans) >= self::BAN_POSITIVE_CACHE_MAX_ENTRIES) {
            \array_shift($this->positiveBans);
        }
        $this->positiveBans[$ip] = \max((float)($this->positiveBans[$ip] ?? 0.0), $expiresAt);
    }

    private static function rememberDistributedBan(string $instanceName, string $ip, float $expiresAt): void
    {
        $key = \hash('xxh3', $instanceName) . ':' . $ip;
        if (!isset(self::$distributedPositiveBans[$key])
            && \count(self::$distributedPositiveBans) >= self::BAN_POSITIVE_CACHE_MAX_ENTRIES) {
            \array_shift(self::$distributedPositiveBans);
        }
        self::$distributedPositiveBans[$key] = \max(
            (float)(self::$distributedPositiveBans[$key] ?? 0.0),
            $expiresAt,
        );
    }

    private static function clearDistributedBans(string $instanceName): void
    {
        $prefix = \hash('xxh3', $instanceName) . ':';
        foreach (\array_keys(self::$distributedPositiveBans) as $key) {
            if (\str_starts_with((string)$key, $prefix)) {
                unset(self::$distributedPositiveBans[$key]);
            }
        }
    }

    private static function normalizeIp(string $ip): string
    {
        $packed = @\inet_pton(\trim($ip));
        if ($packed === false) {
            return '';
        }
        return (string)\inet_ntop($packed);
    }
}
