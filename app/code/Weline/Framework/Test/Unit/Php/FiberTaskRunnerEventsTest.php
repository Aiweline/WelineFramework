<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Php;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Php\CurlStreamPump;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

final class FiberTaskRunnerEventsTest extends TestCase
{
    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
    }

    public function testRunEventsYieldsAllFulfilledTasks(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 2);
        $events = [];
        foreach ($runner->runEvents([
            'a' => static function (): string { return 'A'; },
            'b' => static function (): string { return 'B'; },
        ]) as $key => $event) {
            $events[$key] = $event;
        }

        self::assertCount(2, $events);
        self::assertSame(['status' => 'fulfilled', 'result' => 'A'], $events['a']);
        self::assertSame(['status' => 'fulfilled', 'result' => 'B'], $events['b']);
    }

    public function testRunEventsCapturesPerTaskExceptionsAsRejectedEvents(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 2);
        $events = [];
        foreach ($runner->runEvents([
            'good' => static function (): string { return 'ok'; },
            'bad' => static function (): never {
                throw new \RuntimeException('boom');
            },
            'also' => static function (): string { return 'also'; },
        ]) as $key => $event) {
            $events[$key] = $event;
        }

        self::assertSame(['status' => 'fulfilled', 'result' => 'ok'], $events['good']);
        self::assertSame('rejected', $events['bad']['status']);
        self::assertInstanceOf(\RuntimeException::class, $events['bad']['error']);
        self::assertSame('boom', $events['bad']['error']->getMessage());
        self::assertSame(['status' => 'fulfilled', 'result' => 'also'], $events['also']);
    }

    public function testRunEventsExposesPumpInsideTasks(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 2);
        $pumps = [];
        foreach ($runner->runEvents([
            'a' => static function () use (&$pumps): bool {
                $pumps[] = FiberTaskRunner::currentPump();
                return true;
            },
            'b' => static function () use (&$pumps): bool {
                FiberTaskRunner::yield();
                $pumps[] = FiberTaskRunner::currentPump();
                return true;
            },
        ]) as $_) {
            // drain
        }

        self::assertCount(2, $pumps);
        foreach ($pumps as $pump) {
            self::assertInstanceOf(CurlStreamPump::class, $pump);
        }
        self::assertNull(FiberTaskRunner::currentPump());
    }

    public function testRunEventsYieldsResultsInCompletionOrder(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 3);
        $order = [];
        foreach ($runner->runEvents([
            'slow' => static function (): string {
                SchedulerSystem::yieldDelay(20);
                return 'slow';
            },
            'fast' => static function (): string {
                return 'fast';
            },
        ]) as $key => $event) {
            $order[] = $key;
        }

        // The fast task must complete before the slow one (which yieldDelay 20ms).
        self::assertSame(['fast', 'slow'], $order);
    }

    public function testRunEventsSequentialFallbackHonorsTryCatchPerTask(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 1);
        $events = [];
        foreach ($runner->runEvents([
            'first' => static function (): string { return '1'; },
            'second' => static function (): never {
                throw new \LogicException('serial-boom');
            },
            'third' => static function (): string { return '3'; },
        ]) as $key => $event) {
            $events[$key] = $event;
        }

        self::assertSame('fulfilled', $events['first']['status']);
        self::assertSame('rejected', $events['second']['status']);
        self::assertInstanceOf(\LogicException::class, $events['second']['error']);
        self::assertSame('fulfilled', $events['third']['status']);
        self::assertSame('3', $events['third']['result']);
    }
}
