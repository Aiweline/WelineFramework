<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Test\Unit\Service;

use Agent\CursorSupervisor\Service\WatchdogService;
use PHPUnit\Framework\TestCase;

final class WatchdogServiceIntervalTest extends TestCase
{
    public function testLoopDelayUsesMilliseconds(): void
    {
        $service = new WatchdogService();

        $service->setCheckInterval(2);
        self::assertSame(2000, $service->getLoopDelayMilliseconds());
    }

    public function testLoopDelayHasMinimumOneSecond(): void
    {
        $service = new WatchdogService();

        $service->setCheckInterval(0);
        self::assertSame(1000, $service->getLoopDelayMilliseconds());
    }
}
