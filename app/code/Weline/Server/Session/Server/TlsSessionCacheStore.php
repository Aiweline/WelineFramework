<?php

declare(strict_types=1);

namespace Weline\Server\Session\Server;

/**
 * Dedicated, RAM-only TLS session cache owned by the Memory sidecar.
 *
 * TLS session DER contains resumption key material. This store therefore has
 * no persistence, debug dump, generic Session namespace, or log surface.
 */
final class TlsSessionCacheStore
{
    /**
     * @var array<string, array{der:string,created_at:int,expires_at:int,bytes:int}>
     */
    private array $entries = [];
    /** @var array<int, array<string, true>> */
    private array $expiryBuckets = [];
    private \SplPriorityQueue $expiryQueue;
    private int $totalBytes = 0;
    private readonly int $maxTotalBytes;
    private readonly string $configFingerprint;
    private int $memoryHighWatermarkBytes = 0;
    private int $memoryLowWatermarkBytes = 0;
    private int $memoryPressureEvictions = 0;
    private int $getCount = 0;
    private int $hitCount = 0;
    private int $putCount = 0;
    private int $rejectCount = 0;
    private int $removeCount = 0;

    public function __construct(
        private readonly int $maxEntries = 20000,
        int $maxTotalBytes = 67108864,
        private readonly int $maxSessionBytes = 16384,
        private readonly int $maxTtlSeconds = 300,
        float $memoryHighWatermarkRatio = 0.75,
        float $memoryLowWatermarkRatio = 0.60,
    ) {
        $this->expiryQueue = new \SplPriorityQueue();
        $this->expiryQueue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $memoryLimit = (string)\ini_get('memory_limit');
        $memoryLimitBytes = self::parseMemoryBytes($memoryLimit);
        if ($memoryLimitBytes > 0) {
            // PHP hash-table overhead is not represented by logical DER bytes.
            // Reserve at least 75% of the process budget for framework/FPC data,
            // protocol buffers, and the TLS store's own array overhead.
            $maxTotalBytes = \min($maxTotalBytes, \max(1, \intdiv($memoryLimitBytes, 4)));
            $highRatio = \max(0.10, \min(0.95, $memoryHighWatermarkRatio));
            $lowRatio = \max(0.05, \min($highRatio - 0.01, $memoryLowWatermarkRatio));
            $this->memoryHighWatermarkBytes = (int)\floor($memoryLimitBytes * $highRatio);
            $this->memoryLowWatermarkBytes = (int)\floor($memoryLimitBytes * $lowRatio);
        }
        $this->maxTotalBytes = \max(1, $maxTotalBytes);
        $this->configFingerprint = self::configurationFingerprint(
            $this->maxEntries,
            $this->maxTotalBytes,
            $this->maxSessionBytes,
            $this->maxTtlSeconds,
        );
    }

    /**
     * @return array{der:string,created_at:int,expires_at:int}|null
     */
    public function get(string $contextHex, string $sessionIdHex): ?array
    {
        $this->getCount++;
        if (!$this->validContext($contextHex) || !$this->validSessionId($sessionIdHex)) {
            return null;
        }
        $key = $contextHex . ':' . $sessionIdHex;
        $entry = $this->entries[$key] ?? null;
        if (!\is_array($entry)) {
            return null;
        }
        if ($entry['expires_at'] <= \time()) {
            $this->removeKey($key);
            return null;
        }

        // PHP arrays preserve insertion order. Reinsert on hit for bounded LRU.
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;
        $this->hitCount++;

        return [
            'der' => $entry['der'],
            'created_at' => $entry['created_at'],
            'expires_at' => $entry['expires_at'],
        ];
    }

    public function put(
        string $contextHex,
        string $sessionIdHex,
        string $derBase64,
        int $createdAt,
        int $expiresAt
    ): bool {
        $this->putCount++;
        if (!$this->validContext($contextHex) || !$this->validSessionId($sessionIdHex)) {
            $this->rejectCount++;
            return false;
        }
        $now = \time();
        if ($createdAt <= 0
            || $createdAt > $now + 60
            || $expiresAt <= $now
            || $expiresAt <= $createdAt
            || $expiresAt > $now + $this->maxTtlSeconds
            || $expiresAt > $createdAt + $this->maxTtlSeconds
        ) {
            $this->rejectCount++;
            return false;
        }
        $maxEncodedBytes = 4 * \intdiv($this->maxSessionBytes + 2, 3);
        if (\strlen($derBase64) > $maxEncodedBytes) {
            $this->rejectCount++;
            return false;
        }
        $der = \base64_decode($derBase64, true);
        if (!\is_string($der) || $der === '' || \strlen($der) > $this->maxSessionBytes) {
            $this->rejectCount++;
            return false;
        }
        $canonicalDerBase64 = \base64_encode($der);
        if (!\hash_equals($canonicalDerBase64, $derBase64)) {
            $this->rejectCount++;
            return false;
        }
        unset($der);

        $key = $contextHex . ':' . $sessionIdHex;
        $bytes = \strlen($key) + \strlen($canonicalDerBase64) + 64;
        if ($bytes > $this->maxTotalBytes) {
            $this->rejectCount++;
            return false;
        }

        // Never make a TLS handshake perform an unbounded eviction sweep.
        // If 256 O(1) LRU removals cannot restore headroom, fail open and let
        // OpenSSL continue with a full handshake instead of risking sidecar OOM.
        $this->relieveMemoryPressure(256);
        if ($this->underMemoryPressure()) {
            $this->rejectCount++;
            return false;
        }

        $this->evictExpired($now, 64, 0.0002);
        if (isset($this->entries[$key])) {
            $this->removeKey($key);
        }
        while ($this->entries !== []
            && (\count($this->entries) >= $this->maxEntries || $this->totalBytes + $bytes > $this->maxTotalBytes)
        ) {
            $oldestKey = \array_key_first($this->entries);
            if (!\is_string($oldestKey)) {
                break;
            }
            $this->removeKey($oldestKey);
        }
        if (\count($this->entries) >= $this->maxEntries || $this->totalBytes + $bytes > $this->maxTotalBytes) {
            $this->rejectCount++;
            return false;
        }

        $this->entries[$key] = [
            'der' => $canonicalDerBase64,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'bytes' => $bytes,
        ];
        if (!isset($this->expiryBuckets[$expiresAt])) {
            $this->expiryBuckets[$expiresAt] = [];
            $this->expiryQueue->insert($expiresAt, -$expiresAt);
        }
        $this->expiryBuckets[$expiresAt][$key] = true;
        $this->totalBytes += $bytes;

        return true;
    }

    public function remove(string $contextHex, string $sessionIdHex): bool
    {
        $this->removeCount++;
        if (!$this->validContext($contextHex) || !$this->validSessionId($sessionIdHex)) {
            return false;
        }

        return $this->removeKey($contextHex . ':' . $sessionIdHex);
    }

    /** @return array<string, int|string> */
    public function stats(): array
    {
        $this->evictExpired(\time(), 256, 0.0005);

        return [
            'entries' => \count($this->entries),
            'total_bytes' => $this->totalBytes,
            'max_entries' => $this->maxEntries,
            'max_total_bytes' => $this->maxTotalBytes,
            'memory_pressure_evictions' => $this->memoryPressureEvictions,
            'gets' => $this->getCount,
            'hits' => $this->hitCount,
            'puts' => $this->putCount,
            'rejects' => $this->rejectCount,
            'removes' => $this->removeCount,
            'max_session_bytes' => $this->maxSessionBytes,
            'timeout_seconds' => $this->maxTtlSeconds,
            'config_fingerprint' => $this->configFingerprint,
        ];
    }

    public function maintain(int $maximumEntries = 2048, float $maximumSeconds = 0.001): int
    {
        return $this->evictExpired(
            \time(),
            \max(1, $maximumEntries),
            \max(0.0001, $maximumSeconds),
        );
    }

    public static function expectedConfigurationFingerprint(
        int $maxEntries,
        int $maxTotalBytes,
        int $maxSessionBytes,
        int $maxTtlSeconds,
        string $memoryLimit
    ): string {
        $memoryLimitBytes = self::parseMemoryBytes($memoryLimit);
        if ($memoryLimitBytes > 0) {
            $maxTotalBytes = \min($maxTotalBytes, \max(1, \intdiv($memoryLimitBytes, 4)));
        }

        return self::configurationFingerprint(
            $maxEntries,
            \max(1, $maxTotalBytes),
            $maxSessionBytes,
            $maxTtlSeconds,
        );
    }

    /**
     * Evict TLS entries before the generic Session/Memory store loses FPC data.
     */
    public function relieveMemoryPressure(int $maxEvictions = 4096): int
    {
        if (!$this->underMemoryPressure() || $maxEvictions <= 0) {
            return 0;
        }

        $evicted = 0;
        while ($this->entries !== []
            && $evicted < $maxEvictions
            && \memory_get_usage(false) > $this->memoryLowWatermarkBytes
        ) {
            $oldestKey = \array_key_first($this->entries);
            if (!\is_string($oldestKey) || !$this->removeKey($oldestKey)) {
                break;
            }
            $evicted++;
        }
        $this->memoryPressureEvictions += $evicted;

        return $evicted;
    }

    private function evictExpired(int $now, int $maximumEntries, float $maximumSeconds): int
    {
        $evicted = 0;
        $deadline = \hrtime(true) + (int)\max(1, \floor($maximumSeconds * 1_000_000_000));
        while (!$this->expiryQueue->isEmpty()
            && $evicted < $maximumEntries
            && \hrtime(true) < $deadline
        ) {
            $expiresAt = (int)$this->expiryQueue->top();
            if (!isset($this->expiryBuckets[$expiresAt])) {
                $this->expiryQueue->extract();
                continue;
            }
            if ($expiresAt > $now) {
                break;
            }

            while (isset($this->expiryBuckets[$expiresAt])
                && $this->expiryBuckets[$expiresAt] !== []
                && $evicted < $maximumEntries
                && \hrtime(true) < $deadline
            ) {
                $key = \array_key_first($this->expiryBuckets[$expiresAt]);
                if (!\is_string($key)) {
                    unset($this->expiryBuckets[$expiresAt]);
                    break;
                }
                $entry = $this->entries[$key] ?? null;
                if (!\is_array($entry) || (int)($entry['expires_at'] ?? 0) !== $expiresAt) {
                    unset($this->expiryBuckets[$expiresAt][$key]);
                    continue;
                }
                $this->removeKey($key);
                $evicted++;
            }
            if (!isset($this->expiryBuckets[$expiresAt]) || $this->expiryBuckets[$expiresAt] === []) {
                unset($this->expiryBuckets[$expiresAt]);
                $this->expiryQueue->extract();
            }
        }

        return $evicted;
    }

    private function removeKey(string $key): bool
    {
        $entry = $this->entries[$key] ?? null;
        if (!\is_array($entry)) {
            return false;
        }
        $expiresAt = (int)($entry['expires_at'] ?? 0);
        if ($expiresAt > 0 && isset($this->expiryBuckets[$expiresAt][$key])) {
            unset($this->expiryBuckets[$expiresAt][$key]);
            if ($this->expiryBuckets[$expiresAt] === []) {
                unset($this->expiryBuckets[$expiresAt]);
            }
        }
        $this->totalBytes = \max(0, $this->totalBytes - (int)($entry['bytes'] ?? 0));
        unset($this->entries[$key]);

        return true;
    }

    private function validContext(string $contextHex): bool
    {
        return \strlen($contextHex) === 64
            && (bool)\preg_match('/^[a-f0-9]{64}$/D', $contextHex);
    }

    private function validSessionId(string $sessionIdHex): bool
    {
        $length = \strlen($sessionIdHex);

        return $length >= 2
            && $length <= 64
            && ($length % 2) === 0
            && (bool)\preg_match('/^[a-f0-9]+$/D', $sessionIdHex);
    }

    private function underMemoryPressure(): bool
    {
        return $this->memoryHighWatermarkBytes > 0
            && \memory_get_usage(false) >= $this->memoryHighWatermarkBytes;
    }

    private static function parseMemoryBytes(string $value): int
    {
        $value = \strtoupper(\trim($value));
        if ($value === '' || $value === '-1') {
            return 0;
        }
        if (!\preg_match('/^(\d+)([KMG]?)$/D', $value, $matches)) {
            return 0;
        }

        $bytes = (int)$matches[1];
        return match ($matches[2]) {
            'G' => $bytes * 1024 * 1024 * 1024,
            'M' => $bytes * 1024 * 1024,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }

    private static function configurationFingerprint(
        int $maxEntries,
        int $maxTotalBytes,
        int $maxSessionBytes,
        int $maxTtlSeconds
    ): string {
        return \hash('sha256', \implode("\0", [
            'wls-tls-session-store-v1',
            (string)$maxEntries,
            (string)$maxTotalBytes,
            (string)$maxSessionBytes,
            (string)$maxTtlSeconds,
        ]));
    }
}
