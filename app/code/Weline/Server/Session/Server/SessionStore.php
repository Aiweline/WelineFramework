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

final class SessionStore
{
    /**
     * Session 存储
     * 结构：[sessionId => ['data' => array, 'expire' => int, 'atime' => int]]
     * - data: Session 数据
     * - expire: 过期时间戳（0 = 永不过期）
     * - atime: 最后访问时间（用于 LRU）
     */
    private array $store = [];

    /**
     * LRU 访问顺序（最近访问的在末尾）
     * 结构：[sessionId => true]
     */
    private array $lruOrder = [];

    /** 最大 Session 数量 */
    private int $maxSessions;

    /** 默认 TTL（秒） */
    private int $defaultTtl;

    /** 持久化文件路径 */
    private string $persistPath;
    
    /** 持久化失败后的下一次重试时间戳 */
    private int $nextPersistRetryAt = 0;

    /** 持久化间隔（秒） */
    private int $persistInterval;

    /** 每 N 次写入后持久化（仅统计 set/delete/destroy 等真实写入，不含 get/touch） */
    private int $persistOnWrites;

    /** 上次持久化时间 */
    private int $lastPersistTime = 0;

    /** 最小持久化间隔（秒），防止高并发下重复刷盘 */
    private int $persistMinInterval = 5;
    
    /** 持久化失败后重试退避（秒） */
    private int $persistFailureBackoffSec = 5;

    /** 自上次持久化后的写入次数 */
    private int $writesSinceLastPersist = 0;

    /** 是否有未持久化的更改 */
    private bool $dirty = false;

    /** 关键操作后强制持久化 */
    private bool $persistOnCritical;
    
    /** 连续 destroy 计数（用于批量删除检测） */
    private int $destroyCount = 0;
    
    // ==================== 监控指标 ====================
    
    /** 请求计数 */
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
    
    /** 服务启动时间 */
    private int $startTime;

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->maxSessions = (int)($config['max_sessions'] ?? 50000);
        $this->defaultTtl = (int)($config['session_ttl'] ?? 3600);
        $this->persistInterval = (int)($config['persist_interval'] ?? 30);
        $this->persistOnWrites = (int)($config['persist_on_writes'] ?? 100);
        $this->persistOnCritical = (bool)($config['persist_on_critical'] ?? true);
        
        $basePath = $config['persist_path'] ?? (\defined('BP') ? BP . 'var/session/' : '/tmp/wls_session/');
        if (!\is_dir($basePath)) {
            @\mkdir($basePath, 0755, true);
        }
        $persistFileName = \trim((string)($config['persist_file_name'] ?? 'wls_session_store.dat'));
        if ($persistFileName === '') {
            $persistFileName = 'wls_session_store.dat';
        }
        $persistFileName = \basename(\str_replace('\\', '/', $persistFileName));
        if ($persistFileName === '' || $persistFileName === '.' || $persistFileName === '..') {
            $persistFileName = 'wls_session_store.dat';
        }
        $this->persistPath = \rtrim($basePath, '/\\') . '/' . $persistFileName;
        $this->persistMinInterval = (int)($config['persist_min_interval'] ?? 5);
        if ($this->persistMinInterval < 1) {
            $this->persistMinInterval = 1;
        }
        $this->persistFailureBackoffSec = (int)($config['persist_failure_backoff_sec'] ?? $this->persistMinInterval);
        if ($this->persistFailureBackoffSec < 1) {
            $this->persistFailureBackoffSec = 1;
        }
        
        $this->lastPersistTime = \time();
        $this->startTime = \time();
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
        if (!\is_file($this->persistPath)) {
            $this->log('No persist file found, starting fresh');
            return false;
        }

        $content = @\file_get_contents($this->persistPath);
        if ($content === false) {
            $this->log('Failed to read persist file');
            return false;
        }

        $data = @\unserialize($content);
        if (!\is_array($data)) {
            $this->log('Invalid persist file format');
            return false;
        }

        $now = \time();
        $loaded = 0;
        $expired = 0;

        foreach ($data as $sessionId => $entry) {
            if (!isset($entry['data']) || !isset($entry['expire'])) {
                continue;
            }
            
            if ($entry['expire'] > 0 && $entry['expire'] < $now) {
                $expired++;
                continue;
            }

            $this->store[$sessionId] = [
                'data' => $entry['data'],
                'expire' => $entry['expire'],
                'atime' => $entry['atime'] ?? $now,
            ];
            $this->lruOrder[$sessionId] = true;
            $loaded++;
        }

        $this->log("Loaded {$loaded} sessions from file, {$expired} expired");
        return true;
    }

    /**
     * 持久化数据到文件
     */
    public function persistToFile(): bool
    {
        if (!$this->dirty && \count($this->store) === 0) {
            return true;
        }

        if (!$this->ensurePersistDirectory()) {
            return false;
        }
        
        $tempPath = $this->persistPath . '.tmp.' . \getmypid() . '.' . \str_replace('.', '', \uniqid('', true));
        try {
            $content = \serialize($this->store);
        } catch (\Throwable $throwable) {
            $this->markPersistFailure('Failed to serialize persist payload: ' . $throwable->getMessage());
            return false;
        }
        
        if (@\file_put_contents($tempPath, $content, LOCK_EX) === false) {
            @\unlink($tempPath);
            $this->markPersistFailure($this->buildLastPhpErrorMessage(
                'Failed to write persist temp file',
                $tempPath
            ));
            return false;
        }

        if (!@\rename($tempPath, $this->persistPath)) {
            // Windows 下 rename 可能无法覆盖目标文件，尝试 unlink 后再 rename 一次。
            $renamed = false;
            if (@\is_file($this->persistPath) && @\unlink($this->persistPath)) {
                $renamed = @\rename($tempPath, $this->persistPath);
            }
            if (!$renamed) {
                @\unlink($tempPath);
                $this->markPersistFailure($this->buildLastPhpErrorMessage(
                    'Failed to replace persist file',
                    $this->persistPath
                ));
                return false;
            }
        }

        $this->dirty = false;
        $this->lastPersistTime = \time();
        $this->writesSinceLastPersist = 0;
        $this->nextPersistRetryAt = 0;
        $this->persistCount++;
        $this->log('Persisted ' . \count($this->store) . ' sessions to file');
        return true;
    }

    /**
     * 检查是否需要持久化
     * 节流：两次持久化间隔至少 persistMinInterval 秒，避免高并发下疯狂刷盘。
     */
    public function checkPersist(): bool
    {
        if (!$this->dirty) {
            return false;
        }

        $now = \time();
        $elapsed = $now - $this->lastPersistTime;

        if ($this->nextPersistRetryAt > $now) {
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
        if (!isset($this->store[$sessionId])) {
            return $key === null ? [] : null;
        }

        $entry = &$this->store[$sessionId];

        if ($entry['expire'] > 0 && $entry['expire'] < \time()) {
            $this->destroy($sessionId);
            return $key === null ? [] : null;
        }

        // Sliding expiration: active sessions should refresh TTL on reads.
        $this->touch($sessionId);

        if ($key === null) {
            return $entry['data'];
        }

        return $entry['data'][$key] ?? null;
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

        return true;
    }

    /**
     * 删除 Session 中的某个键
     */
    public function delete(string $sessionId, string $key): bool
    {
        if (!isset($this->store[$sessionId])) {
            return false;
        }

        if (!isset($this->store[$sessionId]['data'][$key])) {
            return false;
        }

        unset($this->store[$sessionId]['data'][$key]);
        $this->markDirty();

        return true;
    }

    /**
     * 销毁整个 Session
     */
    public function destroy(string $sessionId): bool
    {
        if (!isset($this->store[$sessionId])) {
            return false;
        }

        unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
        $this->markDirty();
        
        $this->destroyCount++;
        if ($this->persistOnCritical && $this->destroyCount >= 10) {
            $this->destroyCount = 0;
            $this->persistToFile();
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
            'uptime' => \time() - $this->startTime,
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
        $lines[] = "{$prefix}uptime_seconds " . (\time() - $this->startTime);
        
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
    private function evictIfNeeded(): void
    {
        if (\count($this->store) < $this->maxSessions) {
            return;
        }

        $toEvict = (int)\ceil($this->maxSessions * 0.1);
        $evicted = 0;
        $now = \time();
        $expiringThreshold = $now + 600;
        
        $expiringSessions = [];
        $lruSessions = [];
        
        foreach ($this->lruOrder as $sessionId => $_) {
            if (!isset($this->store[$sessionId])) {
                continue;
            }
            
            $entry = $this->store[$sessionId];
            if ($entry['expire'] > 0 && $entry['expire'] < $expiringThreshold) {
                $expiringSessions[$sessionId] = $entry['expire'];
            } else {
                $lruSessions[] = $sessionId;
            }
        }
        
        \asort($expiringSessions);
        
        foreach ($expiringSessions as $sessionId => $expire) {
            if ($evicted >= $toEvict) {
                break;
            }
            unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
            $evicted++;
        }
        
        foreach ($lruSessions as $sessionId) {
            if ($evicted >= $toEvict) {
                break;
            }
            unset($this->store[$sessionId], $this->lruOrder[$sessionId]);
            $evicted++;
        }

        if ($evicted > 0) {
            $this->evictionCount += $evicted;
            $this->log("LRU evicted {$evicted} sessions (expiring-first strategy)");
            $this->markDirty();
        }
    }

    /**
     * 标记数据已更改
     */
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
     * 确保持久化目录存在。
     */
    private function ensurePersistDirectory(): bool
    {
        $persistDir = \dirname($this->persistPath);
        if ($persistDir === '' || $persistDir === '.' || $persistDir === DIRECTORY_SEPARATOR) {
            $this->markPersistFailure('Invalid persist directory: ' . $persistDir);
            return false;
        }
        if (\is_dir($persistDir)) {
            return true;
        }
        if (!@\mkdir($persistDir, 0755, true) && !\is_dir($persistDir)) {
            $this->markPersistFailure($this->buildLastPhpErrorMessage(
                'Failed to create persist directory',
                $persistDir
            ));
            return false;
        }
        return true;
    }

    /**
     * 构建带系统错误信息的日志。
     */
    private function buildLastPhpErrorMessage(string $prefix, string $path): string
    {
        $error = \error_get_last();
        if (\is_array($error) && !empty($error['message'])) {
            return $prefix . ': path=' . $path . ', error=' . $error['message'];
        }
        return $prefix . ': path=' . $path;
    }

    /**
     * 标记持久化失败并设置退避，防止刷屏。
     */
    private function markPersistFailure(string $message): void
    {
        $this->nextPersistRetryAt = \time() + $this->persistFailureBackoffSec;
        $this->log($message . ', retry_in=' . $this->persistFailureBackoffSec . 's');
    }
}
