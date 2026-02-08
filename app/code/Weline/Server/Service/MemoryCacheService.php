<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Server\Service;

/**
 * WLS 内存缓存服务
 * 
 * 使用 PHP 静态数组存储缓存（Dispatcher 常驻内存）
 * 提供高性能的内存级全页缓存能力
 * 
 * @package Weline_Server
 */
class MemoryCacheService
{
    /**
     * 缓存存储
     * 
     * @var array<string, array{response: string, headers: array, created_at: int, ttl: int}>
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
     * @var array{hits: int, misses: int, sets: int, purges: int, size: int}
     */
    private static array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'purges' => 0,
        'size' => 0,
    ];

    /**
     * 最大缓存大小（字节）
     * 
     * @var int
     */
    private static int $maxSize = 104857600; // 100MB

    /**
     * 获取缓存
     * 
     * @param string $key 缓存键
     * @return array|null 缓存数据 ['response' => string, 'headers' => array] 或 null
     */
    public static function get(string $key): ?array
    {
        if (!isset(self::$cache[$key])) {
            self::$stats['misses']++;
            return null;
        }

        $entry = self::$cache[$key];
        
        // 检查是否过期
        if ($entry['ttl'] > 0 && (time() - $entry['created_at']) > $entry['ttl']) {
            self::delete($key);
            self::$stats['misses']++;
            return null;
        }

        self::$stats['hits']++;
        
        return [
            'response' => $entry['response'],
            'headers' => $entry['headers'],
            'created_at' => $entry['created_at'],
            'age' => time() - $entry['created_at'],
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
        // 检查缓存大小限制
        $responseSize = strlen($response);
        if (self::$stats['size'] + $responseSize > self::$maxSize) {
            // 清理过期缓存
            self::cleanExpired();
            
            // 如果还是超过限制，使用 LRU 策略清理
            if (self::$stats['size'] + $responseSize > self::$maxSize) {
                self::evictOldest((int)($responseSize * 1.5));
            }
        }

        // 如果 key 已存在，先删除旧的
        if (isset(self::$cache[$key])) {
            self::delete($key);
        }

        // 存储缓存
        self::$cache[$key] = [
            'response' => $response,
            'headers' => $headers,
            'created_at' => time(),
            'ttl' => $ttl,
        ];

        // 存储元数据
        self::$metadata[$key] = [
            'tags' => $tags,
            'host' => $host,
            'url' => $url,
        ];

        // 更新 Tag 索引
        foreach ($tags as $tag) {
            if (!isset(self::$tagIndex[$tag])) {
                self::$tagIndex[$tag] = [];
            }
            self::$tagIndex[$tag][] = $key;
        }

        // 更新 Host 索引
        if ($host) {
            if (!isset(self::$hostIndex[$host])) {
                self::$hostIndex[$host] = [];
            }
            self::$hostIndex[$host][] = $key;
        }

        // 更新统计
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
     * 驱逐最旧的缓存（LRU）
     * 
     * @param int $targetFreeBytes 目标释放字节数
     * @return int 释放的字节数
     */
    private static function evictOldest(int $targetFreeBytes): int
    {
        // 按创建时间排序
        $sorted = self::$cache;
        uasort($sorted, fn($a, $b) => $a['created_at'] <=> $b['created_at']);

        $freedBytes = 0;
        foreach (array_keys($sorted) as $key) {
            if ($freedBytes >= $targetFreeBytes) {
                break;
            }
            $freedBytes += strlen(self::$cache[$key]['response']);
            self::delete($key);
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
        return array_merge(self::$stats, [
            'entries' => count(self::$cache),
            'max_size' => self::$maxSize,
            'hit_rate' => self::$stats['hits'] + self::$stats['misses'] > 0
                ? round(self::$stats['hits'] / (self::$stats['hits'] + self::$stats['misses']) * 100, 2)
                : 0,
        ]);
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
