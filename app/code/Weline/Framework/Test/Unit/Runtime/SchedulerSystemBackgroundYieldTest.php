<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;

final class SchedulerSystemBackgroundYieldTest extends TestCase
{
    /** @var list<string> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->dispatched = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(function (string $type, array $payload): void {
            $this->dispatched[] = $type;
        });
    }

    protected function tearDown(): void
    {
        SchedulerSystem::setForegroundBusyProbe(null);
        SchedulerSystem::disableScheduler();
    }

    public function testBackgroundFiberParksWhileForegroundIsBusy(): void
    {
        $busyChecks = 3;
        SchedulerSystem::setForegroundBusyProbe(static function () use (&$busyChecks): bool {
            return $busyChecks-- > 0;
        });

        $fiber = new \Fiber(static function (): void {
            SchedulerSystem::markCurrentFiberBackground();
            SchedulerSystem::yield();
        });
        $fiber->start();
        while ($fiber->isSuspended()) {
            $fiber->resume();
        }

        self::assertSame(['yield_delay', 'yield_delay', 'yield_delay'], $this->dispatched);
    }

    public function testBackgroundFiberYieldsNormallyWhenForegroundIsIdle(): void
    {
        SchedulerSystem::setForegroundBusyProbe(static fn(): bool => false);

        $fiber = new \Fiber(static function (): void {
            SchedulerSystem::markCurrentFiberBackground();
            SchedulerSystem::yield();
        });
        $fiber->start();
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame(['yield'], $this->dispatched);
    }

    public function testForegroundFiberNeverParks(): void
    {
        SchedulerSystem::setForegroundBusyProbe(static fn(): bool => true);

        $fiber = new \Fiber(static fn() => SchedulerSystem::yield());
        $fiber->start();
        $fiber->resume();

        self::assertSame(['yield'], $this->dispatched);
    }

    public function testParkingIsBoundedSoBackgroundWorkCannotStarveForever(): void
    {
        SchedulerSystem::setForegroundBusyProbe(static fn(): bool => true, 20);

        $fiber = new \Fiber(static function (): void {
            SchedulerSystem::markCurrentFiberBackground();
            SchedulerSystem::yield();
        });
        $started = \microtime(true);
        $fiber->start();
        while ($fiber->isSuspended()) {
            \usleep(2000);
            $fiber->resume();
            self::assertLessThan(2.0, \microtime(true) - $started);
        }

        self::assertNotEmpty($this->dispatched);
        self::assertContains('yield_delay', $this->dispatched);
    }
}
