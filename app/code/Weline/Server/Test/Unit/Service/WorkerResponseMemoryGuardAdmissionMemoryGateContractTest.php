<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class WorkerResponseMemoryGuardAdmissionMemoryGateContractTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
    }

    public function testAbsoluteHeadroomBytesTracksLimitMinusReal(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
        $headroom = WorkerResponseMemoryGuard::absoluteHeadroomBytes();
        self::assertGreaterThan(0, $headroom);
        self::assertLessThanOrEqual(256 * 1024 * 1024, $headroom);
    }

    public function testDefaultAdmissionThresholdAlignsWithLoopHeadroomShed(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        self::assertSame(64 * 1024 * 1024, WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
        WorkerResponseMemoryGuard::setAdmissionMemoryHeadroomBytes(32 * 1024 * 1024);
        self::assertSame(32 * 1024 * 1024, WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
    }

    public function testLowHeadroomGateCompactsThenMarksCriticalBelowHardFloor(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(16 * 1024 * 1024);
        $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(true);
        self::assertFalse($gate['admit']);
        self::assertTrue($gate['critical']);
        self::assertTrue($gate['compacted']);
        self::assertSame('memory_pressure', $gate['reason']);
        self::assertArrayHasKey('absolute_headroom_bytes', $gate);
        self::assertArrayHasKey('pressure', $gate);
    }

    public function testSoftMissIsNotCriticalWhenAboveHardFloor(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
        WorkerResponseMemoryGuard::setAdmissionMemoryHeadroomBytes(250 * 1024 * 1024);
        WorkerResponseMemoryGuard::setAdmissionMemoryHardFloorBytes(1024);
        $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(true);
        self::assertFalse($gate['admit']);
        self::assertFalse($gate['critical']);
        self::assertSame('memory_pressure', $gate['reason']);
    }

    public function testDefaultHardFloorIsSixteenMebibytes(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        self::assertSame(16 * 1024 * 1024, WorkerResponseMemoryGuard::admissionMemoryHardFloorBytes());
    }

    public function testWorkersHookMemoryGateAfterQueueFull(): void
    {
        $base = \dirname(__DIR__, 3);
        $worker = (string)\file_get_contents($base . '/bin/worker.php');
        $ssl = (string)\file_get_contents($base . '/bin/worker_ssl.php');
        foreach ([$worker, $ssl] as $source) {
            self::assertStringContainsString('wlsFiberAdmissionMemoryGateEvaluate(true)', $source);
            self::assertStringContainsString("wlsFiberAdmissionUnavailableResponse('memory_pressure')", $source);
            self::assertStringContainsString('noteAdmissionMemoryReject()', $source);
            $queuePos = \strpos($source, "wlsFiberAdmissionUnavailableResponse('queue_full')");
            $gatePos = \strpos($source, 'wlsFiberAdmissionMemoryGateEvaluate(true)');
            self::assertNotFalse($queuePos);
            self::assertNotFalse($gatePos);
            self::assertLessThan($gatePos, $queuePos);
        }
    }
}
