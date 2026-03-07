<?php

declare(strict_types=1);

/**
 * WLS 内存缓存适配器
 * 
 * 专为 WLS（Weline Server）常驻进程设计的内存缓存。
 * 特点：
 * - L1 内存缓存（进程内）
 * - L2 文件持久化（重启恢复）
 * - LRU 淘汰策略
 * - 内存上限控制
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Adapter;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Cache\Contract\StatsInterface;
use Weline\Server\Service\CacheMemoryService;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;
use Weline\Server\Shared\Service\SharedMemoryService;

class WlsMemoryAdapter implements CacheAdapterInterface, MemoryStoreInterface, StatsInterface
{
    /**
     * 统计：identity => ['hits' => int, 'misses' => int]
     */
    private static array $stats = [];

    private string $identity;
    private int $maxItems;
    private int $maxMemory;
    private CacheMemoryService $cacheMemoryService;

    public function __construct(string $identity, array $config = [])
    {
        $this->identity = $identity;
        $this->maxItems = (int) ($config['max_items'] ?? 10000);
        $this->maxMemory = (int) ($config['max_memory'] ?? 67108864); // 64MB
        $endpoint = RoutingPolicyRegistry::getMemoryEndpoint();
        $host = (string)($config['host'] ?? $endpoint['host']);
        $port = (int)($config['port'] ?? $endpoint['port']);
        $sharedMemory = new SharedMemoryService($host, $port, [
            'connect_timeout' => (float)($config['connect_timeout'] ?? 1.0),
            'timeout' => (float)($config['timeout'] ?? 2.0),
            'pool_size' => (int)($config['pool_size'] ?? 8),
            'pool_min_idle' => (int)($config['pool_min_idle'] ?? 1),
            'acquire_timeout' => (float)($config['acquire_timeout'] ?? 0.2),
        ]);
        $this->cacheMemoryService = new CacheMemoryService($sharedMemory);
        $this->initBucket();
    }

    public function get(string $key): mixed
    {
        $value = $this->cacheMemoryService->get($this->identity, $key);
        if ($value === null) {
            self::$stats[$this->identity]['misses']++;
            return null;
        }
        self::$stats[$this->identity]['hits']++;
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->cacheMemoryService->set($this->identity, $key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cacheMemoryService->delete($this->identity, $key);
    }

    public function clear(): bool
    {
        return $this->cacheMemoryService->clear($this->identity);
    }

    public function has(string $key): bool
    {
        return $this->cacheMemoryService->exists($this->identity, $key);
    }

    public function getMemoryUsage(): int
    {
        return 0;
    }

    /**
     * 获取精确内存使用（谨慎使用，可能触发 OOM）
     */
    public function getMemoryUsagePrecise(): int
    {
        return 0;
    }

    /**
     * 检查 PHP 进程内存压力
     * @return float 0-1 之间，越高越紧张
     */
    public static function getMemoryPressure(): float
    {
        $usage = \memory_get_usage(true);
        $limit = \ini_get('memory_limit');
        if ($limit === '-1') {
            return 0.0;
        }
        $limitBytes = self::parseMemoryLimit((string)$limit);
        if ($limitBytes <= 0) {
            return 0.0;
        }
        return $usage / $limitBytes;
    }

    /**
     * 解析 memory_limit 字符串
     */
    private static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1' || $limit === '0') {
            return 0;
        }
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        return $value;
    }

    public function getMemoryItemCount(): int
    {
        return 0;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function getMaxMemory(): int
    {
        return $this->maxMemory;
    }

    public function evict(int $count): int
    {
        return 0;
    }

    public function clearMemory(): void
    {
        // Shared memory is single source of truth, no process-local memory bucket.
    }

    public function warmUp(int $limit = 1000): int
    {
        return 0;
    }

    public function getHits(): int
    {
        return self::$stats[$this->identity]['hits'] ?? 0;
    }

    public function getMisses(): int
    {
        return self::$stats[$this->identity]['misses'] ?? 0;
    }

    public function getHitRatio(): float
    {
        $hits = $this->getHits();
        $misses = $this->getMisses();
        $total = $hits + $misses;
        return $total > 0 ? round($hits / $total, 4) : 0.0;
    }

    public function getTotalRequests(): int
    {
        return $this->getHits() + $this->getMisses();
    }

    public function resetStats(): void
    {
        self::$stats[$this->identity] = ['hits' => 0, 'misses' => 0];
    }

    /**
     * 获取标识
     */
    public function getIdentity(): string
    {
        return $this->identity;
    }

    /**
     * 初始化内存桶
     */
    private function initBucket(): void
    {
        if (!isset(self::$stats[$this->identity])) {
            self::$stats[$this->identity] = ['hits' => 0, 'misses' => 0];
        }
    }

    /**
     * StateManager 重置入口
     */
    public static function resetRequestState(): void
    {
        foreach (array_keys(self::$stats) as $identity) {
            self::$stats[$identity] = ['hits' => 0, 'misses' => 0];
        }
    }

    /**
     * 清空所有内存（进程退出时调用）
     */
    public static function clearAllMemory(): void
    {
        self::$stats = [];
    }
}
