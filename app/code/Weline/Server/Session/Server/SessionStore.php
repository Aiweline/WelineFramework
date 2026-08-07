<?php

declare(strict_types=1);

/**
 * WLS Session Server 内存存储
 *
 * 提供高性能的内存 Session 存储，支持：
 * - TTL 过期自动清理
 * - LRU 淘汰（当达到最大 Session 数时）
 * - 定时持久化到文件（重启可恢复）
 * - 按写入次数触发持久化
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Server;

use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;

final class SessionStore
{
    private const POSIX_PERSIST_DIRECTORY_MODE = 0700;

    private const POSIX_PERSIST_FILE_MODE = 0600;

    private const DEFAULT_MAX_PERSIST_BYTES = 268_435_456;

    private const ABSOLUTE_MAX_PERSIST_BYTES = 536_870_912;

    private const DEFAULT_MAX_RECOVERY_DIRECTORY_ENTRIES = 16_000;

    private const MAX_RECOVERY_ARTIFACTS_PER_KIND = 8;

    private const PERSIST_LOCK_SUFFIX = '.persist.lock';

    private const DEFAULT_PERSIST_LOCK_TIMEOUT_SECONDS = 0.25;

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /**
     * Session 存储
     * 结构：[sessionId => ['data' => array, 'expire' => int, 'atime' => int]]
     * - data: Session 数据
     * - expire: 过期时间戳（0 = 永不过期）
     * - atime: 最后访问时间（用于 LRU）
     */
    /** @var array<array-key,array{data:array<array-key,mixed>,expire:int,atime:int}> */
    private array $store = [];

    /**
     * LRU 访问顺序（最近访问的在末尾）
     * 结构：[sessionId => true]
     */
    /** @var array<array-key,true> */
    private array $lruOrder = [];

    /** 最大 Session 数量 */
    private int $maxSessions;

    /** 内存达到高水位后开始主动淘汰；0 表示禁用内存水位保护。 */
    private int $memoryHighWatermarkBytes = 0;

    /** 主动淘汰持续到低水位，给协议编解码和连接缓冲预留空间。 */
    private int $memoryLowWatermarkBytes = 0;

    /** 因内存压力淘汰的 Session 数量。 */
    private int $memoryPressureEvictionCount = 0;

    /** 默认 TTL（秒） */
    private int $defaultTtl;

    /** 持久化文件路径 */
    private string $persistPath;

    /** WLS 2.0 专用目录启用前的兼容快照；仅用于一次性迁移。 */
    private ?string $legacyPersistPath = null;

    /** 单个完整快照和恢复证据的最大字节数 */
    private int $maxPersistBytes;

    /** 持久化命名空间单次恢复最多检查的目录项 */
    private int $maxRecoveryDirectoryEntries;

    /** 等待其他 Session Server 释放持久化锁的单调时限 */
    private float $persistLockTimeout;
    
    /** 持久化失败后的下一次重试时间戳 */
    private int $nextPersistRetryAt = 0;

    /** 持久化失败后的进程内单调重试截止时间 */
    private float $nextPersistRetryMonotonic = 0.0;

    /** 持久化间隔（秒） */
    private int $persistInterval;

    /** 每 N 次写入后持久化（仅统计 set/delete/destroy 等真实写入，不含 get/touch） */
    private int $persistOnWrites;

    /** 上次持久化时间 */
    private int $lastPersistTime = 0;

    /** 上次持久化的进程内单调时间 */
    private float $lastPersistMonotonic = 0.0;

    /** 最小持久化间隔（秒），防止高并发下重复刷盘 */
    private int $persistMinInterval = 5;
    
    /** 持久化失败后重试退避（秒） */
    private int $persistFailureBackoffSec = 5;

    /** 自上次持久化后的写入次数 */
    private int $writesSinceLastPersist = 0;

    /** 是否有未持久化的更改 */
    private bool $dirty = false;

    /** Whether this store should persist to disk. Memory cache sidecars disable this. */
    private bool $persistEnabled = true;

    /** 关键操作后强制持久化 */
    private bool $persistOnCritical;
    
    /** 连续 destroy 计数（用于批量删除检测） */
    private int $destroyCount = 0;
    
    // ==================== 监控指标 ====================
    
    /** 请求计数 */
    /** @var array<string,int> */
    private array $requestCounts = [
        'get' => 0,
        'set' => 0,
        'delete' => 0,
        'destroy' => 0,
    ];
    
    /** 淘汰计数 */
    private int $evictionCount = 0;
    
    /** GC 清理计数 */
    private int $gcCleanedCount = 0;
    
    /** 持久化计数 */
    private int $persistCount = 0;
    
    /** 服务启动的进程内单调时间 */
    private float $startMonotonic;

    /** 操作延迟采样率（10%） */
    private int $metricsSampleRate = 10;

    /** 慢操作阈值（毫秒） */
    /** @var array<string,int> */
    private array $slowOperationThresholds = [
        'get' => 50,
        'set' => 100,
        'delete' => 50,
        'destroy' => 100,
    ];

    /**
     * 构造函数
     *
     * @param array<string,mixed> $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->maxSessions = \max(1, (int)($config['max_sessions'] ?? 50000));
        $this->defaultTtl = (int)($config['session_ttl'] ?? 3600);
        $this->persistInterval = (int)($config['persist_interval'] ?? 30);
        $this->persistOnWrites = (int)($config['persist_on_writes'] ?? 100);
        $this->persistOnCritical = (bool)($config['persist_on_critical'] ?? true);
        $persistEnabled = $config['persist_enabled'] ?? true;
        $this->persistEnabled = \is_bool($persistEnabled)
            ? $persistEnabled
            : !\in_array(\strtolower(\trim((string)$persistEnabled)), ['', '0', 'false', 'no', 'off'], true);
        
        $defaultLegacyPath = \defined('BP')
            ? BP . 'var/session/'
            : '/tmp/wls_session/';
        $persistPathExplicit = \array_key_exists('persist_path', $config)
            && \trim((string)$config['persist_path']) !== '';
        $basePath = (string)($persistPathExplicit
            ? $config['persist_path']
            : \rtrim($defaultLegacyPath, '/\\') . '/.wls-state/');
        $persistFileName = \trim((string)($config['persist_file_name'] ?? 'wls_session_store.dat'));
        if ($persistFileName === '') {
            $persistFileName = 'wls_session_store.dat';
        }
        $persistFileName = \basename(\str_replace('\\', '/', $persistFileName));
        if ($persistFileName === '' || $persistFileName === '.' || $persistFileName === '..') {
            $persistFileName = 'wls_session_store.dat';
        }
        $this->persistPath = \rtrim($basePath, '/\\') . '/' . $persistFileName;
        $legacyBasePath = \trim((string)($config['legacy_persist_path']
            ?? (!$persistPathExplicit ? $defaultLegacyPath : '')));
        if ($legacyBasePath !== '') {
            $legacyPath = \rtrim($legacyBasePath, '/\\') . '/' . $persistFileName;
            if (!\hash_equals(
                \str_replace('\\', '/', $this->persistPath),
                \str_replace('\\', '/', $legacyPath),
            )) {
                $this->legacyPersistPath = $legacyPath;
            }
        }
        $this->maxPersistBytes = \max(1024, \min(
            self::ABSOLUTE_MAX_PERSIST_BYTES,
            (int)($config['persist_max_bytes'] ?? self::DEFAULT_MAX_PERSIST_BYTES),
        ));
        $this->maxRecoveryDirectoryEntries = \max(16, \min(
            self::DEFAULT_MAX_RECOVERY_DIRECTORY_ENTRIES,
            (int)($config['persist_recovery_max_directory_entries']
                ?? self::DEFAULT_MAX_RECOVERY_DIRECTORY_ENTRIES),
        ));
        $this->persistLockTimeout = (float)($config['persist_lock_timeout']
            ?? self::DEFAULT_PERSIST_LOCK_TIMEOUT_SECONDS);
        if (!\is_finite($this->persistLockTimeout)
            || $this->persistLockTimeout <= 0.0
            || $this->persistLockTimeout > 300.0
        ) {
            throw new \InvalidArgumentException(
                'Session persist lock timeout must be within (0, 300] seconds.'
            );
        }
        $this->persistMinInterval = (int)($config['persist_min_interval'] ?? 5);
        if ($this->persistMinInterval < 1) {
            $this->persistMinInterval = 1;
        }
        $this->persistFailureBackoffSec = (int)($config['persist_failure_backoff_sec'] ?? $this->persistMinInterval);
        if ($this->persistFailureBackoffSec < 1) {
            $this->persistFailureBackoffSec = 1;
        }

        $memoryLimitBytes = $this->parseMemoryBytes((string)\ini_get('memory_limit'));
        $configuredHighBytes = \max(0, (int)($config['memory_high_watermark_bytes'] ?? 0));
        $configuredLowBytes = \max(0, (int)($config['memory_low_watermark_bytes'] ?? 0));
        $highRatio = \max(0.50, \min(0.90, (float)($config['memory_high_watermark_ratio'] ?? 0.75)));
        $lowRatio = \max(0.35, \min($highRatio - 0.05, (float)($config['memory_low_watermark_ratio'] ?? 0.60)));
        $this->memoryHighWatermarkBytes = $configuredHighBytes > 0
            ? $configuredHighBytes
            : ($memoryLimitBytes > 0 ? (int)\floor($memoryLimitBytes * $highRatio) : 0);
        $this->memoryLowWatermarkBytes = $configuredLowBytes > 0
            ? $configuredLowBytes
            : ($memoryLimitBytes > 0 ? (int)\floor($memoryLimitBytes * $lowRatio) : 0);
        if ($this->memoryHighWatermarkBytes > 0) {
            $this->memoryLowWatermarkBytes = \max(1, \min(
                $this->memoryLowWatermarkBytes,
                $this->memoryHighWatermarkBytes - 1
            ));
        }
        
        $this->lastPersistTime = \time();
        $this->lastPersistMonotonic = self::monotonicSeconds();
        $this->startMonotonic = $this->lastPersistMonotonic;
    }

    /**
     * 记录日志（直接使用 WlsLogger）
     */
    private function log(string $message): void
    {
        WlsLogger::info_('[SessionStore] ' . $message);
    }

    /**
     * 从持久化文件加载数据
     */
    public function loadFromFile(): bool
    {
        if (!$this->persistEnabled) {
            $this->log('Persistence disabled, starting fresh');
            return false;
        }
        try {
            return $this->withPersistenceLock(function (): bool {
                $this->recoverInterruptedPersistenceLocked();
                $this->migrateLegacyPersistenceLocked();
                $this->recoverInterruptedPersistenceLocked();
                $content = GatewayProjectStateFilesystem::readOptional(
                    $this->persistPath,
                    $this->maxPersistBytes,
                    'WLS Session persistence snapshot',
                );
                if ($content === null) {
                    $this->log('No persist file found, starting fresh');
                    return false;
                }
                $decoded = $this->decodePersistSnapshot($content, true);
                $now = \time();
                $loadedStore = [];
                $loadedLru = [];
                $expired = 0;
                foreach ($decoded['sessions'] as $sessionId => $entry) {
                    if ($entry['expire'] > 0 && $entry['expire'] <= $now) {
                        ++$expired;
                        continue;
                    }
                    $loadedStore[$sessionId] = $entry;
                    $loadedLru[$sessionId] = true;
                }
                $this->store = $loadedStore;
                $this->lruOrder = $loadedLru;
                $this->dirty = false;
                $this->writesSinceLastPersist = 0;
                $this->lastPersistTime = \time();
                $this->lastPersistMonotonic = self::monotonicSeconds();
                $this->nextPersistRetryAt = 0;
                $this->nextPersistRetryMonotonic = 0.0;
                $loaded = \count($loadedStore);
                $legacy = $decoded['incremental'] ? ' (legacy incremental baseline)' : '';
                $this->log("Loaded {$loaded} sessions from file{$legacy}, {$expired} expired");
                return true;
            });
        } catch (\Throwable $throwable) {
            $this->log('Failed to load persist file: ' . $throwable->getMessage());
            return false;
        }
    }

    /**
     * 持久化数据到文件
     */
    public function persistToFile(): bool
    {
        $startTime = self::monotonicSeconds();

        if (!$this->persistEnabled) {
            $this->dirty = false;
            $this->writesSinceLastPersist = 0;
            return true;
        }

        if (!$this->dirty && \count($this->store) === 0) {
            return true;
        }

        try {
            $dataToPersist = $this->completeSnapshotForPersistence();
            $content = \serialize($dataToPersist);
            if ($content === '' || \strlen($content) > $this->maxPersistBytes) {
                throw new \RuntimeException(
                    'Session persistence snapshot exceeds its fixed byte limit.'
                );
            }
        } catch (\Throwable $throwable) {
            $this->markPersistFailure('Failed to serialize persist payload: ' . $throwable->getMessage());
            $this->recordPersistMetric(self::monotonicSeconds() - $startTime, 'failure', 'serialize_error');
            return false;
        }
        try {
            $this->withPersistenceLock(function () use ($content): void {
                $this->recoverInterruptedPersistenceLocked();
                GatewayProjectStateFilesystem::atomicWrite(
                    $this->persistPath,
                    $content,
                    self::POSIX_PERSIST_FILE_MODE,
                );
                $published = GatewayProjectStateFilesystem::read(
                    $this->persistPath,
                    $this->maxPersistBytes,
                    'published WLS Session persistence snapshot',
                );
                if (!\hash_equals(\hash('sha256', $content), \hash('sha256', $published))) {
                    throw new \RuntimeException(
                        'Published Session persistence snapshot failed content validation.'
                    );
                }
            });
        } catch (\Throwable $throwable) {
            $this->markPersistFailure('Failed to atomically publish persist file: ' . $throwable->getMessage());
            $this->recordPersistMetric(
                self::monotonicSeconds() - $startTime,
                'failure',
                'publication_error',
            );
            return false;
        }

        $this->dirty = false;
        $this->lastPersistTime = \time();
        $this->lastPersistMonotonic = self::monotonicSeconds();
        $this->writesSinceLastPersist = 0;
        $this->nextPersistRetryAt = 0;
        $this->nextPersistRetryMonotonic = 0.0;
        $this->persistCount++;
        $this->recordPersistMetric(self::monotonicSeconds() - $startTime, 'success', '');
        $this->log('Persisted ' . \count($dataToPersist) . ' sessions to file');
        return true;
    }

    /**
     * 检查是否需要持久化
     * 节流：两次持久化间隔至少 persistMinInterval 秒，避免高并发下疯狂刷盘。
     */
    public function checkPersist(): bool
    {
        if (!$this->persistEnabled) {
            return false;
        }

        if (!$this->dirty) {
            return false;
        }

        $now = self::monotonicSeconds();
        $elapsed = $now - $this->lastPersistMonotonic;

        if ($this->nextPersistRetryMonotonic > $now) {
            return false;
        }

        // 节流：距上次持久化不足 persistMinInterval 秒则不持久化（避免 get/touch 导致刷屏）
        if ($elapsed < $this->persistMinInterval) {
            return false;
        }

        $needPersist = false;
        if ($this->writesSinceLastPersist >= $this->persistOnWrites) {
            $needPersist = true;
        }
        if ($elapsed >= $this->persistInterval) {
            $needPersist = true;
        }

        if ($needPersist) {
            return $this->persistToFile();
        }

        return false;
    }

    /**
     * 获取 Session 数据
     *
     * @param string $sessionId Session ID
     * @param string|null $key 键名，null 返回整个 Session
     * @return mixed 值或 null
     */
    public function get(string $sessionId, ?string $key = null): mixed
    {
        $shouldSample = \mt_rand(1, 100) <= $this->metricsSampleRate;
        $startTime = $shouldSample ? self::monotonicSeconds() : 0;

        if (!isset($this->store[$sessionId])) {
            if ($shouldSample) {
                $this->recordOperationMetric('get', self::monotonicSeconds() - $startTime, 'miss');
            }
            return $key === null ? [] : null;
        }

        $entry = &$this->store[$sessionId];

        if ($entry['expire'] > 0 && $entry['expire'] < \time()) {
            $this->destroy($sessionId);
            if ($shouldSample) {
                $this->recordOperationMetric('get', self::monotonicSeconds() - $startTime, 'expired');
            }
            return $key === null ? [] : null;
        }

        // Sliding expiration: active sessions should refresh TTL on reads.
        $this->touch($sessionId);

        $result = $key === null ? $entry['data'] : ($entry['data'][$key] ?? null);

        if ($shouldSample) {
            $this->recordOperationMetric('get', self::monotonicSeconds() - $startTime, 'hit');
        }

        return $result;
    }

    /**
     * 获取整个 Session 数据
     */
    public function getAll(string $sessionId): array
    {
        return $this->get($sessionId, null) ?: [];
    }

    /**
     * 设置 Session 数据
     *
     * @param string $sessionId Session ID
     * @param string $key 键名
     * @param mixed $value 值
     * @param int $ttl 过期时间（秒），0 使用默认值
     * @return bool 是否成功
     */
    public function set(string $sessionId, string $key, mixed $value, int $ttl = 0): bool
    {
        $shouldSample = \mt_rand(1, 100) <= $this->metricsSampleRate;
        $startTime = $shouldSample ? self::monotonicSeconds() : 0;

        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $expire = $ttl > 0 ? \time() + $ttl : 0;
        $now = \time();

        if (!isset($this->store[$sessionId])) {
            $this->evictIfNeeded();
            $this->store[$sessionId] = [
                'data' => [],
                'expire' => $expire,
                'atime' => $now,
            ];
            $this->lruOrder[$sessionId] = true;
        } else {
            $this->store[$sessionId]['expire'] = $expire;
            $this->store[$sessionId]['atime'] = $now;
            $this->touchLru($sessionId);
        }

        $this->store[$sessionId]['data'][$key] = $value;
        $this->markDirty();
        $this->evictIfNeeded(true);

        if ($shouldSample) {
            $this->recordOperationMetric('set', self::monotonicSeconds() - $startTime, 'success');
        }

        return true;
    }

    /**
     * 批量设置整个 Session
     */
    public function setAll(string $sessionId, array $data, int $ttl = 0): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $expire = $ttl > 0 ? \time() + $ttl : 0;
        $now = \time();

        if (!isset($this->store[$sessionId])) {
            $this->evictIfNeeded();
        }

        $this->store[$sessionId] = [
            'data' => $data,
            'expire' => $expire,
            'atime' => $now,
        ];
        $this->lruOrder[$sessionId] = true;
        $this->touchLru($sessionId);
        $this->markDirty();
        $this->evictIfNeeded(true);

        return true;
    }

    /**
     * 删除 Session 中的某个键
     */
    public function delete(string $sessionId, string $key): bool
    {
        $shouldSample = \mt_rand(1, 100) <= $this->metricsSampleRate;
        $startTime = $shouldSample ? self::monotonicSeconds() : 0;

        if (!isset($this->store[$sessionId])) {
            if ($shouldSample) {
                $this->recordOperationMetric('delete', self::monotonicSeconds() - $startTime, 'miss');
            }
            return false;
        }

        if (!isset($this->store[$sessionId]['data'][$key])) {
            if ($shouldSample) {
                $this->recordOperationMetric('delete', self::monotonicSeconds() - $startTime, 'key_not_found');
            }
            return false;
        }

        unset($this->store[$sessionId]['data'][$key]);
        $this->markDirty();

        if ($shouldSample) {
            $this->recordOperationMetric('delete', self::monotonicSeconds() - $startTime, 'success');
        }

        return true;
    }

    /**
     * 销毁整个 Session
     */
    public function destroy(string $sessionId): bool
    {
        $shouldSample = \mt_rand(1, 100) <= $this->metricsSampleRate;
        $startTime = $shouldSample ? self::monotonicSeconds() : 0;

        if (!isset($this->store[$sessionId])) {
            if ($shouldSample) {
                $this->recordOperationMetric('destroy', self::monotonicSeconds() - $startTime, 'miss');
            }
            return false;
        }

        unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
        $this->markDirty();

        $this->destroyCount++;
        if ($this->persistOnCritical && $this->destroyCount >= 10) {
            $this->destroyCount = 0;
            $this->persistToFile();
        }

        if ($shouldSample) {
            $this->recordOperationMetric('destroy', self::monotonicSeconds() - $startTime, 'success');
        }

        return true;
    }
    
    // ==================== 原子操作 ====================
    
    /**
     * 原子递增
     * 
     * @param string $sessionId Session ID
     * @param string $key 键名
     * @param int $delta 增量（可为负数）
     * @param int $ttl TTL
     * @return int|null 新值，失败返回 null
     */
    public function increment(string $sessionId, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        $this->ensureSession($sessionId, $ttl);
        
        $current = $this->store[$sessionId]['data'][$key] ?? 0;
        if (!\is_numeric($current)) {
            return null;
        }
        
        $newValue = (int)$current + $delta;
        $this->store[$sessionId]['data'][$key] = $newValue;
        $this->store[$sessionId]['atime'] = \time();
        $this->touchLru($sessionId);
        $this->markDirty();
        
        return $newValue;
    }
    
    /**
     * 原子递减
     */
    public function decrement(string $sessionId, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        return $this->increment($sessionId, $key, -$delta, $ttl);
    }
    
    /**
     * 原子追加（数组元素或字符串）
     * 
     * @param string $sessionId Session ID
     * @param string $key 键名
     * @param mixed $value 要追加的值
     * @param int $ttl TTL
     * @return bool 是否成功
     */
    public function append(string $sessionId, string $key, mixed $value, int $ttl = 0): bool
    {
        $this->ensureSession($sessionId, $ttl);
        
        $current = $this->store[$sessionId]['data'][$key] ?? [];
        
        if (\is_array($current)) {
            $current[] = $value;
        } elseif (\is_string($current)) {
            $current .= (string)$value;
        } else {
            return false;
        }
        
        $this->store[$sessionId]['data'][$key] = $current;
        $this->store[$sessionId]['atime'] = \time();
        $this->touchLru($sessionId);
        $this->markDirty();
        
        return true;
    }
    
    /**
     * 比较并设置（CAS）
     * 
     * @param string $sessionId Session ID
     * @param string $key 键名
     * @param mixed $expected 期望的当前值
     * @param mixed $newValue 新值
     * @param int $ttl TTL
     * @return bool 是否成功（当前值等于期望值时才设置）
     */
    public function compareAndSet(string $sessionId, string $key, mixed $expected, mixed $newValue, int $ttl = 0): bool
    {
        $this->ensureSession($sessionId, $ttl);
        
        $current = $this->store[$sessionId]['data'][$key] ?? null;
        
        if ($current !== $expected) {
            return false;
        }
        
        $this->store[$sessionId]['data'][$key] = $newValue;
        $this->store[$sessionId]['atime'] = \time();
        $this->touchLru($sessionId);
        $this->markDirty();
        
        return true;
    }
    
    /**
     * 确保 Session 存在
     */
    private function ensureSession(string $sessionId, int $ttl): void
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $expire = $ttl > 0 ? \time() + $ttl : 0;
        $now = \time();
        
        if (!isset($this->store[$sessionId])) {
            $this->evictIfNeeded();
            $this->store[$sessionId] = [
                'data' => [],
                'expire' => $expire,
                'atime' => $now,
            ];
            $this->lruOrder[$sessionId] = true;
        } else {
            $this->store[$sessionId]['expire'] = $expire;
        }
    }

    /**
     * 检查 Session 是否存在
     */
    public function exists(string $sessionId): bool
    {
        if (!isset($this->store[$sessionId])) {
            return false;
        }

        $entry = $this->store[$sessionId];
        if ($entry['expire'] > 0 && $entry['expire'] < \time()) {
            $this->destroy($sessionId);
            return false;
        }

        return true;
    }

    /**
     * 检查 Session 中的指定键是否存在。
     */
    public function existsKey(string $sessionId, string $key): bool
    {
        if ($key === '' || !isset($this->store[$sessionId])) {
            return false;
        }

        $entry = $this->store[$sessionId];
        if ($entry['expire'] > 0 && $entry['expire'] < \time()) {
            $this->destroy($sessionId);
            return false;
        }

        return \array_key_exists($key, $entry['data']);
    }

    /**
     * 批量获取多个键，单次请求仅刷新一次 TTL。
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function mget(string $sessionId, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[(string)$key] = null;
        }

        if (!isset($this->store[$sessionId])) {
            return $result;
        }

        $entry = $this->store[$sessionId];
        if ($entry['expire'] > 0 && $entry['expire'] < \time()) {
            $this->destroy($sessionId);
            return $result;
        }

        $this->touch($sessionId);
        foreach ($keys as $key) {
            $key = (string)$key;
            $result[$key] = $entry['data'][$key] ?? null;
        }

        return $result;
    }

    /**
     * 批量设置多个键，单次请求仅更新一次 TTL 并标记一次 dirty。
     *
     * @param array<string, mixed> $kv
     */
    public function mset(string $sessionId, array $kv, int $ttl = 0): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $expire = $ttl > 0 ? \time() + $ttl : 0;
        $now = \time();

        if (!isset($this->store[$sessionId])) {
            $this->evictIfNeeded();
            $this->store[$sessionId] = [
                'data' => [],
                'expire' => $expire,
                'atime' => $now,
            ];
            $this->lruOrder[$sessionId] = true;
        } else {
            $this->store[$sessionId]['expire'] = $expire;
            $this->store[$sessionId]['atime'] = $now;
            $this->touchLru($sessionId);
        }

        foreach ($kv as $key => $value) {
            $this->store[$sessionId]['data'][(string)$key] = $value;
        }

        $this->markDirty();
        $this->evictIfNeeded(true);
        return true;
    }

    /**
     * 刷新 Session 过期时间（滑动 TTL）
     * 不调用 markDirty()，避免每次 get 都算“写入”导致频繁持久化刷屏。
     */
    public function touch(string $sessionId, int $ttl = 0): bool
    {
        if (!isset($this->store[$sessionId])) {
            return false;
        }

        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $this->store[$sessionId]['expire'] = $ttl > 0 ? \time() + $ttl : 0;
        $this->store[$sessionId]['atime'] = \time();
        $this->touchLru($sessionId);
        // 不 markDirty：仅刷新内存中的 TTL，定时/按写入次数持久化时会带上最新状态

        return true;
    }

    /**
     * 垃圾回收
     *
     * @param int $maxLifetime 最大生存时间（秒），0 使用默认值
     * @return int 清理的 Session 数量
     */
    public function gc(int $maxLifetime = 0): int
    {
        $startTime = self::monotonicSeconds();
        $now = \time();
        $cleaned = 0;

        foreach ($this->store as $sessionId => $entry) {
            if ($this->isEntryExpired($entry, $now, $maxLifetime)) {
                unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->gcCleanedCount += $cleaned;
            $this->markDirty();
            $this->log("GC cleaned {$cleaned} expired sessions");
        }

        // 记录 GC 耗时
        $durationMs = (self::monotonicSeconds() - $startTime) * 1000;
        \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->recordHistogram(
            'wls_store_gc_duration_ms',
            $durationMs,
            []
        );

        return $cleaned;
    }

    /**
     * 按指定 Session ID 子集执行 GC（用于按域隔离清理）。
     *
     * @param string[] $sessionIds
     */
    public function gcBySessionIds(array $sessionIds, int $maxLifetime = 0): int
    {
        if (empty($sessionIds)) {
            return 0;
        }
        $now = \time();
        $cleaned = 0;
        foreach ($sessionIds as $sessionId) {
            if (!isset($this->store[$sessionId])) {
                continue;
            }
            $entry = $this->store[$sessionId];
            if (!$this->isEntryExpired($entry, $now, $maxLifetime)) {
                continue;
            }
            unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
            $cleaned++;
        }
        if ($cleaned > 0) {
            $this->gcCleanedCount += $cleaned;
            $this->markDirty();
            $this->log("Scoped GC cleaned {$cleaned} sessions");
        }
        return $cleaned;
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        return [
            'session_count' => \count($this->store),
            'max_sessions' => $this->maxSessions,
            'dirty' => $this->dirty,
            'writes_since_persist' => $this->writesSinceLastPersist,
            'last_persist_time' => $this->lastPersistTime,
            'persist_interval' => $this->persistInterval,
            'persist_on_writes' => $this->persistOnWrites,
            'memory_usage' => \memory_get_usage(true),
            'memory_usage_live' => \memory_get_usage(false),
            'memory_high_watermark_bytes' => $this->memoryHighWatermarkBytes,
            'memory_low_watermark_bytes' => $this->memoryLowWatermarkBytes,
            'memory_pressure_eviction_count' => $this->memoryPressureEvictionCount,
            'uptime' => \max(0.0, self::monotonicSeconds() - $this->startMonotonic),
            'request_counts' => $this->requestCounts,
            'eviction_count' => $this->evictionCount,
            'gc_cleaned_count' => $this->gcCleanedCount,
            'persist_count' => $this->persistCount,
            'persist_path' => $this->persistPath,
            'persist_retry_at' => $this->nextPersistRetryAt,
        ];
    }
    
    /**
     * 获取 Prometheus 格式指标
     */
    public function getPrometheusMetrics(): string
    {
        $lines = [];
        $prefix = 'wls_session_';
        
        $lines[] = "# HELP {$prefix}sessions_total Current number of sessions";
        $lines[] = "# TYPE {$prefix}sessions_total gauge";
        $lines[] = "{$prefix}sessions_total " . \count($this->store);
        
        $lines[] = "# HELP {$prefix}sessions_max Maximum number of sessions";
        $lines[] = "# TYPE {$prefix}sessions_max gauge";
        $lines[] = "{$prefix}sessions_max {$this->maxSessions}";
        
        $lines[] = "# HELP {$prefix}memory_bytes Memory usage in bytes";
        $lines[] = "# TYPE {$prefix}memory_bytes gauge";
        $lines[] = "{$prefix}memory_bytes " . \memory_get_usage(true);
        
        $lines[] = "# HELP {$prefix}uptime_seconds Uptime in seconds";
        $lines[] = "# TYPE {$prefix}uptime_seconds counter";
        $lines[] = "{$prefix}uptime_seconds "
            . \max(0.0, self::monotonicSeconds() - $this->startMonotonic);
        
        $lines[] = "# HELP {$prefix}requests_total Total requests by operation";
        $lines[] = "# TYPE {$prefix}requests_total counter";
        foreach ($this->requestCounts as $op => $count) {
            $lines[] = "{$prefix}requests_total{op=\"{$op}\"} {$count}";
        }
        
        $lines[] = "# HELP {$prefix}evictions_total Total evicted sessions";
        $lines[] = "# TYPE {$prefix}evictions_total counter";
        $lines[] = "{$prefix}evictions_total {$this->evictionCount}";
        
        $lines[] = "# HELP {$prefix}gc_cleaned_total Total GC cleaned sessions";
        $lines[] = "# TYPE {$prefix}gc_cleaned_total counter";
        $lines[] = "{$prefix}gc_cleaned_total {$this->gcCleanedCount}";
        
        $lines[] = "# HELP {$prefix}persists_total Total persist operations";
        $lines[] = "# TYPE {$prefix}persists_total counter";
        $lines[] = "{$prefix}persists_total {$this->persistCount}";

        // 合并全局指标收集器的指标
        $lines[] = \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->exportPrometheus();

        return \implode("\n", $lines) . "\n";
    }
    
    /**
     * 增加请求计数
     */
    public function incrementRequestCount(string $op): void
    {
        if (isset($this->requestCounts[$op])) {
            $this->requestCounts[$op]++;
        }
    }

    /**
     * 更新 LRU 顺序（将 sessionId 移动到末尾）
     */
    private function touchLru(string $sessionId): void
    {
        unset($this->lruOrder[$sessionId]);
        $this->lruOrder[$sessionId] = true;
        $this->store[$sessionId]['atime'] = \time();
    }

    /**
     * LRU 淘汰（如果达到最大 Session 数）
     *
     * 优化策略：优先淘汰即将过期（expire - now < 10分钟）的 Session，
     * 其次淘汰最久未访问的 Session
     */
    private function evictIfNeeded(bool $memoryOnly = false): void
    {
        $sessionCount = \count($this->store);
        $countPressure = !$memoryOnly && $sessionCount >= $this->maxSessions;
        $memoryUsage = \memory_get_usage(false);
        $memoryPressure = $this->memoryHighWatermarkBytes > 0
            && $memoryUsage >= $this->memoryHighWatermarkBytes;
        if (!$countPressure && !$memoryPressure) {
            return;
        }

        $startTime = self::monotonicSeconds();
        $toEvict = $countPressure ? \max(1, (int)\ceil($this->maxSessions * 0.1)) : 0;
        $evicted = 0;
        while ($this->lruOrder !== []) {
            $sessionId = \array_key_first($this->lruOrder);
            unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
            $evicted++;

            $countSatisfied = !$countPressure || $evicted >= $toEvict;
            $memorySatisfied = !$memoryPressure
                || \memory_get_usage(false) <= $this->memoryLowWatermarkBytes;
            if ($countSatisfied && $memorySatisfied) {
                break;
            }
        }

        if ($evicted > 0) {
            $this->evictionCount += $evicted;
            if ($memoryPressure) {
                $this->memoryPressureEvictionCount += $evicted;
            }
            $reason = $memoryPressure && $countPressure
                ? 'memory+count'
                : ($memoryPressure ? 'memory' : 'count');
            $this->log("LRU evicted {$evicted} sessions (reason={$reason})");
            $this->markDirty();

            // 记录淘汰耗时
            $durationMs = (self::monotonicSeconds() - $startTime) * 1000;
            \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->recordHistogram(
                'wls_store_lru_eviction_duration_ms',
                $durationMs,
                []
            );
        }
    }

    /**
     * 在读取协议帧或维护阶段释放内存，避免到达 PHP memory_limit 后才被动崩溃。
     */
    public function relieveMemoryPressure(): int
    {
        $before = $this->evictionCount;
        $this->evictIfNeeded(true);

        return $this->evictionCount - $before;
    }

    private function parseMemoryBytes(string $value): int
    {
        $value = \strtoupper(\trim($value));
        if ($value === '' || $value === '-1') {
            return 0;
        }
        if (!\preg_match('/^(\d+)([KMG]?)$/', $value, $matches)) {
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

    /** 标记数据已更改。持久化始终发布完整快照。 */
    private function markDirty(): void
    {
        $this->dirty = true;
        $this->writesSinceLastPersist++;
    }

    /**
     * 判断条目是否过期。
     */
    private function isEntryExpired(array $entry, int $now, int $maxLifetime): bool
    {
        if (($entry['expire'] ?? 0) > 0 && (int)$entry['expire'] < $now) {
            return true;
        }
        if ($maxLifetime > 0 && ((int)($entry['atime'] ?? 0) + $maxLifetime) < $now) {
            return true;
        }
        return false;
    }

    /**
     * 获取所有 Session ID（用于调试）
     */
    public function getAllSessionIds(): array
    {
        return \array_keys($this->store);
    }

    /**
     * 强制持久化
     */
    public function forcePersist(): bool
    {
        $this->dirty = true;
        return $this->persistToFile();
    }

    /**
     * @return array<array-key,array{data:array<array-key,mixed>,expire:int,atime:int}>
     */
    private function completeSnapshotForPersistence(): array
    {
        $now = \time();
        $expired = 0;
        foreach ($this->store as $sessionId => $entry) {
            if ($entry['expire'] > 0 && $entry['expire'] <= $now) {
                unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
                ++$expired;
            }
        }
        if ($expired > 0) {
            $this->gcCleanedCount += $expired;
        }

        return $this->store;
    }

    /**
     * @return array{
     *   sessions:array<array-key,array{data:array<array-key,mixed>,expire:int,atime:int}>,
     *   incremental:bool
     * }
     */
    private function decodePersistSnapshot(string $content, bool $allowLegacyIncremental): array
    {
        if ($content === '' || \strlen($content) > $this->maxPersistBytes) {
            throw new \RuntimeException('Session persistence snapshot has an invalid size.');
        }
        try {
            $decoded = @\unserialize($content, ['allowed_classes' => true]);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Session persistence snapshot cannot be decoded.',
                0,
                $throwable,
            );
        }
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Session persistence snapshot must contain an array.');
        }

        $incremental = isset($decoded['incremental']) && $decoded['incremental'] === true;
        if ($incremental) {
            if (!$allowLegacyIncremental
                || \count($decoded) !== 2
                || !\array_key_exists('data', $decoded)
                || !\is_array($decoded['data'])
            ) {
                throw new \RuntimeException(
                    'Legacy incremental Session persistence cannot be used as a complete snapshot.'
                );
            }
            $decoded = $decoded['data'];
        }
        if (\count($decoded) > $this->maxSessions) {
            throw new \RuntimeException(
                'Session persistence snapshot exceeds the configured Session count limit.'
            );
        }

        $sessions = [];
        $now = \time();
        foreach ($decoded as $sessionId => $entry) {
            $sessionId = (string)$sessionId;
            if ($sessionId === '' || \strlen($sessionId) > 8192 || !\is_array($entry)) {
                throw new \RuntimeException('Session persistence snapshot contains an invalid Session ID or entry.');
            }
            if (!\array_key_exists('data', $entry)
                || !\is_array($entry['data'])
                || !\array_key_exists('expire', $entry)
                || !\is_int($entry['expire'])
                || $entry['expire'] < 0
            ) {
                throw new \RuntimeException(
                    'Session persistence snapshot contains an invalid Session entry.'
                );
            }
            $atime = $entry['atime'] ?? $now;
            if (!\is_int($atime) || $atime < 0) {
                throw new \RuntimeException(
                    'Session persistence snapshot contains an invalid access time.'
                );
            }
            $sessions[$sessionId] = [
                'data' => $entry['data'],
                'expire' => $entry['expire'],
                'atime' => $atime,
            ];
        }

        return ['sessions' => $sessions, 'incremental' => $incremental];
    }

    /**
     * @template TResult
     * @param \Closure():TResult $operation
     * @return TResult
     */
    private function withPersistenceLock(\Closure $operation): mixed
    {
        if (!$this->ensurePersistDirectory()) {
            throw new \RuntimeException('Session persistence directory is unavailable or unsafe.');
        }
        $lockPath = $this->persistPath . self::PERSIST_LOCK_SUFFIX;
        $pid = (int)\getmypid();
        $lock = VerifiedPersistentFileLock::acquire(
            $lockPath,
            $this->persistLockTimeout,
            fn (): array => [
                'pid' => $pid,
                'purpose' => 'wls-session-store-persistence',
                'target' => \basename($this->persistPath),
                'started_at' => \date(DATE_ATOM),
            ],
        );
        if (!\is_resource($lock)) {
            throw new \RuntimeException(
                'Unable to acquire the verified Session persistence lock within '
                . \number_format($this->persistLockTimeout, 3, '.', '')
                . ' seconds.'
            );
        }
        try {
            return $operation();
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    private function recoverInterruptedPersistenceLocked(): void
    {
        $selected = $this->persistenceRecoverySnapshot();
        if ($selected['artifacts'] === []) {
            return;
        }

        $targetIdentity = $selected['target_identity'];
        if (\is_array($targetIdentity)) {
            $targetRaw = GatewayProjectStateFilesystem::read(
                $this->persistPath,
                $this->maxPersistBytes,
                'Session persistence recovery paired target',
            );
            $this->decodePersistSnapshot($targetRaw, true);
            $targetAfterValidation = @\lstat($this->persistPath);
            if (!\is_array($targetAfterValidation)
                || !$this->sameFileState($targetIdentity, $targetAfterValidation)
            ) {
                throw new \RuntimeException(
                    'Session persistence recovery target changed during validation.'
                );
            }
            $rechecked = $this->persistenceRecoverySnapshot();
            $this->assertSameRecoverySnapshot($selected, $rechecked);
            if (!$this->sameOptionalFileState(
                $targetAfterValidation,
                $rechecked['target_identity'],
            )) {
                throw new \RuntimeException(
                    'Session persistence recovery target changed before cleanup.'
                );
            }
            $this->removeRecoveryArtifacts(
                $rechecked['artifacts'],
                $targetAfterValidation,
            );
            return;
        }
        if (\file_exists($this->persistPath) || \is_link($this->persistPath)) {
            throw new \RuntimeException(
                'Session persistence recovery target is indeterminate or unsafe.'
            );
        }

        $backups = [];
        $legacyStaging = [];
        foreach ($selected['artifacts'] as $artifact) {
            if ($artifact['kind'] === 'atomic backup') {
                $backups[] = $artifact;
            } elseif ($artifact['kind'] === 'legacy staging') {
                $legacyStaging[] = $artifact;
            }
        }
        if (\count($backups) > 1) {
            throw new \RuntimeException(
                'Session persistence recovery has ambiguous backups for one missing target.'
            );
        }

        $restore = $backups[0] ?? null;
        $allowIncremental = true;
        if (!\is_array($restore) && $legacyStaging !== []) {
            if (\count($legacyStaging) > 1) {
                throw new \RuntimeException(
                    'Session persistence recovery has ambiguous legacy staging files for one missing target.'
                );
            }
            $restore = $legacyStaging[0];
            $allowIncremental = false;
        }
        $restoreRaw = null;
        $restoreSha256 = null;
        if (\is_array($restore)) {
            $restoreRaw = GatewayProjectStateFilesystem::read(
                $restore['path'],
                $this->maxPersistBytes,
                'Session persistence retained recovery snapshot',
            );
            $this->decodePersistSnapshot($restoreRaw, $allowIncremental);
            $restoreSha256 = \hash('sha256', $restoreRaw);
        }

        $rechecked = $this->persistenceRecoverySnapshot();
        $this->assertSameRecoverySnapshot($selected, $rechecked);
        if (\is_array($rechecked['target_identity'])
            || \file_exists($this->persistPath)
            || \is_link($this->persistPath)
        ) {
            throw new \RuntimeException(
                'Session persistence recovery target appeared before recovery.'
            );
        }
        if (!\is_array($restore)) {
            $this->removeRecoveryArtifacts($rechecked['artifacts'], null);
            return;
        }

        $current = $rechecked['artifacts'][$restore['path']] ?? null;
        if (!\is_array($current)
            || !\is_string($restoreRaw)
            || !\is_string($restoreSha256)
            || !$this->sameFileState($restore['identity'], $current['identity'])
        ) {
            throw new \RuntimeException(
                'Session persistence retained recovery snapshot changed before restoration.'
            );
        }
        $currentRaw = GatewayProjectStateFilesystem::read(
            $current['path'],
            $this->maxPersistBytes,
            'Session persistence retained recovery snapshot',
        );
        $this->decodePersistSnapshot($currentRaw, $allowIncremental);
        if (!\hash_equals($restoreSha256, \hash('sha256', $currentRaw))) {
            throw new \RuntimeException(
                'Session persistence retained recovery snapshot contents changed before restoration.'
            );
        }
        $this->restoreMissingPersistTarget($current, $restoreSha256);
        $this->recoverInterruptedPersistenceLocked();
    }

    /**
     * Move the one legacy committed snapshot out of the high-cardinality PHP
     * Session directory. The persistent retirement marker prevents an old,
     * later-reappearing file from becoming authoritative after migration.
     */
    private function migrateLegacyPersistenceLocked(): void
    {
        $legacyPath = $this->legacyPersistPath;
        if ($legacyPath === null) {
            return;
        }
        $legacyDirectory = \dirname($legacyPath);
        $retirementMarker = $this->legacyRetirementMarkerPath($legacyPath);
        $markerExists = $this->validateLegacyRetirementMarker($retirementMarker);

        $current = GatewayProjectStateFilesystem::readOptional(
            $this->persistPath,
            $this->maxPersistBytes,
            'WLS Session dedicated persistence snapshot',
        );
        if ($current !== null) {
            $this->decodePersistSnapshot($current, true);
            if (!$markerExists) {
                $this->createLegacyRetirementMarker($retirementMarker);
            }
            $this->retireLegacyPersistTargetUnderLock($legacyPath);
            return;
        }
        if ($markerExists) {
            return;
        }
        if (!\is_dir($legacyDirectory)) {
            $this->createLegacyRetirementMarker($retirementMarker);
            return;
        }

        $legacyLock = VerifiedPersistentFileLock::acquire(
            $legacyPath . self::PERSIST_LOCK_SUFFIX,
            $this->persistLockTimeout,
            static fn (): array => [
                'pid' => (int) \getmypid(),
                'purpose' => 'wls-session-store-legacy-migration',
                'target' => \basename($legacyPath),
                'started_at' => \date(DATE_ATOM),
            ],
        );
        if (!\is_resource($legacyLock)) {
            throw new \RuntimeException(
                'Unable to acquire the verified legacy Session persistence migration lock.'
            );
        }
        try {
            if ($this->validateLegacyRetirementMarker($retirementMarker)) {
                return;
            }
            $legacyIdentity = @\lstat($legacyPath);
            $legacyRaw = GatewayProjectStateFilesystem::readOptional(
                $legacyPath,
                $this->maxPersistBytes,
                'legacy WLS Session persistence snapshot',
            );
            if ($legacyRaw === null) {
                $this->createLegacyRetirementMarker($retirementMarker);
                return;
            }
            if (!\is_array($legacyIdentity)) {
                throw new \RuntimeException(
                    'Legacy Session persistence snapshot identity is unavailable.'
                );
            }
            $this->decodePersistSnapshot($legacyRaw, true);
            $legacyAfterRead = @\lstat($legacyPath);
            if (!\is_array($legacyAfterRead)
                || !$this->sameFileState($legacyIdentity, $legacyAfterRead)
            ) {
                throw new \RuntimeException(
                    'Legacy Session persistence snapshot changed during migration.'
                );
            }

            GatewayProjectStateFilesystem::atomicWrite(
                $this->persistPath,
                $legacyRaw,
                self::POSIX_PERSIST_FILE_MODE,
            );
            $migrated = GatewayProjectStateFilesystem::read(
                $this->persistPath,
                $this->maxPersistBytes,
                'migrated WLS Session persistence snapshot',
            );
            $this->decodePersistSnapshot($migrated, true);
            if (!\hash_equals(\hash('sha256', $legacyRaw), \hash('sha256', $migrated))) {
                throw new \RuntimeException(
                    'Migrated Session persistence snapshot failed digest validation.'
                );
            }
            $this->createLegacyRetirementMarker($retirementMarker);
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $legacyPath,
                    'retired legacy Session persistence snapshot',
                    $legacyAfterRead,
                );
            } catch (\Throwable $throwable) {
                // The durable marker is already committed. Preserve a target
                // that changed rather than deleting an unverified file.
                $this->log('Legacy Session persistence retirement preserved evidence: '
                    . $throwable->getMessage());
            }
        } finally {
            @\flock($legacyLock, LOCK_UN);
            @\fclose($legacyLock);
        }
    }

    private function legacyRetirementMarkerPath(string $legacyPath): string
    {
        return \dirname($this->persistPath)
            . DIRECTORY_SEPARATOR
            . '.legacy-retired-'
            . \substr(\hash('sha256', \str_replace('\\', '/', $legacyPath)), 0, 24);
    }

    private function validateLegacyRetirementMarker(string $path): bool
    {
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'Session persistence legacy retirement marker is indeterminate.'
                );
            }
            return false;
        }
        if (\is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)($status['mode'] ?? 0)) & 0777) !== self::POSIX_PERSIST_DIRECTORY_MODE))
        ) {
            throw new \RuntimeException(
                'Session persistence legacy retirement marker is unsafe.'
            );
        }

        return true;
    }

    private function createLegacyRetirementMarker(string $path): void
    {
        if ($this->validateLegacyRetirementMarker($path)) {
            return;
        }
        if (!@\mkdir($path, self::POSIX_PERSIST_DIRECTORY_MODE)
            && !$this->validateLegacyRetirementMarker($path)
        ) {
            throw new \RuntimeException(
                'Unable to commit the Session persistence legacy retirement marker.'
            );
        }
        if (!$this->validateLegacyRetirementMarker($path)) {
            throw new \RuntimeException(
                'Session persistence legacy retirement marker failed verification.'
            );
        }
        GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
    }

    private function retireLegacyPersistTargetBestEffort(string $legacyPath): void
    {
        $identity = @\lstat($legacyPath);
        if (!\is_array($identity)) {
            return;
        }
        try {
            GatewayProjectStateFilesystem::removeRegular(
                $legacyPath,
                'retired legacy Session persistence snapshot',
                $identity,
            );
        } catch (\Throwable $throwable) {
            $this->log('Legacy Session persistence retirement preserved evidence: '
                . $throwable->getMessage());
        }
    }

    private function retireLegacyPersistTargetUnderLock(string $legacyPath): void
    {
        if (!\is_dir(\dirname($legacyPath))) {
            return;
        }
        $lock = VerifiedPersistentFileLock::acquire(
            $legacyPath . self::PERSIST_LOCK_SUFFIX,
            $this->persistLockTimeout,
            static fn (): array => [
                'pid' => (int) \getmypid(),
                'purpose' => 'wls-session-store-legacy-retirement',
                'target' => \basename($legacyPath),
                'started_at' => \date(DATE_ATOM),
            ],
        );
        if (!\is_resource($lock)) {
            $this->log('Legacy Session persistence retirement deferred because its lock is held.');
            return;
        }
        try {
            $this->retireLegacyPersistTargetBestEffort($legacyPath);
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @return array{
     *   directory_identity:array<string|int,mixed>,
     *   target_identity:array<string|int,mixed>|null,
     *   artifacts:array<string,array{
     *     path:string,kind:string,identity:array<string|int,mixed>
     *   }>
     * }
     */
    private function persistenceRecoverySnapshot(): array
    {
        $directory = \dirname($this->persistPath);
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || ((((int)$directoryBefore['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Session persistence recovery directory is unsafe.'
            );
        }
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the Session persistence recovery directory.'
            );
        }
        $targetLeaf = \basename($this->persistPath);
        $lockLeaf = $targetLeaf . self::PERSIST_LOCK_SUFFIX;
        $foldedTargetLeaf = \strtolower($targetLeaf);
        $foldedLockLeaf = \strtolower($lockLeaf);
        $quotedTarget = \preg_quote($targetLeaf, '/');
        $legacyPattern = '/\A' . $quotedTarget
            . '\.tmp\.[1-9][0-9]{0,18}\.[a-f0-9]{14}[0-9]{8}\z/D';
        $stagingPattern = '/\A' . $quotedTarget . '\.tmp-[a-f0-9]{24}\z/D';
        $backupPattern = '/\A' . $quotedTarget . '\.wls-backup-[a-f0-9]{16}\z/D';
        $foldedLegacyPrefix = $foldedTargetLeaf . '.tmp.';
        $foldedStagingPrefix = $foldedTargetLeaf . '.tmp-';
        $foldedBackupPrefix = $foldedTargetLeaf . '.wls-backup-';
        $artifacts = [];
        $counts = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$visited > $this->maxRecoveryDirectoryEntries) {
                    throw new \RuntimeException(
                        'Session persistence recovery directory exceeds its fixed raw entry quota.'
                    );
                }
                $foldedLeaf = \strtolower($leaf);
                if (\hash_equals($foldedLockLeaf, $foldedLeaf)) {
                    if (!\hash_equals($lockLeaf, $leaf)) {
                        throw new \RuntimeException(
                            'Session persistence lock has a non-canonical case alias.'
                        );
                    }
                    continue;
                }
                if (\hash_equals($foldedTargetLeaf, $foldedLeaf)) {
                    if (!\hash_equals($targetLeaf, $leaf)) {
                        throw new \RuntimeException(
                            'Session persistence target has a non-canonical case alias.'
                        );
                    }
                    continue;
                }

                $kind = '';
                $reserved = false;
                if (\str_starts_with($foldedLeaf, $foldedLegacyPrefix)) {
                    $reserved = true;
                    if (\preg_match($legacyPattern, $leaf) === 1) {
                        $kind = 'legacy staging';
                    }
                } elseif (\str_starts_with($foldedLeaf, $foldedStagingPrefix)) {
                    $reserved = true;
                    if (\preg_match($stagingPattern, $leaf) === 1) {
                        $kind = 'atomic staging';
                    }
                } elseif (\str_starts_with($foldedLeaf, $foldedBackupPrefix)) {
                    $reserved = true;
                    if (\preg_match($backupPattern, $leaf) === 1) {
                        $kind = 'atomic backup';
                    }
                }
                if (!$reserved) {
                    continue;
                }
                if ($kind === '') {
                    throw new \RuntimeException(
                        $leaf !== $foldedLeaf
                            ? 'Session persistence recovery contains a non-canonical case alias.'
                            : 'Session persistence recovery contains a malformed reserved leaf.'
                    );
                }
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                if ($counts[$kind] > self::MAX_RECOVERY_ARTIFACTS_PER_KIND) {
                    throw new \RuntimeException(
                        'Session persistence recovery artifact quota is exhausted.'
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                $before = @\lstat($path);
                if (!\is_array($before)) {
                    throw new \RuntimeException(
                        'Session persistence recovery artifact is indeterminate.'
                    );
                }
                GatewayProjectStateFilesystem::size(
                    $path,
                    $this->maxPersistBytes,
                    'Session persistence recovery artifact',
                );
                $after = @\lstat($path);
                if (!\is_array($after) || !$this->sameFileState($before, $after)) {
                    throw new \RuntimeException(
                        'Session persistence recovery artifact changed during inspection.'
                    );
                }
                $artifacts[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'identity' => $after,
                ];
            }
        } finally {
            @\closedir($handle);
        }
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || !$this->sameDirectoryIdentity($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                'Session persistence recovery directory changed during inspection.'
            );
        }
        $targetIdentity = @\lstat($this->persistPath);
        if (!\is_array($targetIdentity)
            && (\file_exists($this->persistPath) || \is_link($this->persistPath))
        ) {
            throw new \RuntimeException(
                'Session persistence recovery target is indeterminate.'
            );
        }
        \ksort($artifacts, SORT_STRING);

        return [
            'directory_identity' => $directoryAfter,
            'target_identity' => \is_array($targetIdentity) ? $targetIdentity : null,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @param array{
     *   directory_identity:array<string|int,mixed>,
     *   target_identity:array<string|int,mixed>|null,
     *   artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>
     * } $before
     * @param array{
     *   directory_identity:array<string|int,mixed>,
     *   target_identity:array<string|int,mixed>|null,
     *   artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>
     * } $after
     */
    private function assertSameRecoverySnapshot(array $before, array $after): void
    {
        if (!$this->sameDirectoryIdentity(
            $before['directory_identity'],
            $after['directory_identity'],
        )
            || !$this->sameOptionalFileState(
                $before['target_identity'],
                $after['target_identity'],
            )
            || \array_keys($before['artifacts']) !== \array_keys($after['artifacts'])
        ) {
            throw new \RuntimeException(
                'Session persistence recovery namespace changed before mutation.'
            );
        }
        foreach ($before['artifacts'] as $path => $artifact) {
            $current = $after['artifacts'][$path] ?? null;
            if (!\is_array($current)
                || !\hash_equals($artifact['kind'], $current['kind'])
                || !$this->sameFileState($artifact['identity'], $current['identity'])
            ) {
                throw new \RuntimeException(
                    'Session persistence recovery artifact changed before mutation.'
                );
            }
        }
    }

    /**
     * @param array<string,array{path:string,kind:string,identity:array<string|int,mixed>}> $artifacts
     * @param array<string|int,mixed>|null $targetIdentity
     */
    private function removeRecoveryArtifacts(array $artifacts, ?array $targetIdentity): void
    {
        foreach ($artifacts as $artifact) {
            $currentTarget = @\lstat($this->persistPath);
            if (!$this->sameOptionalFileState($targetIdentity, $currentTarget)) {
                throw new \RuntimeException(
                    'Session persistence recovery target changed during cleanup.'
                );
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $artifact['path'],
                'Session persistence interrupted ' . $artifact['kind'],
                $artifact['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect a Session persistence recovery artifact.'
                );
            }
        }
    }

    /**
     * @param array{path:string,kind:string,identity:array<string|int,mixed>} $artifact
     */
    private function restoreMissingPersistTarget(array $artifact, string $expectedSha256): void
    {
        if ($this->freshFileStatus($this->persistPath) !== null
            || \file_exists($this->persistPath)
            || \is_link($this->persistPath)
        ) {
            throw new \RuntimeException(
                'Session persistence recovery target is no longer missing.'
            );
        }
        $before = @\lstat($artifact['path']);
        if (!\is_array($before)
            || !$this->sameFileState($artifact['identity'], $before)
        ) {
            throw new \RuntimeException(
                'Session persistence recovery source changed before restoration.'
            );
        }
        $restoreRaw = GatewayProjectStateFilesystem::read(
            $artifact['path'],
            $this->maxPersistBytes,
            'Session persistence retained recovery snapshot',
        );
        if (!\hash_equals($expectedSha256, \hash('sha256', $restoreRaw))) {
            throw new \RuntimeException(
                'Session persistence recovery source contents changed before restoration.'
            );
        }
        if ($artifact['kind'] === 'atomic backup') {
            GatewayProjectStateFilesystem::restoreVerifiedAtomicBackup(
                $artifact['path'],
                $this->persistPath,
                $artifact['identity'],
                null,
                $expectedSha256,
                \strlen($restoreRaw),
                self::POSIX_PERSIST_FILE_MODE,
            );
        } else {
            // Legacy staging is not in the current atomic helper's reserved
            // namespace. Publish a sealed copy first; the recovery loop only
            // retires the legacy evidence after the new target is verified.
            GatewayProjectStateFilesystem::atomicWrite(
                $this->persistPath,
                $restoreRaw,
                self::POSIX_PERSIST_FILE_MODE,
            );
        }
        $restoredRaw = GatewayProjectStateFilesystem::read(
            $this->persistPath,
            $this->maxPersistBytes,
            'restored Session persistence snapshot',
        );
        $identityReceiptValid = $artifact['kind'] === 'atomic backup'
            ? $this->publicationObjectIdentityMatchesPath(
                $artifact['identity'],
                $this->persistPath,
            )
            : $this->isSealedPublishedPersistTarget($this->persistPath, \strlen($restoredRaw));
        if (!$identityReceiptValid
            || !\hash_equals($expectedSha256, \hash('sha256', $restoredRaw))
        ) {
            throw new \RuntimeException(
                'Restored Session persistence target failed identity or content validation.'
            );
        }
        GatewayProjectStateFilesystem::syncDirectory(\dirname($this->persistPath));
    }

    private function isSealedPublishedPersistTarget(string $path, int $expectedSize): bool
    {
        $status = $this->freshFileStatus($path);
        return \is_array($status)
            && (int)($status['size'] ?? -1) === $expectedSize
            && (\PHP_OS_FAMILY === 'Windows'
                || ((((int)($status['mode'] ?? 0)) & 0777) === self::POSIX_PERSIST_FILE_MODE));
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string|int,mixed>|null $before
     * @param array<string|int,mixed>|false|null $after
     */
    private function sameOptionalFileState(?array $before, array|false|null $after): bool
    {
        if ($before === null || !\is_array($after)) {
            return $before === null && !\is_array($after);
        }
        return $this->sameFileState($before, $after);
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameDirectoryIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function samePublicationObjectIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string|int,mixed> $expected */
    private function publicationObjectIdentityMatchesPath(array $expected, string $path): bool
    {
        $actual = $this->freshFileStatus($path);
        return \is_array($actual)
            && $this->samePublicationObjectIdentity($expected, $actual);
    }

    /** @return array<string|int,mixed>|null */
    private function freshFileStatus(string $path): ?array
    {
        \clearstatcache(true, $path);
        $status = @\lstat($path);
        return \is_array($status) ? $status : null;
    }

    /** @return array<string|int,mixed>|null */
    private function safePersistDirectoryStatus(string $path): ?array
    {
        \clearstatcache(true, $path);
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)$status['mode']) & 0170000) !== 0040000)
        ) {
            return null;
        }
        return $status;
    }

    /**
     * 确保持久化目录存在。
     */
    private function ensurePersistDirectory(): bool
    {
        $persistDir = \dirname($this->persistPath);
        if ($persistDir === ''
            || $persistDir === '.'
            || \dirname($persistDir) === $persistDir
            || \str_contains($persistDir, "\0")
        ) {
            return false;
        }
        if (!\is_dir($persistDir)
            && !@\mkdir($persistDir, self::POSIX_PERSIST_DIRECTORY_MODE, true)
            && !\is_dir($persistDir)
        ) {
            return false;
        }
        $before = $this->safePersistDirectoryStatus($persistDir);
        if ($before === null) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Windows'
            && !@\chmod($persistDir, self::POSIX_PERSIST_DIRECTORY_MODE)
        ) {
            return false;
        }
        $after = $this->safePersistDirectoryStatus($persistDir);
        return $after !== null
            && (\PHP_OS_FAMILY === 'Windows'
                || (((int)$after['mode'] & 0777) === self::POSIX_PERSIST_DIRECTORY_MODE));
    }

    /**
     * 标记持久化失败并设置退避，防止刷屏。
     */
    private function markPersistFailure(string $message): void
    {
        $this->nextPersistRetryAt = \time() + $this->persistFailureBackoffSec;
        $this->nextPersistRetryMonotonic = self::monotonicSeconds() + $this->persistFailureBackoffSec;
        $this->log($message . ', retry_in=' . $this->persistFailureBackoffSec . 's');
    }

    /**
     * 记录操作指标
     */
    private function recordOperationMetric(string $operation, float $durationSec, string $result): void
    {
        $durationMs = $durationSec * 1000;
        $metrics = \Weline\Server\Service\Telemetry\MetricsCollector::getInstance();

        $metrics->recordHistogram(
            'wls_store_operation_duration_ms',
            $durationMs,
            ['operation' => $operation, 'result' => $result]
        );

        // 慢操作检测
        $threshold = $this->slowOperationThresholds[$operation] ?? 100;
        if ($durationMs > $threshold) {
            $metrics->incrementCounter(
                'wls_store_slow_operation_total',
                1,
                ['operation' => $operation]
            );

            $this->log(\sprintf(
                'Slow operation detected: %s took %.2fms (threshold: %dms)',
                $operation,
                $durationMs,
                $threshold
            ));
        }

        // 更新请求计数
        $this->incrementRequestCount($operation);
    }

    /**
     * 记录持久化指标
     */
    private function recordPersistMetric(float $durationSec, string $result, string $reason): void
    {
        $durationMs = $durationSec * 1000;
        $metrics = \Weline\Server\Service\Telemetry\MetricsCollector::getInstance();

        $metrics->recordHistogram(
            'wls_store_persist_duration_ms',
            $durationMs,
            ['result' => $result]
        );

        if ($result === 'failure') {
            $metrics->incrementCounter(
                'wls_store_persist_failure_total',
                1,
                ['reason' => $reason]
            );
        }
    }
}
