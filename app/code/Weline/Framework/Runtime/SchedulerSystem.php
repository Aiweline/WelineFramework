<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 调度感知的阻塞原语封装
 *
 * 业务代码统一调用本类方法替代 PHP 原生 sleep/usleep。
 * - FPM / CLI（无调度器）：直接调用原生函数
 * - WLS（有调度器）：dispatch 事件 + Fiber::suspend()，由调度器定时器 resume
 */
class SchedulerSystem
{
    private static bool $schedulerActive = false;

    /**
     * 标记调度器已激活（由 WLS Server 模块在 Worker 启动时调用）
     */
    public static function enableScheduler(): void
    {
        self::$schedulerActive = true;
    }

    /**
     * 标记调度器已停用
     */
    public static function disableScheduler(): void
    {
        self::$schedulerActive = false;
    }

    /**
     * 调度器是否激活
     */
    public static function isSchedulerActive(): bool
    {
        return self::$schedulerActive;
    }

    /**
     * 替代 \sleep()
     *
     * WLS 下挂起 Fiber 并注册定时器；FPM/CLI 下调用原生 sleep。
     */
    public static function sleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (!self::$schedulerActive || !\Fiber::getCurrent()) {
            \sleep($seconds);
            return;
        }

        self::dispatchWait('sleep', ['seconds' => $seconds]);
        \Fiber::suspend();
    }

    /**
     * 替代 \usleep()
     *
     * WLS 下挂起 Fiber 并注册定时器；FPM/CLI 下调用原生 usleep。
     */
    public static function usleep(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if (!self::$schedulerActive || !\Fiber::getCurrent()) {
            \usleep($microseconds);
            return;
        }

        self::dispatchWait('usleep', ['microseconds' => $microseconds]);
        \Fiber::suspend();
    }

    /**
     * 向调度器派发等待请求
     */
    private static function dispatchWait(string $type, array $params): void
    {
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventData = [
            'data' => \array_merge(['type' => $type, 'fiber' => \Fiber::getCurrent()], $params),
        ];
        $eventsManager->dispatch('Weline_Framework::scheduler::wait', $eventData);
    }

    /**
     * 让出控制权给其他 Fiber（I/O 让步）
     *
     * 在长连接场景中，每次发送数据后调用此方法让出控制权，
     * 使 Worker 可以处理其他请求，避免一个长连接独占整个 Worker。
     *
     * WLS 下挂起当前 Fiber 并注册 0 延迟定时器，下一轮事件循环自动 resume；
     * FPM/CLI 下无操作（因为没有其他 Fiber 需要处理）。
     */
    public static function yield(): void
    {
        if (!self::$schedulerActive || !\Fiber::getCurrent()) {
            return;
        }

        self::dispatchWait('yield', []);
        \Fiber::suspend();
    }

    /**
     * 让出控制权并注册延迟定时器（用于限速场景）
     *
     * @param int $milliseconds 延迟毫秒数
     */
    public static function yieldDelay(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            self::yield();
            return;
        }

        if (!self::$schedulerActive || !\Fiber::getCurrent()) {
            \usleep($milliseconds * 1000);
            return;
        }

        self::dispatchWait('yield_delay', ['milliseconds' => $milliseconds]);
        \Fiber::suspend();
    }
}
