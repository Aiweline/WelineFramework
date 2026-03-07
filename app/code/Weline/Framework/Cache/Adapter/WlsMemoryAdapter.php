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

class WlsMemoryAdapter implements CacheAdapterInterface, MemoryStoreInterface, StatsInterface
{
    /**
     * 内存存储：identity => [key => ['v' => value, 'e' => expiry, 't' => accessTime]]
     */
    private static array $store = [];

    /**
     * LRU 访问顺序：identity => [key1, key2, ...]
     */
    private static array $accessOrder = [];

    /**
     * 统计：identity => ['hits' => int, 'misses' => int]
     */
    private static array $stats = [];

    private string $identity;
    private int $maxItems;
    private int $maxMemory;
    private float $evictRatio;
    private FileAdapter $fileAdapter;

    public function __construct(string $identity, array $config = [])
    {
        $this->identity = $identity;
        $this->maxItems = (int) ($config['max_items'] ?? 10000);
        $this->maxMemory = (int) ($config['max_memory'] ?? 67108864); // 64MB
        $this->evictRatio = (float) ($config['evict_ratio'] ?? 0.1);

        $this->initBucket();

        $this->fileAdapter = new FileAdapter($identity, $config);
    }

    public function get(string $key): mixed
    {
        $bucket = &self::$store[$this->identity];

        if (isset($bucket[$key])) {
            $entry = $bucket[$key];

            if ($entry['e'] > 0 && $entry['e'] < time()) {
                unset($bucket[$key]);
                $this->removeFromAccessOrder($key);
                self::$stats[$this->identity]['misses']++;
            } else {
                $bucket[$key]['t'] = time();
                $this->updateAccessOrder($key);
                self::$stats[$this->identity]['hits']++;
                return $entry['v'];
            }
        }

        $value = $this->fileAdapter->get($key);
        
        if ($value !== null) {
            $this->setToMemory($key, $value, 1800);
            self::$stats[$this->identity]['hits']++;
            return $value;
        }

        self::$stats[$this->identity]['misses']++;
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->setToMemory($key, $value, $ttl);
        return $this->fileAdapter->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        unset(self::$store[$this->identity][$key]);
        $this->removeFromAccessOrder($key);
        return $this->fileAdapter->delete($key);
    }

    public function clear(): bool
    {
        self::$store[$this->identity] = [];
        self::$accessOrder[$this->identity] = [];
        return $this->fileAdapter->clear();
    }

    public function has(string $key): bool
    {
        if (isset(self::$store[$this->identity][$key])) {
            $entry = self::$store[$this->identity][$key];
            if ($entry['e'] === 0 || $entry['e'] >= time()) {
                return true;
            }
        }
        return $this->fileAdapter->has($key);
    }

    public function getMemoryUsage(): int
    {
        // 轻量估算：避免 serialize 在内存紧张时触发 OOM
        // 每个条目约 100 字节开销 + value 估算
        $bucket = self::$store[$this->identity] ?? [];
        $usage = 0;
        foreach ($bucket as $key => $entry) {
            // 键长度 + 条目结构开销 + 值估算
            $usage += strlen($key) + 50;
            if (is_string($entry['v'] ?? null)) {
                $usage += strlen($entry['v']);
            } elseif (is_array($entry['v'] ?? null)) {
                // 数组粗略估算：条目数 × 72 字节
                $usage += count($entry['v']) * 72 + 100;
            } else {
                $usage += 100; // 其他类型估算
            }
        }
        return $usage;
    }

    /**
     * 获取精确内存使用（谨慎使用，可能触发 OOM）
     */
    public function getMemoryUsagePrecise(): int
    {
        $bucket = self::$store[$this->identity] ?? [];
        return strlen(serialize($bucket));
    }

    /**
     * 检查 PHP 进程内存压力
     * @return float 0-1 之间，越高越紧张
     */
    public static function getMemoryPressure(): float
    {
        $usage = memory_get_usage(true);
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return 0.0;
        }
        $limitBytes = self::parseMemoryLimit($limit);
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
        return count(self::$store[$this->identity] ?? []);
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
        $bucket = &self::$store[$this->identity];
        $order = &self::$accessOrder[$this->identity];
        $evicted = 0;

        while ($evicted < $count && !empty($order)) {
            $oldestKey = array_shift($order);
            if (isset($bucket[$oldestKey])) {
                unset($bucket[$oldestKey]);
                $evicted++;
            }
        }

        return $evicted;
    }

    public function clearMemory(): void
    {
        self::$store[$this->identity] = [];
        self::$accessOrder[$this->identity] = [];
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
        if (!isset(self::$store[$this->identity])) {
            self::$store[$this->identity] = [];
            self::$accessOrder[$this->identity] = [];
            self::$stats[$this->identity] = ['hits' => 0, 'misses' => 0];
        }
    }

    /**
     * 写入内存
     */
    private function setToMemory(string $key, mixed $value, int $ttl): void
    {
        $bucket = &self::$store[$this->identity];

        // 1. 检查 PHP 进程内存压力（关键：防止 OOM）
        $pressure = self::getMemoryPressure();
        if ($pressure > 0.85) {
            // 内存压力 > 85%，激进淘汰
            $evictCount = (int) max(100, count($bucket) * 0.3);
            $this->evict($evictCount);
        } elseif ($pressure > 0.75) {
            // 内存压力 > 75%，温和淘汰
            $evictCount = (int) max(10, count($bucket) * $this->evictRatio * 2);
            $this->evict($evictCount);
        }

        // 2. 检查条目数上限
        if (count($bucket) >= $this->maxItems) {
            $evictCount = (int) max(1, $this->maxItems * $this->evictRatio);
            $this->evict($evictCount);
        }

        // 3. 检查缓存自身内存使用（轻量估算）
        if ($this->getMemoryUsage() > $this->maxMemory) {
            $evictCount = (int) max(1, count($bucket) * $this->evictRatio * 2);
            $this->evict($evictCount);
        }

        $bucket[$key] = [
            'v' => $value,
            'e' => $ttl > 0 ? time() + $ttl : 0,
            't' => time(),
        ];

        $this->updateAccessOrder($key);
    }

    /**
     * 更新 LRU 访问顺序
     */
    private function updateAccessOrder(string $key): void
    {
        $order = &self::$accessOrder[$this->identity];
        $pos = array_search($key, $order, true);
        
        if ($pos !== false) {
            unset($order[$pos]);
            $order = array_values($order);
        }
        
        $order[] = $key;
    }

    /**
     * 从访问顺序中移除
     */
    private function removeFromAccessOrder(string $key): void
    {
        $order = &self::$accessOrder[$this->identity];
        $pos = array_search($key, $order, true);
        
        if ($pos !== false) {
            unset($order[$pos]);
            $order = array_values($order);
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
        self::$store = [];
        self::$accessOrder = [];
        self::$stats = [];
    }
}
