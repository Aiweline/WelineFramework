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
        $startedMonotonic = (\hrtime(true) / 1_000_000_000) - 5.0;
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            startedAt: (float)\time() - 500.0,
            startedMonotonic: $startedMonotonic,
        );
        $info = ServiceInfo::fromServiceInstance($instance, 'Worker');

        self::assertEqualsWithDelta(5.0, $instance->getUptime(), 0.25);
        self::assertEqualsWithDelta(5.0, $info->getUptime(), 0.25);
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
