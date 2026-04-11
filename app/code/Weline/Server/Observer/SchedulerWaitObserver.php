<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Scheduler\FiberScheduler;

/**
 * 调度器等待事件观察者
 *
 * 监听 Weline_Framework::scheduler::wait，根据 type 向 FiberScheduler 注册定时器。
 * 当调度器已激活且已注入 FiberScheduler 时注册定时器（含 Master Orchestrator 主循环 Fiber）。
 * FPM 或未 enableScheduler 时 SchedulerSystem 会走原生 sleep，通常不会派发本事件。
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
        SchedulerSystem::setWaitDispatcher(static function (string $type, array $params) use ($scheduler): void {
            $fiber = $params['fiber'] ?? \Fiber::getCurrent();
            if (!($fiber instanceof \Fiber)) {
                return;
            }

            match ($type) {
                'sleep' => $scheduler->addSleepTimer($fiber, (int) ($params['seconds'] ?? 1)),
                'usleep' => $scheduler->addUsleepTimer($fiber, (int) ($params['microseconds'] ?? 1000)),
                'yield' => $scheduler->addYieldTimer($fiber),
                'yield_delay' => $scheduler->addYieldDelayTimer($fiber, (int) ($params['milliseconds'] ?? 1)),
                default => null,
            };
        });
    }

    public static function getScheduler(): ?FiberScheduler
    {
        return self::$scheduler;
    }

    public function execute(Event &$event): void
    {
        if (!SchedulerSystem::isSchedulerActive() || !self::$scheduler) {
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
            // yield: 让出控制权，下一轮事件循环立即 resume（通过 getNextTimerDelay 返回 0 实现）
            'yield' => self::$scheduler->addYieldTimer($fiber),
            // yield_delay: 让出控制权并注册延迟定时器
            'yield_delay' => self::$scheduler->addYieldDelayTimer($fiber, (int) ($data['milliseconds'] ?? 1)),
            default => null,
        };
    }
}
