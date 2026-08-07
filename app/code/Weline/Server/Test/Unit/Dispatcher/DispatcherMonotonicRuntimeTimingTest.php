<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;
use Weline\Server\Dispatcher\RoutingCacheService;

final class DispatcherMonotonicRuntimeTimingTest extends TestCase
{
    public function testReportedUptimeDoesNotDependOnTheWallClockAnchor(): void
    {
        $dispatcher = (new \ReflectionClass(Dispatcher::class))
            ->newInstanceWithoutConstructor();
        $core = new class extends PassthroughCore {
            public function __construct()
            {
            }

            public function getStats(): array
            {
                return [];
            }
        };
        (new \ReflectionProperty($dispatcher, 'passthroughCore'))->setValue(
            $dispatcher,
            $core,
        );
        (new \ReflectionProperty($dispatcher, 'startMonotonic'))->setValue(
            $dispatcher,
            (\hrtime(true) / 1_000_000_000) - 5.0,
        );

        $stats = $dispatcher->getStats();

        self::assertGreaterThanOrEqual(0.0, (float)$stats['uptime']);
        self::assertLessThan(60.0, (float)$stats['uptime']);
    }

    public function testRuntimeTimeoutsDoNotUseWallClockMicrotime(): void
    {
        foreach ([Dispatcher::class, RoutingCacheService::class] as $class) {
            $source = (string)\file_get_contents(
                (new \ReflectionClass($class))->getFileName(),
            );

            self::assertStringNotContainsString('\\microtime(true)', $source, $class);
            self::assertStringNotContainsString('\\time()', $source, $class);
            self::assertStringContainsString('\\hrtime(true)', $source, $class);
        }
    }
}
