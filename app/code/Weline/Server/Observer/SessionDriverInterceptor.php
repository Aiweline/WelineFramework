<?php

declare(strict_types=1);

/**
 * WLS 模式 Session 驱动接管 Observer（已废弃）
 *
 * 此 Observer 已被新的 SessionFactory 架构取代。
 * 新架构通过 SessionFactory::createStrategy() 自动选择 FpmStrategy 或 WlsStrategy，
 * 无需通过事件拦截来替换驱动。
 *
 * @deprecated 使用 Weline\Framework\Session\SessionFactory 替代
 * @see \Weline\Framework\Session\SessionFactory
 * @see \Weline\Framework\Session\Strategy\WlsStrategy
 *
 * @author Aiweline
 */

namespace Weline\Server\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * @deprecated 使用 SessionFactory 自动选择策略
 */
class SessionDriverInterceptor implements ObserverInterface
{
    private const FILE_SESSION_CLASS = \Weline\Framework\Session\Driver\File::class;

    /**
     * @inheritDoc
     * @deprecated 此方法不再生效，事件已禁用
     */
    public function execute(Event &$event): void
    {
        @\trigger_error(
            'SessionDriverInterceptor is deprecated. Use Weline\Framework\Session\SessionFactory instead.',
            E_USER_DEPRECATED
        );
    }
}
