<?php

declare(strict_types=1);

/*
 * WLS 模式 Session 驱动接管 Observer
 * 
 * 在 WLS 常驻内存模式下，将 File Session 驱动替换为 WlsMemorySession 驱动，
 * 大幅提升 Session 读写性能（内存 vs 文件 I/O）。
 * 
 * 可通过 env.php 中的 session.wls_managed 配置控制是否托管：
 * - true（默认）：使用 WlsMemorySession，内存 + 文件双写
 * - false：使用原生 PHP Session 机制
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Session 驱动接管 Observer
 * 
 * 监听 Weline_Framework_Session::driver_create_before 事件
 * 在 WLS 模式下将 File 驱动替换为 WlsMemorySession 驱动
 */
class SessionDriverInterceptor implements ObserverInterface
{
    /**
     * WLS 内存 Session 驱动类名
     */
    private const WLS_MEMORY_SESSION_CLASS = \Weline\Server\Extends\Module\Weline_Framework\Session\WlsMemorySession::class;
    
    /**
     * 框架 File Session 驱动类名
     */
    private const FILE_SESSION_CLASS = \Weline\Framework\Session\Driver\File::class;

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 仅在常驻内存模式下接管
        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return;
        }
        
        // 检查 session.wls_managed 配置，默认为 true（托管）
        $sessionConfig = Env::getInstance()->getConfig('session') ?: [];
        $wlsManaged = $sessionConfig['wls_managed'] ?? true;
        
        // 如果配置为不托管，直接返回，使用原生 Session 机制
        if (!$wlsManaged) {
            return;
        }
        
        $data = $event->getData();
        $driverClass = $data['driver_class'] ?? '';
        $driver = $data['driver'] ?? '';
        
        // 仅接管 File 驱动
        if ($driverClass === self::FILE_SESSION_CLASS || \strtolower($driver) === 'file') {
            // 替换为 WLS 内存 Session 驱动
            $event->setData('driver_class', self::WLS_MEMORY_SESSION_CLASS);
        }
    }
}
