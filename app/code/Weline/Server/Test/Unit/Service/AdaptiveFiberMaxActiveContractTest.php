<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\AdaptiveFiberMaxActive;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class AdaptiveFiberMaxActiveContractTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        AdaptiveFiberMaxActive::resetForTests();
    }

    public function testPinnedKeepsCeiling(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
        AdaptiveFiberMaxActive::configure(4, true, true);
        AdaptiveFiberMaxActive::observe(4, 8);
        self::assertSame(4, AdaptiveFiberMaxActive::current());
        self::assertFalse(AdaptiveFiberMaxActive::wantsWorkerScale());
    }

    public function testSoftPressureLowersTowardLiveBudget(): void
    {
        // Tiny synthetic limit → headroom below soft → effective drops.
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(48 * 1024 * 1024);
        AdaptiveFiberMaxActive::configure(4, false, true);
        AdaptiveFiberMaxActive::observe(2, 3);
        $effective = AdaptiveFiberMaxActive::current();
        self::assertGreaterThanOrEqual(1, $effective);
        self::assertLessThanOrEqual(2, $effective);
        self::assertTrue(AdaptiveFiberMaxActive::wantsWorkerScale());
    }

    public function testHealthyHeadroomStaysAtCeiling(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(2 * 1024 * 1024 * 1024);
        AdaptiveFiberMaxActive::configure(4, false, true);
        AdaptiveFiberMaxActive::observe(1, 0);
        self::assertSame(4, AdaptiveFiberMaxActive::current());
        self::assertSame(0, AdaptiveFiberMaxActive::capacityScalars()['fiber_admission_saturated']);
    }

    public function testCapacityScalarsExposeAdaptiveFields(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(512 * 1024 * 1024);
        AdaptiveFiberMaxActive::configure(6, false, true);
        AdaptiveFiberMaxActive::observe(2, 1);
        $scalars = WorkerResponseMemoryGuard::capacityStatusScalars();
        self::assertArrayHasKey('fiber_max_active_effective', $scalars);
        self::assertArrayHasKey('fiber_max_active_ceiling', $scalars);
        self::assertArrayHasKey('fiber_admission_saturated', $scalars);
        self::assertSame(6, $scalars['fiber_max_active_ceiling']);
    }

    public function testWorkersRefreshAdaptiveBeforeAdmission(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['/bin/worker.php', '/bin/worker_ssl.php'] as $rel) {
            $src = (string) \file_get_contents($base . $rel);
            self::assertStringContainsString('AdaptiveFiberMaxActive::configure', $src);
            self::assertStringContainsString('wlsFiberAdmissionRefreshAdaptiveMaxActive', $src);
        }
    }
}
