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
     * I/O await is opt-in: only Worker loops that merge Fiber socket waiters into
     * CoroutineRuntime::wait() may enable it. Master / EventBuffer Workers stay
     * on sync stream_select fallback so Fibers are never suspended without a waker.
     */
    private static bool $ioWaitEnabled = false;
    /** @var null|callable(string, array): void */
    private static $waitDispatcher = null;

    /**
     * WLS 全局调度已激活时，暂停其全局标记与 waitDispatcher，便于
     * {@see \Weline\Framework\Php\FiberTaskRunner} / {@see \Weline\Ai\Service\AiService::runCooperativeSessionTasks()}
     * 安装本地 Fiber 等待环与并发池。返回的闭包必须在 finally 中调用以恢复。
     */
    public static function suppressGlobalSchedulerMomentarily(): \Closure
    {
        if (!self::$schedulerActive) {
            return static function (): void {};
        }

        /** @var null|callable(string,array):void $savedDispatcher */
        $savedDispatcher = self::$waitDispatcher;
        $savedIoWait = self::$ioWaitEnabled;
        self::$schedulerActive = false;
        self::$ioWaitEnabled = false;
        self::$waitDispatcher = null;

        return static function () use ($savedDispatcher, $savedIoWait): void {
            self::$schedulerActive = true;
            self::$ioWaitEnabled = $savedIoWait;
            self::$waitDispatcher = $savedDispatcher;
        };
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function runWithoutGlobalScheduler(callable $callback): mixed
    {
        $restore = self::suppressGlobalSchedulerMomentarily();

        try {
            return $callback();
        } finally {
            $restore();
        }
    }

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
        self::$ioWaitEnabled = false;
        self::$waitDispatcher = null;
    }

    /**
     * 调度器是否激活
     */
    public static function isSchedulerActive(): bool
    {
        return self::$schedulerActive;
    }

    /**
     * Enable Fiber socket readable/writable await when the active Worker loop
     * actually drives CoroutineRuntime::wait() (worker.php / worker_ssl.php).
     */
    public static function enableIoWait(): void
    {
        self::$ioWaitEnabled = true;
    }

    public static function disableIoWait(): void
    {
        self::$ioWaitEnabled = false;
    }

    public static function isIoWaitEnabled(): bool
    {
        return self::$ioWaitEnabled;
    }

    /**
     * Suspend the current Fiber until $stream is readable, or fall back to a
     * bounded stream_select when I/O await is unavailable.
     *
     * @param resource $stream
     */
    public static function awaitReadable(mixed $stream, float $timeoutSec): bool
    {
        return self::awaitSocket($stream, false, $timeoutSec);
    }

    /**
     * Suspend the current Fiber until $stream is writable, or fall back to a
     * bounded stream_select when I/O await is unavailable.
     *
     * @param resource $stream
     */
    public static function awaitWritable(mixed $stream, float $timeoutSec): bool
    {
        return self::awaitSocket($stream, true, $timeoutSec);
    }

    /**
     * @param resource $stream
     */
    private static function awaitSocket(mixed $stream, bool $writable, float $timeoutSec): bool
    {
        if (!\is_resource($stream)) {
            return false;
        }

        $timeoutSec = \max(0.0, $timeoutSec);
        if (!self::$schedulerActive || !self::$ioWaitEnabled || !\Fiber::getCurrent()) {
            return self::selectOnce($stream, $writable, $timeoutSec);
        }
        if (!self::prepareCurrentFiberForSuspend()) {
            return self::selectOnce($stream, $writable, $timeoutSec);
        }

        self::dispatchWait($writable ? 'io_writable' : 'io_readable', [
            'stream' => $stream,
            'timeout' => $timeoutSec,
            'fiber' => \Fiber::getCurrent(),
        ]);
        $result = self::suspendCurrentFiber();

        return $result === true;
    }

    /**
     * @param resource $stream
     */
    private static function selectOnce(mixed $stream, bool $writable, float $timeoutSec): bool
    {
        $read = $writable ? null : [$stream];
        $write = $writable ? [$stream] : null;
        $except = null;
        $sec = (int) $timeoutSec;
        $usec = (int) \round(($timeoutSec - $sec) * 1_000_000);
        if ($usec < 0) {
            $usec = 0;
        }
        if ($timeoutSec > 0.0 && $sec === 0 && $usec === 0) {
            $usec = 1;
        }

        $ready = @\stream_select($read, $write, $except, $sec, $usec);

        return $ready !== false && $ready > 0;
    }

    /**
     * 注入调度等待分发器，WLS 运行时优先直连调度器，避免为 sleep/yield 再走事件系统。
     *
     * @param null|callable(string, array): void $dispatcher
     */
    public static function setWaitDispatcher(?callable $dispatcher): void
    {
        self::$waitDispatcher = $dispatcher;
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
        if (!self::prepareCurrentFiberForSuspend()) {
            \sleep($seconds);
            return;
        }

        self::dispatchWait('sleep', ['seconds' => $seconds]);
        self::suspendCurrentFiber();
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
        if (!self::prepareCurrentFiberForSuspend()) {
            \usleep($microseconds);
            return;
        }

        self::dispatchWait('usleep', ['microseconds' => $microseconds]);
        self::suspendCurrentFiber();
    }

    /**
     * 向调度器派发等待请求
     */
    private static function dispatchWait(string $type, array $params): void
    {
        if (\is_callable(self::$waitDispatcher)) {
            (self::$waitDispatcher)($type, $params);
            return;
        }

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
        if (!self::prepareCurrentFiberForSuspend()) {
            return;
        }

        self::dispatchWait('yield', []);
        self::suspendCurrentFiber();
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
        if (!self::prepareCurrentFiberForSuspend()) {
            \usleep($milliseconds * 1000);
            return;
        }

        self::dispatchWait('yield_delay', ['milliseconds' => $milliseconds]);
        self::suspendCurrentFiber();
    }

    private static function prepareCurrentFiberForSuspend(): bool
    {
        if (!Runtime::isPersistent()) {
            return true;
        }

        return FiberOutputBuffer::flushBeforeYield();
    }

    private static function suspendCurrentFiber(): mixed
    {
        try {
            return \Fiber::suspend();
        } catch (\FiberError $e) {
            if (\str_contains($e->getMessage(), 'force-closed fiber')) {
                return null;
            }
            throw $e;
        }
    }
}
