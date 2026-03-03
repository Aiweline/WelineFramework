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
use Weline\Framework\Runtime\Runtime;

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
     */
    private const DEFAULT_POOL_CONFIG = [
        'ttl' => 1800,
        'permanent' => false,
        'taggable' => false,
        'tip' => '',
    ];

    /**
     * 预定义池配置
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
        'session' => ['ttl' => 7200, 'tip' => '会话缓存'],
        'request' => ['ttl' => 300, 'tip' => '请求缓存'],
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
        'queue' => ['ttl' => 300, 'tip' => '队列缓存'],
        'system_config' => ['ttl' => 3600, 'tip' => '系统配置缓存'],
        'product' => ['ttl' => 1800, 'tip' => '产品缓存'],
        'file_manager' => ['ttl' => 86400, 'permanent' => true, 'tip' => '文件管理器缓存'],
        'editor' => ['ttl' => 86400, 'permanent' => true, 'tip' => '编辑器缓存'],
        'api_doc' => ['ttl' => 3600, 'tip' => 'API文档缓存'],
        'fpc' => ['ttl' => 3600, 'taggable' => true, 'tip' => '全页缓存'],
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
        foreach ($this->pools as $pool) {
            if (!$pool->isPermanent()) {
                $pool->clear();
            }
        }
    }

    public function flushAll(): void
    {
        foreach ($this->pools as $pool) {
            $pool->clear();
        }
    }

    public function getAllStats(): array
    {
        $stats = [];
        
        foreach ($this->pools as $identity => $pool) {
            $stats[$identity] = $pool->getStats();
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

        if ($taggable) {
            return new TaggableCachePool($identity, $adapter, $tip, $permanent, $ttl);
        }

        return new CachePool($identity, $adapter, $tip, $permanent, $ttl);
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
            if (isset($poolConfig['wls_driver'])) {
                return $poolConfig['wls_driver'];
            }
            if (isset($this->config['wls_default'])) {
                return $this->config['wls_default'];
            }
            return 'wls_memory';
        }

        if (isset($poolConfig['driver'])) {
            return $poolConfig['driver'];
        }

        return $this->config['default'] ?? 'file';
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
