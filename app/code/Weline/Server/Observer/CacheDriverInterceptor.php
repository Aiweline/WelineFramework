<?php

declare(strict_types=1);

/*
 * WLS 模式缓存驱动接管 Observer
 * 
 * 在 WLS 常驻内存模式下，将 File 缓存驱动替换为 WlsMemoryCache 驱动，
 * 大幅提升缓存读取性能（内存 vs 文件 I/O）。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 缓存驱动接管 Observer
 * 
 * 监听 Weline_Framework_Cache::driver_create_before 事件
 * 在 WLS 模式下将 File 驱动替换为 WlsMemoryCache 驱动
 */
class CacheDriverInterceptor implements ObserverInterface
{
    /**
     * WLS 内存缓存驱动类名
     */
    private const WLS_MEMORY_CACHE_CLASS = \Weline\Server\Extends\Module\Weline_Framework\Cache\WlsMemoryCache::class;
    
    /**
     * 框架 File 缓存驱动类名
     */
    private const FILE_CACHE_CLASS = \Weline\Framework\Cache\Driver\File::class;

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 仅在常驻内存模式下接管
        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return;
        }
        
        $data = $event->getData();
        $driverClass = $data['driver_class'] ?? '';
        $driver = $data['driver'] ?? '';
        
        // 仅接管 File 驱动
        if ($driverClass === self::FILE_CACHE_CLASS || \strtolower($driver) === 'file') {
            // 替换为 WLS 内存缓存驱动
            $event->setData('driver_class', self::WLS_MEMORY_CACHE_CLASS);
        }
    }
}
