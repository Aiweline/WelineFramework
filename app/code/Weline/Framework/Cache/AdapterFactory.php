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
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\Adapter\RedisAdapter;
use Weline\Framework\Cache\Adapter\MemcachedAdapter;
use Weline\Framework\Cache\Adapter\ApcuAdapter;
use Weline\Framework\Cache\Contract\CacheAdapterCreatorInterface;
use Weline\Framework\Cache\Contract\CacheAdapterDescriptor;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterProviderInterface;
use Weline\Framework\Compilation\ServiceProviderRegistry;

class AdapterFactory
{
    public const PROVIDER_CAPABILITY_PREFIX = 'cache.adapter_provider.';

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
    ];

    /** @var array<string, CacheAdapterCreatorInterface> */
    private array $providerCreators = [];

    /**
     * Provider descriptors are an immutable framework:compile product. Cache
     * the validated, stateless creators by implementation digest so creating
     * another CacheManager never repeats provider construction. A custom
     * registry naturally receives a different digest and remains isolated.
     *
     * @var array<string, array<string, CacheAdapterCreatorInterface>>
     */
    private static array $processProviderCreators = [];

    private ?string $providerLoadFailure = null;

    private array $config;

    public function __construct(
        ?ServiceProviderRegistry $serviceProviders = null,
    )
    {
        $this->config = (array) Env::getInstance()->getConfig('cache');
        $this->loadProviderAdapters($serviceProviders ?? new ServiceProviderRegistry());
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
        if (isset($this->adapters[$driver])) {
            $adapterClass = $this->adapters[$driver];
            $driverConfig = $this->config['drivers'][$driver] ?? [];
            $adapter = new $adapterClass($identity, $driverConfig);
            if (!$adapter instanceof CacheAdapterInterface) {
                throw new \RuntimeException("Cache adapter {$adapterClass} violates its contract.");
            }

            return $adapter;
        }

        if (isset($this->providerCreators[$driver])) {
            $adapter = $this->providerCreators[$driver]->create(
                $identity,
                (array)($this->config['drivers'][$driver] ?? []),
            );
            if (!$adapter instanceof CacheAdapterInterface) {
                throw new \RuntimeException("Cache adapter provider for {$driver} violates its contract.");
            }

            return $adapter;
        }

        if ($this->providerLoadFailure !== null) {
            throw new \RuntimeException(
                "Cache adapter provider registry is unavailable while resolving {$driver}: "
                . $this->providerLoadFailure,
            );
        }

        throw new \InvalidArgumentException("Unknown cache driver: {$driver}");
    }

    /**
     * 获取所有已注册的驱动名称
     *
     * @return array<string>
     */
    public function getRegisteredDrivers(): array
    {
        return \array_values(\array_unique(\array_merge(
            \array_keys($this->adapters),
            \array_keys($this->providerCreators),
        )));
    }

    /**
     * 检查驱动是否已注册
     *
     * @param string $driver 驱动名称
     * @return bool
     */
    public function hasDriver(string $driver): bool
    {
        return isset($this->adapters[$driver]) || isset($this->providerCreators[$driver]);
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

    private function loadProviderAdapters(ServiceProviderRegistry $serviceProviders): void
    {
        try {
            $implementations = $serviceProviders->implementationsWithPrefix(self::PROVIDER_CAPABILITY_PREFIX);
            \ksort($implementations);
            $providerDigest = \hash('sha256', \serialize($implementations));
            if (isset(self::$processProviderCreators[$providerDigest])) {
                $this->providerCreators = self::$processProviderCreators[$providerDigest];
                return;
            }

            $discovered = [];
            foreach ($implementations as $capability => $implementation) {
                if (!\class_exists($implementation)) {
                    throw new \RuntimeException("Cache adapter provider {$implementation} does not exist.");
                }

                $provider = new $implementation();
                if (!$provider instanceof CacheAdapterProviderInterface) {
                    throw new \RuntimeException(
                        "Cache adapter provider {$implementation} for {$capability} must implement "
                        . CacheAdapterProviderInterface::class,
                    );
                }

                foreach ($provider->descriptors() as $descriptor) {
                    if (!$descriptor instanceof CacheAdapterDescriptor) {
                        throw new \RuntimeException(
                            "Cache adapter provider {$implementation} returned a non-descriptor value.",
                        );
                    }
                    if (isset($this->adapters[$descriptor->driver]) || isset($discovered[$descriptor->driver])) {
                        throw new \RuntimeException(
                            "Cache driver {$descriptor->driver} is provided more than once.",
                        );
                    }
                    if (!\is_a($descriptor->creatorClass, CacheAdapterCreatorInterface::class, true)) {
                        throw new \RuntimeException(
                            "Cache adapter creator {$descriptor->creatorClass} for {$descriptor->driver} must implement "
                            . CacheAdapterCreatorInterface::class,
                        );
                    }

                    $creatorClass = $descriptor->creatorClass;
                    $creator = new $creatorClass();
                    if (!$creator instanceof CacheAdapterCreatorInterface) {
                        throw new \RuntimeException(
                            "Cache adapter creator {$creatorClass} for {$descriptor->driver} violates its contract.",
                        );
                    }
                    $discovered[$descriptor->driver] = $creator;
                }
            }

            \ksort($discovered);
            $this->providerCreators = self::$processProviderCreators[$providerDigest] = $discovered;
        } catch (\Throwable $throwable) {
            // Core drivers must remain usable in FPM/CLI even when no optional
            // module registry exists. Resolving a provided driver fails loudly.
            $this->providerCreators = [];
            $this->providerLoadFailure = $throwable->getMessage();
        }
    }
}
