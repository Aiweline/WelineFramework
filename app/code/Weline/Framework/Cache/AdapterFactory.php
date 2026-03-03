<?php

declare(strict_types=1);

/**
 * 适配器工厂
 * 
 * 通过注册表模式创建缓存适配器（OCP）。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\Adapter\RedisAdapter;
use Weline\Framework\Cache\Adapter\MemcachedAdapter;
use Weline\Framework\Cache\Adapter\ApcuAdapter;
use Weline\Framework\Cache\Adapter\WlsMemoryAdapter;

class AdapterFactory
{
    /**
     * 适配器注册表
     * 
     * @var array<string, class-string<CacheAdapterInterface>>
     */
    private array $adapters = [
        'file' => FileAdapter::class,
        'redis' => RedisAdapter::class,
        'memcached' => MemcachedAdapter::class,
        'apcu' => ApcuAdapter::class,
        'wls_memory' => WlsMemoryAdapter::class,
    ];

    private array $config;

    public function __construct()
    {
        $this->config = (array) Env::getInstance()->getConfig('cache');
    }

    /**
     * 注册自定义适配器（OCP: 扩展点）
     *
     * @param string $name 驱动名称
     * @param class-string<CacheAdapterInterface> $adapterClass 适配器类
     */
    public function register(string $name, string $adapterClass): void
    {
        $this->adapters[$name] = $adapterClass;
    }

    /**
     * 创建适配器
     *
     * @param string $driver 驱动名称
     * @param string $identity 池标识
     * @return CacheAdapterInterface
     * @throws \InvalidArgumentException
     */
    public function create(string $driver, string $identity): CacheAdapterInterface
    {
        if (!isset($this->adapters[$driver])) {
            throw new \InvalidArgumentException("Unknown cache driver: {$driver}");
        }

        $adapterClass = $this->adapters[$driver];
        $driverConfig = $this->config['drivers'][$driver] ?? [];

        return new $adapterClass($identity, $driverConfig);
    }

    /**
     * 获取所有已注册的驱动名称
     *
     * @return array<string>
     */
    public function getRegisteredDrivers(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * 检查驱动是否已注册
     *
     * @param string $driver 驱动名称
     * @return bool
     */
    public function hasDriver(string $driver): bool
    {
        return isset($this->adapters[$driver]);
    }

    /**
     * 获取驱动配置
     *
     * @param string $driver 驱动名称
     * @return array
     */
    public function getDriverConfig(string $driver): array
    {
        return $this->config['drivers'][$driver] ?? [];
    }
}
