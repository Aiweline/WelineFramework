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
 * 监听 Weline_Framework::scheduler::wait，根据 type 向 FiberScheduler 注册定时器 / I/O waiter。
 * I/O await 仅在 Worker 显式 enableIoWait()（CoroutineRuntime 驱动主循环）后生效。
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
                'io_readable' => self::registerIoWaiter($scheduler, $fiber, $params, false),
                'io_writable' => self::registerIoWaiter($scheduler, $fiber, $params, true),
                default => null,
            };
        });
    }

    public static function getScheduler(): ?FiberScheduler
    {
        return self::$scheduler;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function registerIoWaiter(
        FiberScheduler $scheduler,
        \Fiber $fiber,
        array $params,
        bool $writable
    ): void {
        if (!SchedulerSystem::isIoWaitEnabled()) {
            return;
        }
        $stream = $params['stream'] ?? null;
        if (!\is_resource($stream)) {
            return;
        }
        $timeout = (float) ($params['timeout'] ?? 0.0);
        if ($writable) {
            $scheduler->addWritableWaiter($fiber, $stream, $timeout);
        } else {
            $scheduler->addReadableWaiter($fiber, $stream, $timeout);
        }
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
            'yield' => self::$scheduler->addYieldTimer($fiber),
            'yield_delay' => self::$scheduler->addYieldDelayTimer($fiber, (int) ($data['milliseconds'] ?? 1)),
            'io_readable' => self::registerIoWaiter(self::$scheduler, $fiber, $data, false),
            'io_writable' => self::registerIoWaiter(self::$scheduler, $fiber, $data, true),
            default => null,
        };
    }
}
