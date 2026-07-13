<?php

declare(strict_types=1);

/**
 * 缓存管理器
 * 
 * 提供缓存池的统一获取入口（SRP: 只负责池的管理和创建）。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CacheManagerInterface;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\TaggableInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Pool\TaggableCachePool;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\RuntimeRoutingPolicyInterface;

class CacheManager implements CacheManagerInterface
{
    /**
     * 池注册表
     * 
     * @var array<string, CachePoolInterface>
     */
    private array $pools = [];

    /**
     * 配置
     */
    private array $config;

    /**
     * 适配器工厂
     */
    private AdapterFactory $adapterFactory;

    /**
     * 默认池配置
     *
     * jitter 字段为 TTL 抖动比例（0 ~ 0.5）。permanent=true 池强制 0，
     * 实际值不会真正叠加，仅作语义记录；CachePool 内部会自动忽略。
     */
    private const DEFAULT_POOL_CONFIG = [
        'ttl' => 1800,
        'permanent' => false,
        'taggable' => false,
        'enabled' => true,
        'tip' => '',
        'jitter' => CachePool::DEFAULT_JITTER_RATIO,
        'environment_scoped' => false,
    ];

    /**
     * 预定义池配置
     *
     * jitter 缺省值由 DEFAULT_POOL_CONFIG 提供；短 TTL 池显式置 0 避免命中精度损失。
     */
    private const PREDEFINED_POOLS = [
        'router' => ['ttl' => 86400, 'permanent' => true, 'tip' => '路由缓存'],
        'config' => ['ttl' => 0, 'permanent' => true, 'tip' => '配置缓存'],
        'database' => ['ttl' => 1800, 'tip' => '数据库缓存'],
        'view' => ['ttl' => 3600, 'tip' => '视图缓存'],
        'phrase' => ['ttl' => 86400, 'permanent' => true, 'tip' => '翻译缓存'],
        'plugin' => ['ttl' => 86400, 'permanent' => true, 'tip' => '插件缓存'],
        'event' => ['ttl' => 0, 'permanent' => true, 'tip' => '事件缓存'],
        'hook' => ['ttl' => 86400, 'tip' => '钩子缓存'],
        'controller' => ['ttl' => 86400, 'permanent' => true, 'tip' => '控制器缓存'],
        'session' => ['ttl' => 7200, 'tip' => '会话缓存', 'jitter' => 0.0],
        'request' => ['ttl' => 300, 'tip' => '请求缓存', 'jitter' => 0.0],
        'object' => ['ttl' => 86400, 'permanent' => true, 'tip' => '对象缓存'],
        'acl' => ['ttl' => 3600, 'tip' => '权限缓存'],
        'currency' => ['ttl' => 3600, 'tip' => '货币缓存'],
        'i18n' => ['ttl' => 86400, 'permanent' => true, 'tip' => '国际化缓存'],
        'theme' => ['ttl' => 3600, 'tip' => '主题缓存'],
        'url_rewrite' => ['ttl' => 86400, 'tip' => 'URL重写缓存'],
        'website' => ['ttl' => 3600, 'tip' => '网站缓存'],
        'module_router' => ['ttl' => 86400, 'permanent' => true, 'tip' => '模块路由缓存'],
        'taglib' => ['ttl' => 86400, 'permanent' => true, 'tip' => '标签库缓存'],
        'eav' => ['ttl' => 1800, 'tip' => 'EAV缓存'],
        'queue' => ['ttl' => 300, 'tip' => '队列缓存', 'jitter' => 0.0],
        'system_config' => ['ttl' => 3600, 'tip' => '系统配置缓存'],
        'product' => ['ttl' => 1800, 'tip' => '产品缓存'],
        'file_manager' => ['ttl' => 86400, 'permanent' => true, 'tip' => '文件管理器缓存'],
        'editor' => ['ttl' => 86400, 'permanent' => true, 'tip' => '编辑器缓存'],
        'api_doc' => ['ttl' => 3600, 'tip' => 'API文档缓存'],
        'fpc' => ['ttl' => 3600, 'taggable' => true, 'tip' => '全页缓存', 'environment_scoped' => true],
        'single_flight' => ['ttl' => 30, 'tip' => '请求合并锁池', 'jitter' => 0.0],
        'hot_key_tracker' => ['ttl' => 60, 'tip' => '热点 Key 跟踪', 'jitter' => 0.0],
        'url_guard' => ['ttl' => 1800, 'tip' => 'URL 越界规则缓存'],
        'default' => ['ttl' => 1800, 'tip' => '默认缓存'],
    ];

    public function __construct(?AdapterFactory $adapterFactory = null)
    {
        $this->config = (array) Env::getInstance()->getConfig('cache');
        $this->adapterFactory = $adapterFactory ?? new AdapterFactory();
    }

    public function pool(string $identity): CachePoolInterface
    {
        if (!isset($this->pools[$identity])) {
            $this->pools[$identity] = $this->createPool($identity);
        }
        
        return $this->pools[$identity];
    }

    public function hasPool(string $identity): bool
    {
        return isset($this->pools[$identity]) || isset($this->config['pools'][$identity]) || isset(self::PREDEFINED_POOLS[$identity]);
    }

    public function getPoolIdentities(): array
    {
        $configured = array_keys($this->config['pools'] ?? []);
        $predefined = array_keys(self::PREDEFINED_POOLS);
        $created = array_keys($this->pools);
        
        return array_unique(array_merge($configured, $predefined, $created));
    }

    public function invalidateTag(string $tag): void
    {
        foreach ($this->pools as $pool) {
            if ($pool instanceof TaggableInterface) {
                $pool->invalidateTags([$tag]);
            }
        }
    }

    public function clearAll(): void
    {
        foreach ($this->getPoolIdentities() as $identity) {
            $pool = $this->pool($identity);
            if (!$pool->isPermanent()) {
                $pool->clear();
            }
        }
    }

    public function flushAll(): void
    {
        foreach ($this->getPoolIdentities() as $identity) {
            $this->pool($identity)->clear();
        }
    }

    public function getAllStats(): array
    {
        $stats = [];

        foreach ($this->getPoolIdentities() as $identity) {
            $stats[$identity] = $this->pool($identity)->getStats();
        }

        return $stats;
    }

    /**
     * 创建缓存池
     */
    private function createPool(string $identity): CachePoolInterface
    {
        $poolConfig = $this->getPoolConfig($identity);
        $driverName = $this->resolveDriver($identity, $poolConfig);

        $adapter = $this->adapterFactory->create($driverName, $identity);

        $tip = $poolConfig['tip'] ?? '';
        $permanent = $poolConfig['permanent'] ?? false;
        $ttl = $poolConfig['ttl'] ?? 1800;
        $taggable = $poolConfig['taggable'] ?? false;
        $enabled = $poolConfig['enabled'] ?? true;
        $jitter = (float)($poolConfig['jitter'] ?? CachePool::DEFAULT_JITTER_RATIO);
        $environmentScoped = (bool)($poolConfig['environment_scoped'] ?? false);

        if ($taggable) {
            return new TaggableCachePool(
                $identity,
                $adapter,
                $tip,
                (bool)$permanent,
                (int)$ttl,
                (bool)$enabled,
                $jitter,
                $environmentScoped
            );
        }

        return new CachePool(
            $identity,
            $adapter,
            $tip,
            (bool)$permanent,
            (int)$ttl,
            (bool)$enabled,
            $jitter,
            $environmentScoped
        );
    }

    /**
     * 获取池配置
     */
    private function getPoolConfig(string $identity): array
    {
        $config = self::DEFAULT_POOL_CONFIG;

        if (isset(self::PREDEFINED_POOLS[$identity])) {
            $config = array_merge($config, self::PREDEFINED_POOLS[$identity]);
        }

        if (isset($this->config['status'][$identity])) {
            $config['enabled'] = (bool)$this->config['status'][$identity];
        }

        if (isset($this->config['pools'][$identity])) {
            $config = array_merge($config, $this->config['pools'][$identity]);
        }

        return $config;
    }

    /**
     * 解析驱动名称
     */
    private function resolveDriver(string $identity, array $poolConfig): string
    {
        if (Runtime::isPersistent()) {
            $configuredDriver = (string)($poolConfig['driver'] ?? $this->config['default'] ?? 'file');
            $configuredDriver = \strtolower(\trim($configuredDriver));
            if ($configuredDriver === '') {
                $configuredDriver = 'file';
            }

            // WLS 常驻模式下仅接管 file，其他驱动保持原样
            if ($configuredDriver === 'file' && $this->shouldHijackFileToWlsMemory()) {
                return 'wls_memory';
            }

            return $configuredDriver;
        }

        if (isset($poolConfig['driver'])) {
            return $poolConfig['driver'];
        }

        return $this->config['default'] ?? 'file';
    }

    /**
     * 是否启用 file -> wls_memory 接管策略。
     */
    private function shouldHijackFileToWlsMemory(): bool
    {
        try {
            $policy = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(RuntimeRoutingPolicyInterface::class);
            if ($policy instanceof RuntimeRoutingPolicyInterface) {
                return $policy->shouldHijackCacheFile();
            }
        } catch (\Throwable) {
        }
        // Master 策略尚未下发时，使用安全默认值（接管 file）
        return true;
    }

    /**
     * 获取适配器工厂
     */
    public function getAdapterFactory(): AdapterFactory
    {
        return $this->adapterFactory;
    }

    /**
     * 重置所有池统计
     */
    public function resetAllStats(): void
    {
        foreach ($this->pools as $pool) {
            if ($pool instanceof CachePool) {
                $pool->resetStats();
            }
        }
    }
}
