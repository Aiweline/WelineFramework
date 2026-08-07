<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\Master\ProcessMonitor;
use Weline\Server\Service\AsyncLogger;
use Weline\Server\Service\MemoryCacheService;
use Weline\Server\Service\StatusLogService;

final class RuntimeAuxiliaryMonotonicTimingTest extends TestCase
{
    public function testAuxiliaryRuntimeTimeoutsHaveMonotonicState(): void
    {
        $asyncLogger = $this->source(AsyncLogger::class);
        self::assertStringContainsString('private static function monotonicSeconds(): float', $asyncLogger);
        self::assertStringNotContainsString('\\time() - self::$lastFlush', $asyncLogger);

        $statusLog = $this->source(StatusLogService::class);
        self::assertStringContainsString('private static float $lastLogMonotonic', $statusLog);
        self::assertStringContainsString('private static float $failureCooldownUntilMonotonic', $statusLog);
        self::assertStringNotContainsString('$now - self::$lastLogTime', $statusLog);

        $processMonitor = $this->source(ProcessMonitor::class);
        self::assertStringContainsString("'start_monotonic' => self::monotonicSeconds()", $processMonitor);
        self::assertStringNotContainsString("\\time() - \$info['start_time']", $processMonitor);

        $memoryCache = $this->source(MemoryCacheService::class);
        self::assertStringContainsString("'created_monotonic' => \$nowMonotonic", $memoryCache);
        self::assertStringContainsString("'last_access_monotonic' => \$nowMonotonic", $memoryCache);
        self::assertStringNotContainsString("\\time() - \$entry['created_at']", $memoryCache);
        self::assertStringNotContainsString("time() - \$entry['created_at']", $memoryCache);
    }

    public function testMemoryCacheStatResetRetainsTheCompleteCounterSchema(): void
    {
        MemoryCacheService::resetStats();
        $stats = MemoryCacheService::getStats();

        self::assertArrayHasKey('evictions', $stats);
        self::assertArrayHasKey('emergency_cleanups', $stats);
        self::assertSame(0, $stats['evictions']);
        self::assertSame(0, $stats['emergency_cleanups']);
    }

    public function testMemoryCacheExpiryAndAgeUseTheMonotonicEntryClock(): void
    {
        MemoryCacheService::purgeAll();
        try {
            self::assertTrue(MemoryCacheService::set('clock-domain', 'ok', ttl: 10));
            $cached = MemoryCacheService::get('clock-domain');

            self::assertIsArray($cached);
            self::assertGreaterThanOrEqual(0.0, (float)$cached['age']);
            self::assertLessThan(2.0, (float)$cached['age']);

            $cacheProperty = new \ReflectionProperty(MemoryCacheService::class, 'cache');
            $cacheProperty->setAccessible(true);
            $cache = $cacheProperty->getValue();
            self::assertIsArray($cache);
            self::assertArrayHasKey('created_monotonic', $cache['clock-domain']);
            self::assertArrayHasKey('last_access_monotonic', $cache['clock-domain']);
            $cache['clock-domain']['created_monotonic']
                = (\hrtime(true) / 1_000_000_000) - 11.0;
            $cacheProperty->setValue(null, $cache);

            self::assertFalse(MemoryCacheService::has('clock-domain'));
        } finally {
            MemoryCacheService::purgeAll();
        }
    }

    public function testProcessMonitorKeepsMonotonicBirthPrivateFromStatusConsumers(): void
    {
        $monitor = new ProcessMonitor();
        $monitor->addProcess('missing', 2_147_483_647, 'missing');

        $processesProperty = new \ReflectionProperty($monitor, 'processes');
        $processesProperty->setAccessible(true);
        $processes = $processesProperty->getValue($monitor);
        $processes['missing']['start_monotonic']
            = (\hrtime(true) / 1_000_000_000) - 5.0;
        $processesProperty->setValue($monitor, $processes);

        $status = $monitor->check('missing');

        self::assertIsArray($status);
        self::assertGreaterThanOrEqual(4, $status['runtime']);
        self::assertLessThanOrEqual(6, $status['runtime']);
        self::assertArrayNotHasKey('start_monotonic', $monitor->getProcesses()['missing']);
        self::assertArrayHasKey('start_time', $monitor->getProcesses()['missing']);
    }

    /** @param class-string $class */
    private function source(string $class): string
    {
        return (string)\file_get_contents((new \ReflectionClass($class))->getFileName());
    }
}
