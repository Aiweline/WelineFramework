<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Php;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

final class FiberTaskRunnerTest extends TestCase
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

    public function testSchedulerDelayYieldsToOtherReadyFiber(): void
    {
        $events = [];
        $runner = new FiberTaskRunner(defaultConcurrency: 2);

        $results = $runner->run([
            'slow' => static function () use (&$events): string {
                $events[] = 'slow:start';
                SchedulerSystem::yieldDelay(10);
                $events[] = 'slow:end';

                return 'slow-result';
            },
            'fast' => static function () use (&$events): string {
                $events[] = 'fast';

                return 'fast-result';
            },
        ]);

        self::assertSame(['slow:start', 'fast', 'slow:end'], $events);
        self::assertSame([
            'slow' => 'slow-result',
            'fast' => 'fast-result',
        ], $results);
        self::assertFalse(SchedulerSystem::isSchedulerActive());
    }

    public function testEachFiberGetsIsolatedCopyOfParentWelineContext(): void
    {
        WelineEnv::set('area', 'backend');
        WelineEnv::set('ai_tenant_context', ['tenant_id' => 7]);

        $runner = new FiberTaskRunner(defaultConcurrency: 2);
        $results = $runner->run([
            'a' => static function (): array {
                WelineEnv::set('area', 'task_a');
                SchedulerSystem::yieldDelay(1);

                return [
                    'area' => WelineEnv::get('area'),
                    'tenant' => WelineEnv::get('ai_tenant_context'),
                ];
            },
            'b' => static function (): array {
                SchedulerSystem::yieldDelay(1);

                return [
                    'area' => WelineEnv::get('area'),
                    'tenant' => WelineEnv::get('ai_tenant_context'),
                ];
            },
        ]);

        self::assertSame('task_a', $results['a']['area']);
        self::assertSame('backend', $results['b']['area']);
        self::assertSame('backend', WelineEnv::get('area'));
        self::assertSame(['tenant_id' => 7], $results['a']['tenant']);
        self::assertSame(['tenant_id' => 7], $results['b']['tenant']);
    }

    public function testExceptionFromFiberIsRethrown(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $runner->run([
            'bad' => static function (): void {
                throw new \RuntimeException('boom');
            },
            'other' => static function (): void {
                FiberTaskRunner::yield();
            },
        ]);
    }

    public function testSchedulerActiveDoesNotForceSequentialFiberPool(): void
    {
        SchedulerSystem::enableScheduler();
        $events = [];
        $runner = new FiberTaskRunner(defaultConcurrency: 2);

        $results = $runner->run([
            'slow' => static function () use (&$events): string {
                $events[] = 'slow:start';
                SchedulerSystem::yieldDelay(12);
                $events[] = 'slow:end';

                return 'slow-result';
            },
            'fast' => static function () use (&$events): string {
                $events[] = 'fast';

                return 'fast-result';
            },
        ]);

        self::assertSame(['slow:start', 'fast', 'slow:end'], $events);
        self::assertSame([
            'slow' => 'slow-result',
            'fast' => 'fast-result',
        ], $results);
        self::assertTrue(SchedulerSystem::isSchedulerActive());
    }

    public function testNestedFiberPoolRunsTasksConcurrentlyInsideOuterFiber(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $events = [];
        $outer = new FiberTaskRunner(defaultConcurrency: 2);

        $results = $outer->run([
            'outer' => static function () use (&$events): array {
                self::assertInstanceOf(\Fiber::class, \Fiber::getCurrent());

                $nested = new FiberTaskRunner(defaultConcurrency: 3);
                $nestedResults = [];
                foreach ($nested->runEvents([
                    'a' => static function () use (&$events): string {
                        $events[] = 'a:start';
                        SchedulerSystem::yieldDelay(10);
                        $events[] = 'a:end';

                        return 'a-result';
                    },
                    'b' => static function () use (&$events): string {
                        $events[] = 'b:start';
                        SchedulerSystem::yieldDelay(10);
                        $events[] = 'b:end';

                        return 'b-result';
                    },
                    'c' => static function () use (&$events): string {
                        $events[] = 'c:start';
                        SchedulerSystem::yieldDelay(10);
                        $events[] = 'c:end';

                        return 'c-result';
                    },
                ]) as $taskKey => $event) {
                    self::assertSame('fulfilled', $event['status']);
                    $nestedResults[$taskKey] = $event['result'] ?? null;
                }

                return $nestedResults;
            },
        ]);

        self::assertSame(['a:start', 'b:start', 'c:start'], \array_slice($events, 0, 3));
        self::assertSame([
            'a' => 'a-result',
            'b' => 'b-result',
            'c' => 'c-result',
        ], $results['outer']);
        self::assertFalse(SchedulerSystem::isSchedulerActive());
    }
}
