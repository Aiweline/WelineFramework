<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Observability\MetricsSnapshotWriter;
use Weline\Server\Service\AttackLogService;
use Weline\Server\Service\BatchManager;
use Weline\Server\Service\FileWatcher;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\SessionStateFacade;
use Weline\Server\Service\WlsPerformanceTraceStore;

final class RuntimeMaintenanceMonotonicTimingTest extends TestCase
{
    /**
     * @dataProvider runtimeMaintenanceClassProvider
     */
    public function testMaintenanceCadenceTimeoutsAndBackoffUseMonotonicClock(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringNotContainsString('\\microtime(true)', $source, $class);
        self::assertStringContainsString('\\hrtime(true)', $source, $class);
    }

    public function testBatchAndAttackProtocolTimestampsRemainWallClock(): void
    {
        $batch = (string)\file_get_contents((new \ReflectionClass(BatchManager::class))->getFileName());
        $attack = (string)\file_get_contents((new \ReflectionClass(AttackLogService::class))->getFileName());

        self::assertStringContainsString("'created_at' => \$wallNow", $batch);
        self::assertStringContainsString("'deadline_monotonic' => \$deadlineMonotonic", $batch);
        self::assertStringContainsString("'detection_time' => \\time()", $attack);
    }

    public function testBatchTimeoutUsesItsPrivateMonotonicDeadline(): void
    {
        $manager = new BatchManager();
        $id = $manager->createOperation('reload', 'command', timeout: 5.0);
        self::assertTrue($manager->startOperation($id));

        $property = new \ReflectionProperty(BatchManager::class, 'operations');
        $operations = $property->getValue($manager);
        self::assertEqualsWithDelta(
            5.0,
            $operations[$id]['deadline_monotonic'] - $operations[$id]['created_monotonic'],
            0.001,
        );
        self::assertEqualsWithDelta((float)\time(), $operations[$id]['created_at'], 2.0);

        $operations[$id]['expires_at'] = (float)\time() + 3600.0;
        $operations[$id]['deadline_monotonic'] = (\hrtime(true) / 1_000_000_000) - 1.0;
        $property->setValue($manager, $operations);

        self::assertSame([$id], $manager->checkTimeouts());
        self::assertSame(BatchManager::STATE_TIMEOUT, $manager->getOperation($id)['state']);
    }

    /** @return iterable<string,array{class-string}> */
    public static function runtimeMaintenanceClassProvider(): iterable
    {
        yield 'metrics snapshot flush cadence' => [MetricsSnapshotWriter::class];
        yield 'logger batching and rate limits' => [WlsLogger::class];
        yield 'attack log flush backoff' => [AttackLogService::class];
        yield 'batch operation timeout and retention' => [BatchManager::class];
        yield 'file watcher debounce and cooldown' => [FileWatcher::class];
        yield 'memory facade trace elapsed time' => [MemoryStateFacade::class];
        yield 'session facade trace elapsed time' => [SessionStateFacade::class];
        yield 'performance trace memory retry' => [WlsPerformanceTraceStore::class];
    }
}
