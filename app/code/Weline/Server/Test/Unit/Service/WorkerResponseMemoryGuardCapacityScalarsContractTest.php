<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class WorkerResponseMemoryGuardCapacityScalarsContractTest extends TestCase
{
    public function testCapacityStatusScalarsExposeCompactCounters(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        $scalars = WorkerResponseMemoryGuard::capacityStatusScalars();
        self::assertArrayHasKey('compact_soft_count', $scalars);
        self::assertArrayHasKey('compact_hard_count', $scalars);
        self::assertArrayHasKey('drain_request_count', $scalars);
        self::assertArrayHasKey('last_pressure', $scalars);
        self::assertArrayHasKey('admission_memory_rejects', $scalars);
        self::assertArrayHasKey('absolute_headroom_bytes', $scalars);
        self::assertIsInt($scalars['compact_soft_count']);
        self::assertIsInt($scalars['compact_hard_count']);
        self::assertIsInt($scalars['drain_request_count']);
        self::assertIsFloat($scalars['last_pressure']);
        self::assertIsInt($scalars['admission_memory_rejects']);
        self::assertIsInt($scalars['absolute_headroom_bytes']);
        self::assertSame(0, $scalars['admission_memory_rejects']);
    }

    public function testAdmissionMemoryGateHealthyPathSkipsCompactAndRejectCounterIncrements(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        // Huge synthetic limit → headroom above 64MiB; soft pressure stays low.
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(4 * 1024 * 1024 * 1024);

        $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(true);
        self::assertTrue($gate['admit']);
        self::assertFalse($gate['compacted']);
        self::assertNull($gate['reason']);
        self::assertGreaterThanOrEqual(
            WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes(),
            $gate['absolute_headroom_bytes']
        );

        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(8 * 1024 * 1024);
        $blocked = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(true);
        self::assertFalse($blocked['admit']);
        self::assertSame('memory_pressure', $blocked['reason']);
        self::assertTrue($blocked['compacted']);

        WorkerResponseMemoryGuard::noteAdmissionMemoryReject();
        $scalars = WorkerResponseMemoryGuard::capacityStatusScalars();
        self::assertSame(1, $scalars['admission_memory_rejects']);
        self::assertArrayHasKey('absolute_headroom_bytes', $scalars);

        WorkerResponseMemoryGuard::resetThresholdCache();
    }

    public function testWorkerSnapshotMergesCapacityScalars(): void
    {
        $base = \dirname(__DIR__, 3);
        $worker = $base . '/bin/worker.php';
        $ssl = $base . '/bin/worker_ssl.php';
        self::assertStringContainsString('capacityStatusScalars()', (string)\file_get_contents($worker));
        self::assertStringContainsString('capacityStatusScalars()', (string)\file_get_contents($ssl));
    }
}
