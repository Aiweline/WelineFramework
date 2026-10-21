<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Control\ControlCommandResult;

final class ServiceLifetimeClockDomainTest extends TestCase
{
    public function testLiveServiceUptimeUsesMonotonicStartWhileWallStartRemainsSerializable(): void
    {
        // Bound the assertion by the test's own monotonic elapsed time instead
        // of a fixed 0.25s delta: this stays deterministic under CI/load spikes
        // while still proving the 500s wall-clock start is not used.
        $t0 = \hrtime(true) / 1_000_000_000;
        $startedMonotonic = $t0 - 5.0;
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            startedAt: (float)\time() - 500.0,
            startedMonotonic: $startedMonotonic,
        );
        $info = ServiceInfo::fromServiceInstance($instance, 'Worker');

        $instanceUptime = $instance->getUptime();
        $infoUptime = $info->getUptime();
        $maxExpected = 5.0 + ((\hrtime(true) / 1_000_000_000) - $t0);

        self::assertGreaterThanOrEqual(5.0, $instanceUptime);
        self::assertLessThanOrEqual($maxExpected + 1e-6, $instanceUptime);
        self::assertGreaterThanOrEqual(5.0, $infoUptime);
        self::assertLessThanOrEqual($maxExpected + 1e-6, $infoUptime);
        self::assertLessThan(60.0, $instanceUptime, 'uptime must not fall back to the 500s wall clock');
        self::assertSame($instance->startedAt, $info->toArray()['started_at']);
        self::assertSame($startedMonotonic, $info->toArray()['started_monotonic']);
    }

    /**
     * @dataProvider lifetimeClassProvider
     */
    public function testRuntimeLifetimeAndFallbackIdsDoNotUseWallClockMicrotime(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringNotContainsString('\\microtime(true)', $source, $class);
        self::assertStringContainsString('\\hrtime(true)', $source, $class);
    }

    /** @return iterable<string,array{class-string}> */
    public static function lifetimeClassProvider(): iterable
    {
        yield 'service instance uptime' => [ServiceInstance::class];
        yield 'service status uptime' => [ServiceInfo::class];
        yield 'control operation id fallback' => [ControlCommandResult::class];
    }
}
