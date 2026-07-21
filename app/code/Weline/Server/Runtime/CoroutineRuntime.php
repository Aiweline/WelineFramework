<?php
declare(strict_types=1);

namespace Weline\Server\Runtime;

use Weline\Server\EventLoop\EventLoopInterface;
use Weline\Server\Scheduler\FiberScheduler;

final class CoroutineRuntime
{
    private const MIN_READY_TIMER_WAIT_USEC = 1000;

    public function __construct(
        private readonly EventLoopInterface $loop,
        private readonly FiberScheduler $scheduler
    ) {}

    /**
     * @param array<int|string, resource> $read
     * @param array<int|string, resource> $write
     * @param array<int|string, resource> $except
     */
    public function wait(array &$read, array &$write, array &$except, int $defaultUsec = 100000): int|false
    {
        $this->scheduler->collectIoWaitStreams($read, $write);

        $timeoutSec = 0;
        $timeoutUsec = $defaultUsec;
        $nextDelay = $this->scheduler->getNextTimerDelay();
        if ($nextDelay !== null) {
            if ($nextDelay <= 0.0) {
                $delayUsec = 0;
            } else {
                $delayUsec = (int) \ceil($nextDelay * 1_000_000);
                if ($delayUsec < self::MIN_READY_TIMER_WAIT_USEC && $defaultUsec > 0) {
                    $delayUsec = \min($defaultUsec, self::MIN_READY_TIMER_WAIT_USEC);
                }
            }
            if ($delayUsec < $timeoutUsec) {
                $timeoutUsec = \max(0, $delayUsec);
            }
        }

        $readyRead = $read;
        $readyWrite = $write;
        $readyExcept = $except;
        $changed = $this->loop->wait($readyRead, $readyWrite, $readyExcept, $timeoutSec, $timeoutUsec);

        // Always publish the ready sets back to the caller (stream_select semantics).
        $read = \is_array($readyRead) ? $readyRead : [];
        $write = \is_array($readyWrite) ? $readyWrite : [];
        $except = \is_array($readyExcept) ? $readyExcept : [];

        if ($changed !== false) {
            $this->scheduler->markIoReady($read, $write);
        }

        return $changed;
    }

    public function getLoopBackend(): string
    {
        return $this->loop->backend();
    }
}
