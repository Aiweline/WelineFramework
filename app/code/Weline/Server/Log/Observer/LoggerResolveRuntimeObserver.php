<?php

declare(strict_types=1);

/**
 * 日志运行模式解析观察者
 *
 * 在 WLS 进程内（ErrorBootstrap 已初始化）时将 runtime 设为 wls，
 * 使 LoggerFactory 使用 WlsLoggerAdapter，无需依赖 WELINE_SERVER_MODE 常量。
 */

namespace Weline\Server\Log\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Server\Log\Error\ErrorBootstrap;

class LoggerResolveRuntimeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (ErrorBootstrap::isInitialized()) {
            $event->setData('runtime', 'wls');
        }
    }
}
