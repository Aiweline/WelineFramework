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

    public function testAdmissionHeadroomScalesWithWorkerMemoryLimit(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
        // 40% of 256MiB = ~102MiB soft headroom (above fixed 64MiB floor).
        self::assertSame(
            (int) \floor(256 * 1024 * 1024 * 0.40),
            WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes()
        );
    }

    public function testClampMaxActiveFitsBudgetUnderMemoryLimit(): void
    {
        WorkerResponseMemoryGuard::resetThresholdCache();
        // 256M → soft ~102MiB → budget ~154MiB / 32MiB ≈ 4; configured 12 → 4.
        self::assertSame(
            4,
            WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(12, 256 * 1024 * 1024)
        );
        self::assertSame(
            12,
            WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(12, 256 * 1024 * 1024, true)
        );
        self::assertSame(
            0,
            WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(0, 256 * 1024 * 1024)
        );
        // 768M → soft 40% ≈307MiB → budget ≈461MiB / 32MiB ≈ 14; configured 32 → 14.
        // (Do not loosen ≥512M: higher clamp caused mix30 upstream closes / 502.)
        self::assertSame(
            0.40,
            WorkerResponseMemoryGuard::admissionMemoryHeadroomFractionForLimit(768 * 1024 * 1024)
        );
        self::assertSame(
            32 * 1024 * 1024,
            WorkerResponseMemoryGuard::admissionPerActiveFiberBudgetBytesForLimit(768 * 1024 * 1024)
        );
        self::assertSame(
            14,
            WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(32, 768 * 1024 * 1024)
        );
        self::assertSame(
            0.40,
            WorkerResponseMemoryGuard::admissionMemoryHeadroomFractionForLimit(256 * 1024 * 1024)
        );
        self::assertSame(
            32 * 1024 * 1024,
            WorkerResponseMemoryGuard::admissionPerActiveFiberBudgetBytesForLimit(256 * 1024 * 1024)
        );
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
        $limit = 256 * 1024 * 1024;
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes($limit);
        $headroom = WorkerResponseMemoryGuard::absoluteHeadroomBytes();
        // Soft miss must be host-usage-agnostic: require more free bytes than available.
        $softThreshold = $headroom + (8 * 1024 * 1024);
        WorkerResponseMemoryGuard::setAdmissionMemoryHeadroomBytes($softThreshold);
        // Hard floor well below actual headroom → prefer delay (not critical 503).
        WorkerResponseMemoryGuard::setAdmissionMemoryHardFloorBytes(1024);
        if ($headroom < 1024) {
            self::markTestSkipped('host headroom already below hard floor; covered by critical-floor contract');
        }
        $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(true);
        self::assertFalse($gate['admit']);
        self::assertFalse($gate['critical'], 'soft miss must not be critical when headroom ≥ hard floor');
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
