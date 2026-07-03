<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\Runtime;

class PixelHotBufferService
{
    private const MEMORY_CLASS = 'Weline\\Server\\Service\\MemoryStateFacade';
    private const NAMESPACE = 'visitor.pixel.hot_buffer';
    private const KEY_LAST_FLUSH_AT = 'last_flush_at';
    private const KEY_LAST_FLUSH_RESULT = 'last_flush_result';
    private const KEY_LAST_ERROR = 'last_error';
    private const KEY_PENDING_COUNT = 'pending_count';
    private const KEY_FLUSH_LOCK = 'flush_lock';
    private const BUCKET_PREFIX = 'events:';
    private const DEFAULT_FLUSH_INTERVAL = 15;
    private const DEFAULT_BATCH_SIZE = 500;
    private const DEFAULT_TTL = 300;

    private ?object $memory = null;

    public function __construct(
        private ?PixelEventPersistenceService $persistenceService = null,
        private ?VisitorTrackingConfig $trackingConfig = null
    ) {
    }

    /**
     * @param array{post: array<string, mixed>, data: array<string, mixed>, event_id?: string, received_at?: int} $envelope
     * @return array<string, mixed>|null
     */
    public function buffer(array $envelope): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $memory = $this->memory();
        if (!$memory) {
            return null;
        }

        $now = \time();
        $interval = $this->flushInterval();
        $bucketTs = $this->bucketTimestamp($now, $interval);
        $websiteId = (int)($envelope['data']['website_id'] ?? 0);
        $bucketKey = $this->bucketKey($bucketTs, $websiteId);
        $payload = [
            'event_id' => (string)($envelope['event_id'] ?? $this->eventId($envelope)),
            'received_at' => (int)($envelope['received_at'] ?? $now),
            'post' => $envelope['post'],
            'data' => $envelope['data'],
        ];

        try {
            if (!$memory->append(self::NAMESPACE, $bucketKey, $payload, $this->ttl())) {
                return null;
            }
            $memory->incr(self::NAMESPACE, self::KEY_PENDING_COUNT, 1, $this->ttl());
            $memory->set(self::NAMESPACE, 'last_buffered_at', $now, $this->ttl());
        } catch (\Throwable $throwable) {
            $this->recordError('buffer', $throwable);
            return null;
        }

        $this->queueFlushIfDue();

        return [
            'buffered' => true,
            'bucket' => $bucketKey,
            'bucket_ts' => $bucketTs,
            'flush_interval' => $interval,
        ];
    }

    public function queueFlushIfDue(): void
    {
        if (!Runtime::isPersistent() || !\class_exists(PostResponseTaskQueue::class)) {
            return;
        }

        PostResponseTaskQueue::enqueue('visitor-pixel-hot-buffer-flush', static function (): void {
            try {
                /** @var self $service */
                $service = ObjectManager::getInstance(self::class);
                $service->flushDue(false);
            } catch (\Throwable $throwable) {
                w_log_error('Visitor Pixel hot buffer post-response flush failed: ' . $throwable->getMessage());
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function flushDue(bool $force = false, int $limit = 0): array
    {
        $memory = $this->memory();
        if (!$memory) {
            return [
                'success' => false,
                'flushed' => false,
                'reason' => 'memory_unavailable',
                'stats' => $this->stats(),
            ];
        }

        $now = \time();
        $interval = $this->flushInterval();
        $lastFlushAt = (int)($memory->get(self::NAMESPACE, self::KEY_LAST_FLUSH_AT) ?? 0);
        if (!$force && $lastFlushAt > 0 && ($now - $lastFlushAt) < $interval) {
            return [
                'success' => true,
                'flushed' => false,
                'reason' => 'not_due',
                'next_flush_in' => $interval - ($now - $lastFlushAt),
                'stats' => $this->stats(),
            ];
        }

        $token = $this->lockToken();
        try {
            $locked = $memory->cas(self::NAMESPACE, self::KEY_FLUSH_LOCK, null, $token, \max(5, $interval * 2));
        } catch (\Throwable $throwable) {
            $this->recordError('lock', $throwable);
            return [
                'success' => false,
                'flushed' => false,
                'reason' => 'lock_failed',
                'error' => $throwable->getMessage(),
                'stats' => $this->stats(),
            ];
        }

        if (!$locked) {
            return [
                'success' => true,
                'flushed' => false,
                'reason' => 'locked',
                'stats' => $this->stats(),
            ];
        }

        $processed = 0;
        $failed = 0;
        $bucketCount = 0;
        $limit = $limit > 0 ? $limit : $this->batchSize();
        $errors = [];

        try {
            $all = $memory->getAll(self::NAMESPACE);
            $currentBucket = $this->bucketTimestamp($now, $interval);
            $bucketKeys = $this->dueBucketKeys($all, $currentBucket);

            foreach ($bucketKeys as $bucketKey) {
                if ($processed >= $limit) {
                    break;
                }
                $events = $all[$bucketKey] ?? [];
                if (!\is_array($events)) {
                    $memory->delete(self::NAMESPACE, $bucketKey);
                    continue;
                }

                $bucketCount++;
                $remaining = [];
                foreach (\array_values($events) as $index => $event) {
                    if ($processed >= $limit) {
                        $remaining = \array_merge($remaining, \array_slice(\array_values($events), $index));
                        break;
                    }
                    if (!\is_array($event) || !\is_array($event['post'] ?? null) || !\is_array($event['data'] ?? null)) {
                        $failed++;
                        continue;
                    }

                    try {
                        $this->persistence()->persistPrepared($event['post'], $event['data']);
                        $processed++;
                    } catch (\Throwable $throwable) {
                        $failed++;
                        $remaining[] = $event;
                        $errors[] = $throwable->getMessage();
                    }
                }

                if ($remaining === []) {
                    $memory->delete(self::NAMESPACE, $bucketKey);
                } else {
                    $memory->set(self::NAMESPACE, $bucketKey, $remaining, $this->ttl());
                }
            }

            if ($processed > 0) {
                $memory->decr(self::NAMESPACE, self::KEY_PENDING_COUNT, $processed, $this->ttl());
            }

            $result = [
                'success' => $failed === 0,
                'flushed' => $processed > 0 || $bucketCount > 0,
                'processed' => $processed,
                'failed' => $failed,
                'bucket_count' => $bucketCount,
                'errors' => \array_values(\array_unique(\array_slice($errors, 0, 5))),
                'flushed_at' => $now,
            ];
            $memory->set(self::NAMESPACE, self::KEY_LAST_FLUSH_AT, $now, $this->ttl());
            $memory->set(self::NAMESPACE, self::KEY_LAST_FLUSH_RESULT, $result, $this->ttl());
            if ($errors === []) {
                $memory->delete(self::NAMESPACE, self::KEY_LAST_ERROR);
            } else {
                $memory->set(self::NAMESPACE, self::KEY_LAST_ERROR, $errors[0], $this->ttl());
            }

            $result['stats'] = $this->stats();
            return $result;
        } catch (\Throwable $throwable) {
            $this->recordError('flush', $throwable);
            return [
                'success' => false,
                'flushed' => false,
                'reason' => 'flush_failed',
                'error' => $throwable->getMessage(),
                'processed' => $processed,
                'failed' => $failed,
                'stats' => $this->stats(),
            ];
        } finally {
            try {
                $memory->cas(self::NAMESPACE, self::KEY_FLUSH_LOCK, $token, null, 1);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $enabled = $this->isEnabled();
        $memory = $this->memory();
        $stats = [
            'enabled' => $enabled,
            'available' => (bool)$memory,
            'runtime' => Runtime::isPersistent() ? 'wls' : 'fpm',
            'namespace' => self::NAMESPACE,
            'flushInterval' => $this->flushInterval(),
            'batchSize' => $this->batchSize(),
            'pending' => 0,
            'bucketCount' => 0,
            'oldestBucketTs' => null,
            'lastFlushAt' => null,
            'lastFlushResult' => null,
            'lastError' => null,
        ];

        if (!$memory) {
            return $stats;
        }

        try {
            $all = $memory->getAll(self::NAMESPACE);
            $oldest = null;
            $pending = 0;
            $bucketCount = 0;
            foreach ($all as $key => $value) {
                if (!\is_string($key) || !\str_starts_with($key, self::BUCKET_PREFIX) || !\is_array($value)) {
                    continue;
                }
                $bucketCount++;
                $pending += \count($value);
                $ts = $this->parseBucketTimestamp($key);
                if ($ts !== null && ($oldest === null || $ts < $oldest)) {
                    $oldest = $ts;
                }
            }

            $stats['pending'] = $pending;
            $stats['bucketCount'] = $bucketCount;
            $stats['oldestBucketTs'] = $oldest;
            $stats['pendingEstimate'] = \max(0, (int)($memory->get(self::NAMESPACE, self::KEY_PENDING_COUNT) ?? $pending));
            $stats['lastFlushAt'] = $memory->get(self::NAMESPACE, self::KEY_LAST_FLUSH_AT);
            $stats['lastFlushResult'] = $memory->get(self::NAMESPACE, self::KEY_LAST_FLUSH_RESULT);
            $stats['lastError'] = $memory->get(self::NAMESPACE, self::KEY_LAST_ERROR);
        } catch (\Throwable $throwable) {
            $stats['available'] = false;
            $stats['lastError'] = $throwable->getMessage();
        }

        return $stats;
    }

    public function isEnabled(): bool
    {
        if (!Runtime::isPersistent()) {
            return false;
        }

        $config = $this->hotBufferConfig();
        if (!\array_key_exists('enabled', $config)) {
            return true;
        }

        return $this->toBool($config['enabled'], true);
    }

    private function persistence(): PixelEventPersistenceService
    {
        if (!$this->persistenceService) {
            $this->persistenceService = ObjectManager::getInstance(PixelEventPersistenceService::class);
        }

        return $this->persistenceService;
    }

    private function trackingConfig(): VisitorTrackingConfig
    {
        if (!$this->trackingConfig) {
            $this->trackingConfig = ObjectManager::getInstance(VisitorTrackingConfig::class);
        }

        return $this->trackingConfig;
    }

    /**
     * @return array<string, mixed>
     */
    private function hotBufferConfig(): array
    {
        try {
            $config = $this->trackingConfig()->getRuntimeConfig();
            return \is_array($config['hotBuffer'] ?? null) ? $config['hotBuffer'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function memory(): ?object
    {
        if (!Runtime::isPersistent() || !\class_exists(self::MEMORY_CLASS)) {
            return null;
        }
        if ($this->memory) {
            return $this->memory;
        }

        try {
            $memory = ObjectManager::getInstance(self::MEMORY_CLASS);
            if (!\is_object($memory) || !\method_exists($memory, 'append') || !\method_exists($memory, 'getAll')) {
                return null;
            }
            if (\method_exists($memory, 'ping') && !$memory->ping()) {
                return null;
            }
            $this->memory = $memory;
            return $memory;
        } catch (\Throwable $throwable) {
            $this->recordError('memory', $throwable);
            return null;
        }
    }

    private function flushInterval(): int
    {
        $config = $this->hotBufferConfig();
        return \max(1, (int)($config['flushInterval'] ?? self::DEFAULT_FLUSH_INTERVAL));
    }

    private function batchSize(): int
    {
        $config = $this->hotBufferConfig();
        return \max(1, \min(5000, (int)($config['batchSize'] ?? self::DEFAULT_BATCH_SIZE)));
    }

    private function ttl(): int
    {
        $config = $this->hotBufferConfig();
        return \max(60, (int)($config['ttl'] ?? self::DEFAULT_TTL));
    }

    private function bucketTimestamp(int $time, int $interval): int
    {
        return \intdiv($time, $interval) * $interval;
    }

    private function bucketKey(int $bucketTs, int $websiteId): string
    {
        $shard = \abs((int)\getmypid()) % 16;
        return self::BUCKET_PREFIX . $bucketTs . ':site:' . \max(0, $websiteId) . ':shard:' . $shard;
    }

    /**
     * @param array<string, mixed> $all
     * @return array<int, string>
     */
    private function dueBucketKeys(array $all, int $currentBucket): array
    {
        $keys = [];
        foreach ($all as $key => $_value) {
            if (!\is_string($key) || !\str_starts_with($key, self::BUCKET_PREFIX)) {
                continue;
            }
            $bucketTs = $this->parseBucketTimestamp($key);
            if ($bucketTs !== null && $bucketTs < $currentBucket) {
                $keys[] = $key;
            }
        }
        \sort($keys, SORT_STRING);
        return $keys;
    }

    private function parseBucketTimestamp(string $key): ?int
    {
        if (!\preg_match('/^events:(\d+):/', $key, $matches)) {
            return null;
        }

        return (int)$matches[1];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function eventId(array $envelope): string
    {
        $post = \is_array($envelope['post'] ?? null) ? $envelope['post'] : [];
        $data = \is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $id = (string)($post['event_id'] ?? $post['eventId'] ?? '');
        if ($id !== '') {
            return \substr($id, 0, 80);
        }

        return 'wv-server-' . \substr(\sha1(\json_encode([$post, $data, \microtime(true)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: \uniqid('', true)), 0, 24);
    }

    private function lockToken(): string
    {
        try {
            return \bin2hex(\random_bytes(8));
        } catch (\Throwable) {
            return \str_replace('.', '', \uniqid('lock', true));
        }
    }

    private function recordError(string $phase, \Throwable $throwable): void
    {
        try {
            $memory = $this->memory;
            if ($memory && \method_exists($memory, 'set')) {
                $memory->set(self::NAMESPACE, self::KEY_LAST_ERROR, $phase . ': ' . $throwable->getMessage(), $this->ttl());
            }
        } catch (\Throwable) {
        }
        if (defined('DEV') && DEV) {
            w_log_error('Visitor Pixel hot buffer ' . $phase . ' error: ' . $throwable->getMessage());
        }
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if (\in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (\in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', ''], true)) {
                return false;
            }
        }

        return $default;
    }
}
