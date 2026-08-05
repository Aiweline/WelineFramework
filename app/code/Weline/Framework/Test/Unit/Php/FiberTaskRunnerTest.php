<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Php;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;

final class FiberTaskRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
        Context::leave();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
        Context::leave();
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

    public function testNestedPoolReturnsControlToSuppressedWorkerScheduler(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $workerWaits = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(
            static function (string $type, array $params) use (&$workerWaits): void {
                $workerWaits[] = ['type' => $type, 'fiber' => $params['fiber'] ?? null];
            }
        );

        $requestFiber = new \Fiber(static function (): array {
            $runner = new FiberTaskRunner(defaultConcurrency: 2);

            return $runner->run([
                'first' => static fn (): string => 'first-result',
                'second' => static fn (): string => 'second-result',
            ]);
        });
        $requestFiber->start();

        self::assertTrue($requestFiber->isSuspended());
        self::assertSame('yield', $workerWaits[0]['type'] ?? null);
        self::assertSame($requestFiber, $workerWaits[0]['fiber'] ?? null);

        while (!$requestFiber->isTerminated()) {
            $requestFiber->resume();
        }

        self::assertSame([
            'first' => 'first-result',
            'second' => 'second-result',
        ], $requestFiber->getReturn());
        self::assertTrue(SchedulerSystem::isSchedulerActive());
    }

    public function testSingleTaskUsesCooperativePumpInsideActiveWorkerScheduler(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $workerWaits = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(
            static function (string $type, array $params) use (&$workerWaits): void {
                $workerWaits[] = ['type' => $type, 'fiber' => $params['fiber'] ?? null];
            }
        );

        $requestFiber = new \Fiber(static function (): array {
            $runner = new FiberTaskRunner(defaultConcurrency: 1);
            $settled = [];
            foreach ($runner->runEvents([
                'only' => static fn (): bool => FiberTaskRunner::currentPump() !== null,
            ], 1) as $key => $event) {
                $settled[$key] = $event;
            }

            return $settled;
        });
        $requestFiber->start();

        self::assertTrue($requestFiber->isSuspended());
        self::assertSame('yield', $workerWaits[0]['type'] ?? null);
        self::assertSame($requestFiber, $workerWaits[0]['fiber'] ?? null);

        while (!$requestFiber->isTerminated()) {
            $requestFiber->resume();
        }

        $result = $requestFiber->getReturn();
        self::assertSame('fulfilled', $result['only']['status'] ?? null);
        self::assertTrue($result['only']['result'] ?? false);
        self::assertTrue(SchedulerSystem::isSchedulerActive());
    }

    public function testChildFibersInheritAnIsolatedCopyOfRequestContext(): void
    {
        Context::enter(new Context());
        RequestContext::init();
        $heartbeat = static function (): string {
            return 'heartbeat';
        };
        RequestContext::set('test.heartbeat', $heartbeat);
        RequestContext::set('test.owner', 'parent');

        $runner = new FiberTaskRunner(defaultConcurrency: 2);
        $results = $runner->run([
            'writer' => static function (): array {
                $inheritedHeartbeat = RequestContext::get('test.heartbeat');
                RequestContext::set('test.owner', 'child');
                SchedulerSystem::yieldDelay(2);

                return [
                    'heartbeat' => $inheritedHeartbeat,
                    'owner' => RequestContext::get('test.owner'),
                ];
            },
            'reader' => static function (): string {
                SchedulerSystem::yieldDelay(1);

                return (string)RequestContext::get('test.owner');
            },
        ]);

        self::assertSame($heartbeat, $results['writer']['heartbeat']);
        self::assertSame('child', $results['writer']['owner']);
        self::assertSame('parent', $results['reader']);
        self::assertSame('parent', RequestContext::get('test.owner'));
    }

    public function testRunProgressReturnsTaskResultAndEmitsTaskAndHeartbeatProgress(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 1);
        $progress = $runner->runProgress(
            static function (): string {
                FiberTaskRunner::yield('provider_chunk');
                SchedulerSystem::yieldDelay(8);

                return 'done';
            },
            'heartbeat',
            0.001,
        );

        $events = [];
        foreach ($progress as $event) {
            $events[] = $event;
        }

        self::assertContains('provider_chunk', $events);
        self::assertContains('heartbeat', $events);
        self::assertSame('done', $progress->getReturn());
        self::assertFalse(SchedulerSystem::isSchedulerActive());
    }

    public function testNestedRunProgressYieldsRequestFiberToItsWorkerSchedulerFrame(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $workerWaits = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(
            static function (string $type, array $params) use (&$workerWaits): void {
                $workerWaits[] = ['type' => $type, 'fiber' => $params['fiber'] ?? null];
            }
        );

        $requestFiber = new \Fiber(static function (): array {
            $outer = new FiberTaskRunner(defaultConcurrency: 1);
            $progress = $outer->runProgress(
                static function (): string {
                    $inner = new FiberTaskRunner(defaultConcurrency: 1);
                    foreach ($inner->runEvents([
                        'provider' => static function (): string {
                            SchedulerSystem::yieldDelay(20);

                            return 'provider-result';
                        },
                    ], 1) as $event) {
                        self::assertSame('fulfilled', $event['status'] ?? null);
                    }

                    return 'done';
                },
                'heartbeat',
                0.001,
            );

            $events = [];
            foreach ($progress as $event) {
                $events[] = $event;
            }

            return ['events' => $events, 'result' => $progress->getReturn()];
        });
        $requestFiber->start();

        self::assertTrue($requestFiber->isSuspended());
        self::assertNotEmpty($workerWaits);
        self::assertSame($requestFiber, $workerWaits[0]['fiber'] ?? null);

        $resumeGuard = 0;
        while (!$requestFiber->isTerminated() && $resumeGuard < 100) {
            ++$resumeGuard;
            \usleep(2_000);
            $requestFiber->resume();
        }

        self::assertTrue($requestFiber->isTerminated());
        foreach ($workerWaits as $wait) {
            self::assertSame('yield', $wait['type'] ?? null);
            self::assertSame($requestFiber, $wait['fiber'] ?? null);
        }
        $result = $requestFiber->getReturn();
        self::assertContains('heartbeat', $result['events'] ?? []);
        self::assertSame('done', $result['result'] ?? null);
        self::assertTrue(SchedulerSystem::isSchedulerActive());
    }
}
