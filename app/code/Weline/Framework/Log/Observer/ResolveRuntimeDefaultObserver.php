<?php

declare(strict_types=1);

namespace Weline\Framework\Log\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 日志运行模式解析的默认观察者（占位，保持配置默认值）
 * 其他模块（如 Weline_Server）可注册观察者将 runtime 改为 wls。
 */
class ResolveRuntimeDefaultObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // 不修改 runtime，沿用配置或事件传入的默认值
    }
}
