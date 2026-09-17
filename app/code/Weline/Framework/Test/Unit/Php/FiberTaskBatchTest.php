<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Php;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Php\FiberTaskBatch;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

final class FiberTaskBatchTest extends TestCase
{
    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
    }

    public function testSettleRunsProgressOnCallerAndCollectsResults(): void
    {
        $phases = [];
        $batch = new FiberTaskBatch(2, true);
        $settled = $batch->settle(
            [
                'a' => static function (): string {
                    FiberTaskRunner::yield();

                    return 'A';
                },
                'b' => static function (): string {
                    FiberTaskRunner::yield();

                    return 'B';
                },
            ],
            static function (string $phase, array $ctx) use (&$phases): void {
                $phases[] = $phase;
            },
            ['concurrency' => 2, 'fail_fast' => true, 'label' => 'unit']
        );

        self::assertSame(['A', 'B'], [
            $settled['results']['a'] ?? null,
            $settled['results']['b'] ?? null,
        ]);
        self::assertSame([], $settled['failed']);
        self::assertContains('start', $phases);
        self::assertContains('task', $phases);
        self::assertContains('done', $phases);
    }

    public function testFailFastThrowsAndNonFailFastCollects(): void
    {
        $batch = new FiberTaskBatch(2, true);
        $this->expectException(\RuntimeException::class);
        $batch->settle(
            [
                'ok' => static fn (): string => '1',
                'bad' => static function (): string {
                    throw new \RuntimeException('boom');
                },
            ],
            null,
            ['concurrency' => 1, 'fail_fast' => true]
        );
    }

    public function testNonFailFastCollectsFailures(): void
    {
        $batch = new FiberTaskBatch(1, true);
        $settled = $batch->settle(
            [
                'ok' => static fn (): string => '1',
                'bad' => static function (): string {
                    throw new \RuntimeException('boom');
                },
            ],
            null,
            ['concurrency' => 1, 'fail_fast' => false]
        );

        self::assertSame('1', $settled['results']['ok'] ?? null);
        self::assertCount(1, $settled['failed']);
        self::assertSame('bad', $settled['failed'][0]['key']);
    }

    public function testConcurrencyFromEnvHelper(): void
    {
        self::assertSame(7, FiberTaskRunner::concurrencyFromEnv(7));
        self::assertSame(4, FiberTaskRunner::concurrencyFromEnv(null, 'WELINE_FIBER_CONCURRENCY_MISSING_XYZ', 4));
    }

    public function testMapModulesYieldsPerModuleAndMerges(): void
    {
        $batch = new FiberTaskBatch(2, true);
        $settled = $batch->mapModules(
            [
                'M_A' => ['n' => 1],
                'M_B' => ['n' => 2],
                'M_C' => ['n' => 3],
            ],
            static function (string $name, mixed $payload): int {
                return (int)($payload['n'] ?? 0);
            },
            null,
            [
                'concurrency' => 2,
                'fail_fast' => true,
                'filter' => ['M_A', 'M_C'],
                'label' => 'map-modules-unit',
            ]
        );

        self::assertSame(2, $settled['total']);
        self::assertSame(1, $settled['results']['M_A'] ?? null);
        self::assertSame(3, $settled['results']['M_C'] ?? null);
        self::assertArrayNotHasKey('M_B', $settled['results']);
    }

    public function testKeepResultsFalseDiscardsReturnBag(): void
    {
        $seen = [];
        $batch = new FiberTaskBatch(1, true);
        $settled = $batch->mapModules(
            [
                'M1' => ['v' => 'big'],
                'M2' => ['v' => 'bag'],
            ],
            static function (string $name, mixed $payload): array {
                return ['name' => $name, 'payload' => $payload];
            },
            static function (string $phase, array $ctx) use (&$seen): void {
                if ($phase === 'task' && ($ctx['ok'] ?? false)) {
                    $seen[] = (string)($ctx['key'] ?? '');
                }
            },
            [
                'concurrency' => 1,
                'keep_results' => false,
                'fail_fast' => true,
            ]
        );

        self::assertSame([], $settled['results']);
        self::assertSame(2, $settled['succeeded']);
        self::assertSame(['M1', 'M2'], $seen);
    }
}
