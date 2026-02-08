<?php

declare(strict_types=1);

/*
 * WLS 模式进程内内存缓存驱动
 * 
 * 通过 extends 衍生机制继承 Framework 的 File 缓存驱动。
 * 在 WLS 常驻进程下用进程内存替代文件 I/O，大幅提升性能。
 * 
 * 原理：
 * - 首次 get 时若内存无数据，回退到父类读文件并缓存到内存
 * - set 时同时写内存和文件（保证重启后缓存仍可用）
 * - 后续 get 直接走内存，避免磁盘 I/O
 * 
 * 数据存在当前 Worker 进程内存；跨进程不共享但通过文件持久化保证一致性。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Extends\Module\Weline_Framework\Cache;

use Weline\Framework\Cache\Driver\File;

class WlsMemoryCache extends File
{
    /** 按 identity 分桶的内存缓存：identity => [ builtKey => ['v' => value, 'e' => expiryTs ] ] */
    private static array $memoryStore = [];

    /**
     * 初始化时确保内存桶存在
     */
    public function __init(): void
    {
        parent::__init();
        if (!isset(self::$memoryStore[$this->identity])) {
            self::$memoryStore[$this->identity] = [];
        }
    }

    /**
     * 获取缓存：优先内存，内存未命中则读文件并缓存到内存
     */
    public function get(string $key): mixed
    {
        if (!$this->status) {
            return false;
        }
        
        $k = $this->buildKey($key);
        $bucket = &self::$memoryStore[$this->identity];
        
        // 内存命中
        if (isset($bucket[$k])) {
            $entry = $bucket[$k];
            // 检查过期
            if ($entry['e'] > 0 && $entry['e'] < \time()) {
                unset($bucket[$k]);
            } else {
                return $entry['v'];
            }
        }
        
        // 内存未命中，回退到文件读取
        $value = parent::get($key);
        if ($value !== false) {
            // 缓存到内存（默认 30 分钟，与文件缓存同步）
            $bucket[$k] = ['v' => $value, 'e' => \time() + 1800];
        }
        return $value;
    }

    /**
     * 检查缓存是否存在：优先内存
     */
    public function exists(string $key): bool
    {
        if (!$this->status) {
            return false;
        }
        
        $k = $this->buildKey($key);
        $bucket = &self::$memoryStore[$this->identity];
        
        if (isset($bucket[$k])) {
            $e = $bucket[$k]['e'];
            if ($e > 0 && $e < \time()) {
                unset($bucket[$k]);
            } else {
                return true;
            }
        }
        
        return parent::exists($key);
    }

    /**
     * 设置缓存：同时写内存和文件
     */
    public function set(string $key, mixed $value, int $duration = 1800): bool
    {
        if (!$this->status) {
            return false;
        }
        
        $k = $this->buildKey($key);
        $expires = $duration <= 0 ? 0 : (\time() + $duration);
        
        // 写入内存
        self::$memoryStore[$this->identity][$k] = ['v' => $value, 'e' => $expires];
        
        // 同时写入文件（保证持久化，重启后可恢复）
        return parent::set($key, $value, $duration);
    }

    /**
     * 添加缓存（仅当不存在时）
     */
    public function add(string $key, mixed $value, int $duration = 1800): bool
    {
        if (!$this->status) {
            return false;
        }
        
        if ($this->exists($key)) {
            return false;
        }
        
        return $this->set($key, $value, $duration);
    }

    /**
     * 删除缓存：同时删内存和文件
     */
    public function delete(string $key): bool
    {
        $k = $this->buildKey($key);
        unset(self::$memoryStore[$this->identity][$k]);
        return parent::delete($key);
    }

    /**
     * 清空缓存：同时清内存和文件
     */
    public function flush(): bool
    {
        self::$memoryStore[$this->identity] = [];
        return parent::flush();
    }

    /**
     * 清理缓存
     */
    public function clear(): bool
    {
        self::$memoryStore[$this->identity] = [];
        return parent::clear();
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        $fileStats = parent::getStats();
        $bucket = self::$memoryStore[$this->identity] ?? [];
        $now = \time();
        $memoryItems = 0;
        foreach ($bucket as $entry) {
            if ($entry['e'] === 0 || $entry['e'] >= $now) {
                $memoryItems++;
            }
        }
        return [
            'items' => $fileStats['items'],
            'size' => $fileStats['size'],
            'files' => $fileStats['files'],
            'memory_items' => $memoryItems,
            'driver' => 'WlsMemoryCache (extends File)',
        ];
    }

    /**
     * 清空所有 identity 的内存缓存（用于进程重置）
     */
    public static function clearAllMemory(): void
    {
        self::$memoryStore = [];
    }
}
