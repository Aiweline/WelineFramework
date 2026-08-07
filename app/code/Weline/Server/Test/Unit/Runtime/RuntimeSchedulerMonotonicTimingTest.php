<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Event\Select;
use Weline\Server\EventLoop\EventExtLoop;
use Weline\Server\Scheduler\FiberScheduler;
use Weline\Server\Timer;

final class RuntimeSchedulerMonotonicTimingTest extends TestCase
{
    public function testSchedulerDeadlinesDoNotFollowWallClockMicrotime(): void
    {
        foreach ([FiberScheduler::class, Select::class, EventExtLoop::class, Timer::class] as $class) {
            $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

            self::assertStringNotContainsString('microtime(true)', $source, $class);
            self::assertStringContainsString('hrtime(true)', $source, $class);
        }
    }
}
