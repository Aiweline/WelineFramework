<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

/**
 * WLS 内存缓存服务
 * 
 * 使用 PHP 静态数组存储缓存（Dispatcher 常驻内存）
 * 提供高性能的内存级全页缓存能力
 * 
 * 内存控制策略：
 * 1. 硬性限制：缓存数据总量不超过 maxSize
 * 2. 软性限制：PHP 进程内存使用超过 memoryLimit 的 80% 时触发紧急清理
 * 3. 自动淘汰：LRU（最近最少使用）策略
 * 4. 定期清理：过期缓存自动删除
 * 
 * @package Weline_Server
 */
class MemoryCacheService
{
    /**
     * 缓存存储
     * 
     * @var array<string, array{response: string, headers: array, created_at: int, ttl: int, last_access: int}>
     */
    private static array $cache = [];

    /**
     * 缓存元数据（用于 Tag 索引等）
     * 
     * @var array<string, array{tags: array, host: string, url: string}>
     */
    private static array $metadata = [];

    /**
     * Tag 到 Key 的索引
     * 
     * @var array<string, array<string>>
     */
    private static array $tagIndex = [];

    /**
     * Host 到 Key 的索引
     * 
     * @var array<string, array<string>>
     */
    private static array $hostIndex = [];

    /**
     * 统计信息
     * 
     * @var array{hits: int, misses: int, sets: int, purges: int, size: int, evictions: int, emergency_cleanups: int}
     */
    private static array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'purges' => 0,
        'size' => 0,
        'evictions' => 0,
        'emergency_cleanups' => 0,
    ];

    /**
     * 最大缓存大小（字节）- 缓存数据本身的限制
     * 
     * @var int
     */
    private static int $maxSize = 67108864; // 64MB（默认值，可通过配置覆盖）

    /**
     * PHP 进程内存限制（字节）- 用于紧急清理判断
     * 
     * @var int
     */
    private static int $memoryLimit = 134217728; // 128MB

    /**
     * 内存压力阈值（0.0-1.0）- 超过此比例触发紧急清理
     * 
     * @var float
     */
    private static float $memoryPressureThreshold = 0.8;

    /**
     * 最大条目数 - 防止 key 过多导致内存碎片
     * 
     * @var int
     */
    private static int $maxEntries = 10000;

    /**
     * 是否已初始化配置
     * 
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * 上次内存检查时间
     * 
     * @var int
     */
    private static int $lastMemoryCheck = 0;

    /**
     * 内存检查间隔（秒）
     * 
     * @var int
     */
    private static int $memoryCheckInterval = 30;

    /**
     * 初始化配置（从 env.php 读取）
     */
    private static function initConfig(): void
    {
        if (self::$initialized) {
            return;
        }

        $config = Env::getInstance()->getConfig('server') ?? [];
        $memoryConfig = $config['memory_cache'] ?? [];

        if (isset($memoryConfig['max_size'])) {
            self::$maxSize = self::parseSize($memoryConfig['max_size']);
        }

        if (isset($memoryConfig['max_entries'])) {
            self::$maxEntries = (int) $memoryConfig['max_entries'];
        }

        if (isset($memoryConfig['memory_pressure_threshold'])) {
            self::$memoryPressureThreshold = (float) $memoryConfig['memory_pressure_threshold'];
        }

        if (isset($memoryConfig['memory_check_interval'])) {
            self::$memoryCheckInterval = (int) $memoryConfig['memory_check_interval'];
        }

        $phpLimit = \ini_get('memory_limit');
        if ($phpLimit && $phpLimit !== '-1') {
            self::$memoryLimit = self::parseSize($phpLimit);
        }

        self::$initialized = true;
    }

    /**
     * 解析大小字符串（如 '64M', '1G'）为字节
     */
    private static function parseSize(string|int $size): int
    {
        if (\is_int($size)) {
            return $size;
        }

        $size = \trim($size);
        $unit = \strtoupper(\substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'G' => $value * 1073741824,
            'M' => $value * 1048576,
            'K' => $value * 1024,
            default => $value,
        };
    }

    /**
     * 检查内存压力并在必要时清理
     */
    private static function checkMemoryPressure(): void
    {
        $now = \time();
        if ($now - self::$lastMemoryCheck < self::$memoryCheckInterval) {
            return;
        }
        self::$lastMemoryCheck = $now;

        $memoryUsage = \memory_get_usage(true);
        $threshold = (int) (self::$memoryLimit * self::$memoryPressureThreshold);

        if ($memoryUsage > $threshold) {
            self::emergencyCleanup();
        }
    }

    /**
     * 紧急清理（内存压力过大时触发）
     */
    private static function emergencyCleanup(): void
    {
        self::$stats['emergency_cleanups']++;

        self::cleanExpired();

        $targetSize = (int) (self::$maxSize * 0.5);
        while (self::$stats['size'] > $targetSize && \count(self::$cache) > 0) {
            self::evictOldest((int) (self::$stats['size'] * 0.2));
        }
    }

    /**
     * 获取缓存
     * 
     * @param string $key 缓存键
     * @return array|null 缓存数据 ['response' => string, 'headers' => array] 或 null
     */
    public static function get(string $key): ?array
    {
        self::initConfig();

        if (!isset(self::$cache[$key])) {
            self::$stats['misses']++;
            return null;
        }

        $entry = self::$cache[$key];
        
        // 检查是否过期
        if ($entry['ttl'] > 0 && (\time() - $entry['created_at']) > $entry['ttl']) {
            self::delete($key);
            self::$stats['misses']++;
            return null;
        }

        self::$stats['hits']++;

        self::$cache[$key]['last_access'] = \time();
        
        return [
            'response' => $entry['response'],
            'headers' => $entry['headers'],
            'created_at' => $entry['created_at'],
            'age' => \time() - $entry['created_at'],
        ];
    }

    /**
     * 设置缓存
     * 
     * @param string $key 缓存键
     * @param string $response 响应内容
     * @param array $headers 响应头
     * @param int $ttl 过期时间（秒），0 表示永不过期
     * @param array $tags 标签数组
     * @param string $host 主机名
     * @param string $url 完整 URL
     * @return bool
     */
    public static function set(
        string $key,
        string $response,
        array $headers = [],
        int $ttl = 3600,
        array $tags = [],
        string $host = '',
        string $url = ''
    ): bool {
        self::initConfig();
        self::checkMemoryPressure();

        $responseSize = \strlen($response);

        if ($responseSize > self::$maxSize * 0.1) {
            return false;
        }

        if (\count(self::$cache) >= self::$maxEntries) {
            self::cleanExpired();
            if (\count(self::$cache) >= self::$maxEntries) {
                self::evictOldest($responseSize);
            }
        }

        if (self::$stats['size'] + $responseSize > self::$maxSize) {
            self::cleanExpired();
            
            if (self::$stats['size'] + $responseSize > self::$maxSize) {
                $needed = self::$stats['size'] + $responseSize - self::$maxSize;
                self::evictOldest((int) ($needed * 1.5));
            }
        }

        if (isset(self::$cache[$key])) {
            self::delete($key);
        }

        $now = \time();
        self::$cache[$key] = [
            'response' => $response,
            'headers' => $headers,
            'created_at' => $now,
            'last_access' => $now,
            'ttl' => $ttl,
        ];

        self::$metadata[$key] = [
            'tags' => $tags,
            'host' => $host,
            'url' => $url,
        ];

        foreach ($tags as $tag) {
            if (!isset(self::$tagIndex[$tag])) {
                self::$tagIndex[$tag] = [];
            }
            self::$tagIndex[$tag][] = $key;
        }

        if ($host) {
            if (!isset(self::$hostIndex[$host])) {
                self::$hostIndex[$host] = [];
            }
            self::$hostIndex[$host][] = $key;
        }

        self::$stats['sets']++;
        self::$stats['size'] += $responseSize;

        return true;
    }

    /**
     * 删除缓存
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public static function delete(string $key): bool
    {
        if (!isset(self::$cache[$key])) {
            return false;
        }

        // 更新统计
        self::$stats['size'] -= strlen(self::$cache[$key]['response']);
        self::$stats['purges']++;

        // 清理索引
        if (isset(self::$metadata[$key])) {
            $metadata = self::$metadata[$key];
            
            // 清理 Tag 索引
            foreach ($metadata['tags'] as $tag) {
                if (isset(self::$tagIndex[$tag])) {
                    self::$tagIndex[$tag] = array_filter(
                        self::$tagIndex[$tag],
                        fn($k) => $k !== $key
                    );
                    if (empty(self::$tagIndex[$tag])) {
                        unset(self::$tagIndex[$tag]);
                    }
                }
            }

            // 清理 Host 索引
            $host = $metadata['host'];
            if ($host && isset(self::$hostIndex[$host])) {
                self::$hostIndex[$host] = array_filter(
                    self::$hostIndex[$host],
                    fn($k) => $k !== $key
                );
                if (empty(self::$hostIndex[$host])) {
                    unset(self::$hostIndex[$host]);
                }
            }

            unset(self::$metadata[$key]);
        }

        unset(self::$cache[$key]);

        return true;
    }

    /**
     * 按 URL 清理缓存
     * 
     * @param string $url URL 或 URL 前缀
     * @return bool
     */
    public static function purgeByUrl(string $url): bool
    {
        $found = false;
        
        foreach (self::$metadata as $key => $metadata) {
            if ($metadata['url'] === $url || str_starts_with($metadata['url'], $url)) {
                self::delete($key);
                $found = true;
            }
        }

        return $found;
    }

    /**
     * 按 Tag 清理缓存
     * 
     * @param string $tag 标签
     * @return int 清理的缓存数量
     */
    public static function purgeByTag(string $tag): int
    {
        if (!isset(self::$tagIndex[$tag])) {
            return 0;
        }

        $keys = self::$tagIndex[$tag];
        $count = 0;

        foreach ($keys as $key) {
            if (self::delete($key)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 按 Host 清理缓存
     * 
     * @param string $host 主机名
     * @return int 清理的缓存数量
     */
    public static function purgeByHost(string $host): int
    {
        if (!isset(self::$hostIndex[$host])) {
            return 0;
        }

        $keys = self::$hostIndex[$host];
        $count = 0;

        foreach ($keys as $key) {
            if (self::delete($key)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 按 Key 前缀清理缓存
     * 
     * @param string $keyPrefix 键前缀
     * @return bool
     */
    public static function purgeByKey(string $keyPrefix): bool
    {
        $found = false;
        
        foreach (array_keys(self::$cache) as $key) {
            if ($key === $keyPrefix || str_starts_with($key, $keyPrefix)) {
                self::delete($key);
                $found = true;
            }
        }

        return $found;
    }

    /**
     * 清理所有缓存
     * 
     * @return void
     */
    public static function purgeAll(): void
    {
        self::$cache = [];
        self::$metadata = [];
        self::$tagIndex = [];
        self::$hostIndex = [];
        self::$stats['purges']++;
        self::$stats['size'] = 0;
    }

    /**
     * 清理过期缓存
     * 
     * @return int 清理的数量
     */
    public static function cleanExpired(): int
    {
        $count = 0;
        $now = time();

        foreach (self::$cache as $key => $entry) {
            if ($entry['ttl'] > 0 && ($now - $entry['created_at']) > $entry['ttl']) {
                self::delete($key);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 驱逐最少使用的缓存（LRU）
     * 
     * @param int $targetFreeBytes 目标释放字节数
     * @return int 释放的字节数
     */
    private static function evictOldest(int $targetFreeBytes): int
    {
        $sorted = self::$cache;
        \uasort($sorted, function ($a, $b) {
            return ($a['last_access'] ?? $a['created_at']) <=> ($b['last_access'] ?? $b['created_at']);
        });

        $freedBytes = 0;
        foreach (\array_keys($sorted) as $key) {
            if ($freedBytes >= $targetFreeBytes) {
                break;
            }
            $freedBytes += \strlen(self::$cache[$key]['response']);
            self::delete($key);
            self::$stats['evictions']++;
        }

        return $freedBytes;
    }

    /**
     * 获取统计信息
     * 
     * @return array
     */
    public static function getStats(): array
    {
        self::initConfig();

        $memoryUsage = \memory_get_usage(true);
        $total = self::$stats['hits'] + self::$stats['misses'];

        return \array_merge(self::$stats, [
            'entries' => \count(self::$cache),
            'max_entries' => self::$maxEntries,
            'max_size' => self::$maxSize,
            'max_size_human' => self::formatBytes(self::$maxSize),
            'size_human' => self::formatBytes(self::$stats['size']),
            'usage_percent' => self::$maxSize > 0
                ? \round(self::$stats['size'] / self::$maxSize * 100, 2)
                : 0,
            'hit_rate' => $total > 0
                ? \round(self::$stats['hits'] / $total * 100, 2)
                : 0,
            'php_memory_usage' => $memoryUsage,
            'php_memory_usage_human' => self::formatBytes($memoryUsage),
            'php_memory_limit' => self::$memoryLimit,
            'php_memory_limit_human' => self::formatBytes(self::$memoryLimit),
            'memory_pressure' => self::$memoryLimit > 0
                ? \round($memoryUsage / self::$memoryLimit * 100, 2)
                : 0,
        ]);
    }

    /**
     * 格式化字节数
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return \round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return \round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return \round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * 设置最大缓存大小
     * 
     * @param int $maxSize 最大大小（字节）
     * @return void
     */
    public static function setMaxSize(int $maxSize): void
    {
        self::$maxSize = $maxSize;
    }

    /**
     * 获取最大缓存大小
     * 
     * @return int
     */
    public static function getMaxSize(): int
    {
        return self::$maxSize;
    }

    /**
     * 构建缓存键
     * 
     * @param string $uri URI 路径
     * @param string $host 主机名
     * @param string $method HTTP 方法
     * @param string $queryString 查询字符串
     * @return string
     */
    public static function buildCacheKey(string $uri, string $host, string $method = 'GET', string $queryString = ''): string
    {
        $key = sprintf(
            'wls_mem_%s_%s_%s',
            md5($host),
            strtoupper($method),
            md5($uri . ($queryString ? '?' . $queryString : ''))
        );
        
        return $key;
    }

    /**
     * 检查缓存是否存在且有效
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public static function has(string $key): bool
    {
        if (!isset(self::$cache[$key])) {
            return false;
        }

        $entry = self::$cache[$key];
        
        // 检查是否过期
        if ($entry['ttl'] > 0 && (time() - $entry['created_at']) > $entry['ttl']) {
            self::delete($key);
            return false;
        }

        return true;
    }

    /**
     * 重置统计信息
     * 
     * @return void
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'purges' => 0,
            'size' => self::$stats['size'], // 保留当前大小
        ];
    }
}
