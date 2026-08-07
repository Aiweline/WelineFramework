<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Server\Dispatcher\LoadBalancer;
use Weline\Server\Service\LoadMonitor;
use Weline\Server\Service\Memory\MemoryPressureStateMachine;
use Weline\Server\Service\ScalingDecider;
use Weline\Server\Service\Telemetry\WorkerTelemetryBuffer;
use Weline\Server\Service\Telemetry\WorkerTelemetryReporter;
use Weline\Server\Shared\Connection\ConnectionSignalManager;

final class RuntimeLoadControlMonotonicTimingTest extends TestCase
{
    /**
     * @dataProvider runtimeLoadControlClassProvider
     */
    public function testRuntimeCooldownsAndRetentionUseMonotonicClock(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringNotContainsString('\\microtime(true)', $source, $class);
        self::assertStringContainsString('\\hrtime(true)', $source, $class);
    }

    public function testLoadBalancerConnectionAgesDoNotUseEpochTime(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(LoadBalancer::class))->getFileName());

        self::assertStringNotContainsString('\\time()', $source);
    }

    public function testDebugTimestampsRemainWallClockWhileAgesUseMonotonicFields(): void
    {
        $loadMonitor = (string)\file_get_contents((new \ReflectionClass(LoadMonitor::class))->getFileName());
        $signals = (string)\file_get_contents((new \ReflectionClass(ConnectionSignalManager::class))->getFileName());

        self::assertStringContainsString("'updated_at' => \\time()", $loadMonitor);
        self::assertStringContainsString("'updated_monotonic' => \$now", $loadMonitor);
        self::assertStringContainsString("\$metrics['updated_monotonic']", $loadMonitor);
        self::assertStringContainsString("'timestamp' => \\time()", $signals);
        self::assertStringContainsString("'monotonic_at' => self::monotonicSeconds()", $signals);
        self::assertStringContainsString("\$signal['monotonic_at']", $signals);
    }

    /** @return iterable<string,array{class-string}> */
    public static function runtimeLoadControlClassProvider(): iterable
    {
        yield 'shared memory failure cooldown' => [WlsMemoryAdapter::class];
        yield 'worker connection lifetime' => [LoadBalancer::class];
        yield 'worker metric staleness' => [LoadMonitor::class];
        yield 'memory pressure recovery windows' => [MemoryPressureStateMachine::class];
        yield 'worker scaling cooldown' => [ScalingDecider::class];
        yield 'telemetry batch flush cadence' => [WorkerTelemetryBuffer::class];
        yield 'telemetry anomaly coalescing' => [WorkerTelemetryReporter::class];
        yield 'connection de-duplication retention' => [ConnectionSignalManager::class];
    }
}
