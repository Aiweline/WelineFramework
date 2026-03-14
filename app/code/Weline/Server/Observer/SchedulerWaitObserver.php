<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Scheduler\FiberScheduler;

/**
 * 调度器等待事件观察者
 *
 * 监听 Weline_Framework::scheduler::wait，根据 type 向 FiberScheduler 注册定时器。
 * 仅在 WLS 模式下有实际逻辑；FPM/CLI 下直接 return。
 */
class SchedulerWaitObserver implements ObserverInterface
{
    private static ?FiberScheduler $scheduler = null;

    /**
     * 由 Worker 在启动时注入 FiberScheduler 实例
     */
    public static function setScheduler(FiberScheduler $scheduler): void
    {
        self::$scheduler = $scheduler;
    }

    public static function getScheduler(): ?FiberScheduler
    {
        return self::$scheduler;
    }

    public function execute(Event &$event): void
    {
        if (!Runtime::isWls() || !SchedulerSystem::isSchedulerActive() || !self::$scheduler) {
            return;
        }

        $data = $event->getData('data');
        if (!$data || !isset($data['type'], $data['fiber'])) {
            return;
        }

        $fiber = $data['fiber'];
        if (!($fiber instanceof \Fiber)) {
            return;
        }

        match ($data['type']) {
            'sleep' => self::$scheduler->addSleepTimer($fiber, (int) ($data['seconds'] ?? 1)),
            'usleep' => self::$scheduler->addUsleepTimer($fiber, (int) ($data['microseconds'] ?? 1000)),
            default => null,
        };
    }
}
