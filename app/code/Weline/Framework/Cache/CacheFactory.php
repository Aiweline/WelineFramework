<?php

declare(strict_types=1);

/**
 * 缓存工厂（兼容层）
 * 
 * 提供旧 CacheFactory 模式的兼容实现，内部桥接到新的 CacheManager/CachePool 架构。
 * 模块级缓存可以继承此类并在构造函数中设置 identity。
 * 
 * @deprecated 推荐直接使用 CacheManager::pool('identity') 获取 CachePoolInterface
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\Cache\Contract\CachePoolInterface;

class CacheFactory implements CacheFactoryInterface
{
    protected string $identity;
    protected string $tip;
    protected bool $permanently;
    private ?CacheManager $cacheManager = null;
    private ?CachePoolInterface $pool = null;

    public function __construct(
        string $identity = 'default',
        string $tip = '',
        bool $permanently = false
    ) {
        $this->identity = $identity;
        $this->tip = $tip;
        $this->permanently = $permanently;
    }

    /**
     * 创建/获取缓存池实例
     *
     * @param string $driver 缓存驱动（兼容参数，新架构中由 CacheManager 统一管理）
     * @param string $tip 缓存说明
     * @return CachePoolInterface
     */
    public function create(string $driver = '', string $tip = ''): CachePoolInterface
    {
        if ($this->pool === null) {
            $this->pool = $this->getCacheManager()->pool($this->identity);
        }
        return $this->pool;
    }

    /**
     * 是否为持久缓存
     */
    public function isKeep(): bool
    {
        return $this->permanently;
    }

    /**
     * 获取缓存标识
     */
    public function getIdentity(): string
    {
        return $this->identity;
    }

    /**
     * 获取缓存管理器实例
     */
    protected function getCacheManager(): CacheManager
    {
        if ($this->cacheManager === null) {
            $this->cacheManager = new CacheManager();
        }
        return $this->cacheManager;
    }
}
