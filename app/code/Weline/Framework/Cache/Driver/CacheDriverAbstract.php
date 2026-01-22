<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Driver;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

abstract class CacheDriverAbstract implements \Weline\Framework\Cache\CacheDriverInterface
{
    protected bool $status;
    protected array $config;
    protected string $identity;
    protected string $tip;
    
    /**
     * 性能优化：缓存 Request 实例
     */
    private static ?Request $requestInstance = null;
    
    /**
     * 性能优化：缓存已计算的 key
     */
    private static array $keyCache = [];

    public function __construct(string $identity, array $config, $tip = '', bool $status = true)
    {
        $this->identity = $identity;
        $this->config   = $config;
        $this->tip      = $tip;
        $this->status   = $status;
        $this->__init();
    }

    public function __init()
    {
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        $this->__init();
    }

    public function setIdentity(string $identity): static
    {
        $this->identity = $identity;
        return $this;
    }

    public function getIdentify(): string
    {
        return $this->identity;
    }

    public function getStatus(): bool
    {
        return $this->status;
    }

    public function tip(): string
    {
        return $this->tip;
    }

    /**
     * @DESC         |设置状态
     * 0 : 关闭
     * 1 : 开启
     * 参数区：
     *
     * @param bool $status
     *
     * @return CacheInterface
     */
    public function setStatus(bool $status): CacheInterface
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @DESC         |使用指定的键从缓存中检索多个值。
     *
     * 参数区：
     *
     * @param array $keys
     *
     * @return array
     */
    public function getMulti(array $keys): array
    {
        if (!$this->status) {
            return [];
        }
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    /**
     * @DESC         |缓存中存储多个项目。每个项包含一个由键标识的值。
     *
     * 参数区：
     *
     * @param array $items
     * @param int   $duration
     *
     * @return array
     */
    public function setMulti(array $items, int $duration = 1800): array
    {
        if (!$this->status) {
            return [];
        }
        $failedKeys = [];
        foreach ($items as $key => $value) {
            if ($this->set($key, $value, $duration) === false) {
                $failedKeys[] = $key;
            }
        }

        return $failedKeys;
    }

    /**
     * @DESC         |在缓存中存储多个项目。每个项包含一个由键标识的值。
     *                如果缓存已经包含这样一个键，则现有值和过期时间将被保留。
     *
     * 参数区：
     *
     * @param     $items
     * @param int $duration
     *
     * @return array
     */
    public function addMulti($items, int $duration = 1800): array
    {
        if (!$this->status) {
            return [];
        }
        $failedKeys = [];
        foreach ($items as $key => $value) {
            if ($this->add($key, $value, $duration) === false) {
                $failedKeys[] = $key;
            }
        }

        return $failedKeys;
    }

    /**
     * @DESC         |从给定键生成规范化缓存键
     *
     * 性能优化：使用 xxh3 或 crc32 替代 md5（更快）
     *
     * @param string $key
     *
     * @return string
     */
    public function buildKey(string $key): string
    {
        // 性能优化：使用缓存避免重复计算
        $cacheKey = $this->identity . '_' . $key;
        if (isset(self::$keyCache[$cacheKey])) {
            return self::$keyCache[$cacheKey];
        }
        
        // 性能优化：使用 xxh3（PHP 8.1+）或 crc32（更快的哈希算法）
        // xxh3 比 md5 快约 10 倍，crc32 比 md5 快约 5 倍
        if (function_exists('hash') && in_array('xxh3', hash_algos(), true)) {
            $result = hash('xxh3', $cacheKey);
        } else {
            // 回退到 crc32（比 md5 快）结合 fnv1a32
            $result = sprintf('%08x%08x', crc32($cacheKey), crc32(strrev($cacheKey)));
        }
        
        self::$keyCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * @DESC         | 生成请求级别的缓存key
     *
     * 性能优化：缓存 Request 实例，减少 ObjectManager 调用
     *
     * @param string $key [基础键]
     *
     * @return string
     */
    public function buildWithRequestKey(string $key): string
    {
        $request = $this->getRequest();
        $attach_variables = [
            'page' => $request->getGet('page', 1),
            'pageSize' => $request->getGet('pageSize', 10)
        ];
        
        // 构建完整的缓存键
        $fullKey = $key . $request->getUri() . $request->getMethod() . implode('', $attach_variables);
        
        return $this->buildKey($fullKey);
    }

    /**
     * 性能优化：缓存 Request 实例
     */
    private function getRequest(): Request
    {
        if (self::$requestInstance === null) {
            self::$requestInstance = ObjectManager::getInstance(Request::class);
        }
        return self::$requestInstance;
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array 返回包含 items, size, files 等统计信息的数组
     */
    public function getStats(): array
    {
        // 默认实现，子类可以重写此方法提供更准确的统计信息
        return [
            'items' => 1,
            'size' => 0,
            'files' => 1
        ];
    }
}
