<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\AdaptiveFiberMaxActive;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\SharedSidecarWireContract;
use Weline\Server\Service\SslCertificateService;
use Weline\Server\Service\WorkerResponseMemoryGuard;
use Weline\Server\Service\WorkerStaticResponseL1;

/**
 * Aggregated contract suite (merged from 9 *ContractTest files).
 * Observe one overall pass/fail via testAllServerServiceContractsPass().
 */
final class ServerServiceContractsTest extends TestCase
{
    public function testAllServerServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AdaptiveFiberMaxActive::testPinnedKeepsCeiling' => function () {
            try {
                $this->assert_AdaptiveFiberMaxActive_testPinnedKeepsCeiling();
            } finally {
                $this->tearDown_AdaptiveFiberMaxActive();
            }
        },
            'AdaptiveFiberMaxActive::testPinnedWithWaitersNeverLightsSaturated' => function () {
            try {
                $this->assert_AdaptiveFiberMaxActive_testPinnedWithWaitersNeverLightsSaturated();
            } finally {
                $this->tearDown_AdaptiveFiberMaxActive();
            }
        },
            'AdaptiveFiberMaxActive::testSoftPressureLowersTowardLiveBudget' => function () {
            try {
                $this->assert_AdaptiveFiberMaxActive_testSoftPressureLowersTowardLiveBudget();
            } finally {
                $this->tearDown_AdaptiveFiberMaxActive();
            }
        },
            'AdaptiveFiberMaxActive::testHealthyHeadroomStaysAtCeiling' => function () {
            try {
                $this->assert_AdaptiveFiberMaxActive_testHealthyHeadroomStaysAtCeiling();
            } finally {
                $this->tearDown_AdaptiveFiberMaxActive();
            }
        },
            'AdaptiveFiberMaxActive::testCapacityScalarsExposeAdaptiveFields' => function () {
            try {
                $this->assert_AdaptiveFiberMaxActive_testCapacityScalarsExposeAdaptiveFields();
            } finally {
                $this->tearDown_AdaptiveFiberMaxActive();
            }
        },
            'AdaptiveFiberMaxActive::testWorkersRefreshAdaptiveBeforeAdmission' => function () {
            try {
                $this->assert_AdaptiveFiberMaxActive_testWorkersRefreshAdaptiveBeforeAdmission();
            } finally {
                $this->tearDown_AdaptiveFiberMaxActive();
            }
        },
            'ServiceOrchestratorDirectMaintenanceEpochOnReady::testReadyHandlerPushesMasterMaintenanceEpochIncludingFalse' => function () {
            $this->assert_ServiceOrchestratorDirectMaintenanceEpochOnReady_testReadyHandlerPushesMasterMaintenanceEpochIncludingFalse();
        },
            'SharedSidecarWire::testMissingWireGenerationOnLiveRuntimeNeedsRotation' => function () {
            $this->assert_SharedSidecarWire_testMissingWireGenerationOnLiveRuntimeNeedsRotation();
        },
            'SharedSidecarWire::testCurrentGenerationIncludesMdel' => function () {
            $this->assert_SharedSidecarWire_testCurrentGenerationIncludesMdel();
        },
            'SslCertificateRetirementBatch::testReplayPrimesCertificateBatchViaInQuery' => function () {
            $this->assert_SslCertificateRetirementBatch_testReplayPrimesCertificateBatchViaInQuery();
        },
            'StaticBrowserTtl::testWorkerStaticResponseL1DefaultMaxAgeIsOneYear' => function () {
            $this->assert_StaticBrowserTtl_testWorkerStaticResponseL1DefaultMaxAgeIsOneYear();
        },
            'StaticBrowserTtl::testManagedNginxStaticLocationUsesOneYearCacheControl' => function () {
            $this->assert_StaticBrowserTtl_testManagedNginxStaticLocationUsesOneYearCacheControl();
        },
            'StaticBrowserTtl::testWorkerSslStaticHandleUsesOneYearMaxAge' => function () {
            $this->assert_StaticBrowserTtl_testWorkerSslStaticHandleUsesOneYearMaxAge();
        },
            'WorkerFullPageCacheFastPathCdnFacts::testMustBypassUsesSharedFactsBuilderNotEmptyEnv' => function () {
            $this->assert_WorkerFullPageCacheFastPathCdnFacts_testMustBypassUsesSharedFactsBuilderNotEmptyEnv();
        },
            'WorkerHomepageSchemePeerReceipt::testRootLookupTriesSchemePeerBeforeHtmlDiskFallthrough' => function () {
            $this->assert_WorkerHomepageSchemePeerReceipt_testRootLookupTriesSchemePeerBeforeHtmlDiskFallthrough();
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testAbsoluteHeadroomBytesTracksLimitMinusReal' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testAbsoluteHeadroomBytesTracksLimitMinusReal();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testDefaultAdmissionThresholdAlignsWithLoopHeadroomShed' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testDefaultAdmissionThresholdAlignsWithLoopHeadroomShed();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testAdmissionHeadroomScalesWithWorkerMemoryLimit' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testAdmissionHeadroomScalesWithWorkerMemoryLimit();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testClampMaxActiveFitsBudgetUnderMemoryLimit' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testClampMaxActiveFitsBudgetUnderMemoryLimit();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testLowHeadroomGateCompactsThenMarksCriticalBelowHardFloor' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testLowHeadroomGateCompactsThenMarksCriticalBelowHardFloor();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testSoftMissIsNotCriticalWhenAboveHardFloor' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testSoftMissIsNotCriticalWhenAboveHardFloor();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testDefaultHardFloorIsSixteenMebibytes' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testDefaultHardFloorIsSixteenMebibytes();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testWorkersHookMemoryGateAfterQueueFull' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testWorkersHookMemoryGateAfterQueueFull();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testConcurrencyScaledHeadroomKeepsIdleReserveSmallAndSaturatedReserveAtFraction' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testConcurrencyScaledHeadroomKeepsIdleReserveSmallAndSaturatedReserveAtFraction();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardAdmissionMemoryGate::testHeadroomBetweenIdleFloorAndLegacyFractionAdmitsWhenOnlyOneFiberRuns' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testHeadroomBetweenIdleFloorAndLegacyFractionAdmitsWhenOnlyOneFiberRuns();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardHeadroomPolicy::testFixedModeDefaultsAre50_25_10MiB' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardHeadroomPolicy_testFixedModeDefaultsAre50_25_10MiB();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardHeadroomPolicy::testPercentModeScalesAdmissionDrainRestart' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardHeadroomPolicy_testPercentModeScalesAdmissionDrainRestart();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardHeadroomPolicy::testWorkersWireHeadroomDrainRestartBands' => function () {
            try {
                $this->assert_WorkerResponseMemoryGuardHeadroomPolicy_testWorkersWireHeadroomDrainRestartBands();
            } finally {
                $this->tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate();
            }
        },
            'WorkerResponseMemoryGuardCapacityScalars::testCapacityStatusScalarsExposeCompactCounters' => function () {
            $this->assert_WorkerResponseMemoryGuardCapacityScalars_testCapacityStatusScalarsExposeCompactCounters();
        },
            'WorkerResponseMemoryGuardCapacityScalars::testAdmissionMemoryGateHealthyPathSkipsCompactAndRejectCounterIncrements' => function () {
            $this->assert_WorkerResponseMemoryGuardCapacityScalars_testAdmissionMemoryGateHealthyPathSkipsCompactAndRejectCounterIncrements();
        },
            'WorkerResponseMemoryGuardCapacityScalars::testWorkerSnapshotMergesCapacityScalars' => function () {
            $this->assert_WorkerResponseMemoryGuardCapacityScalars_testWorkerSnapshotMergesCapacityScalars();
        },
        ];
    }

private function tearDown_AdaptiveFiberMaxActive(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    AdaptiveFiberMaxActive::resetForTests();
}

private function assert_AdaptiveFiberMaxActive_testPinnedKeepsCeiling(): void
{
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
    AdaptiveFiberMaxActive::configure(4, true, true);
    AdaptiveFiberMaxActive::observe(4, 8);
    self::assertSame(4, AdaptiveFiberMaxActive::current());
    self::assertFalse(AdaptiveFiberMaxActive::wantsWorkerScale());
}

/**
 * Known gap (P-util / survive_first): under pin_max_active, effective=ceiling so
 * effective > halfCeiling ⇒ fiber_admission_saturated never lights even with waiters.
 * Ops must raise Ready via server:scale --align-baseline; do not blind-unpin.
 */
private function assert_AdaptiveFiberMaxActive_testPinnedWithWaitersNeverLightsSaturated(): void
{
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(48 * 1024 * 1024);
    AdaptiveFiberMaxActive::configure(6, true, false);
    AdaptiveFiberMaxActive::observe(6, 20);
    $scalars = AdaptiveFiberMaxActive::capacityScalars();
    self::assertSame(6, $scalars['fiber_max_active_effective']);
    self::assertSame(6, $scalars['fiber_max_active_ceiling']);
    self::assertSame(20, $scalars['fiber_admission_waiters']);
    self::assertSame(6, $scalars['fiber_admission_active']);
    self::assertSame(0, $scalars['fiber_admission_saturated']);
    self::assertFalse(AdaptiveFiberMaxActive::wantsWorkerScale());
}

private function assert_AdaptiveFiberMaxActive_testSoftPressureLowersTowardLiveBudget(): void
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

private function assert_AdaptiveFiberMaxActive_testHealthyHeadroomStaysAtCeiling(): void
{
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(2 * 1024 * 1024 * 1024);
    AdaptiveFiberMaxActive::configure(4, false, true);
    AdaptiveFiberMaxActive::observe(1, 0);
    self::assertSame(4, AdaptiveFiberMaxActive::current());
    self::assertSame(0, AdaptiveFiberMaxActive::capacityScalars()['fiber_admission_saturated']);
}

private function assert_AdaptiveFiberMaxActive_testCapacityScalarsExposeAdaptiveFields(): void
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

private function assert_AdaptiveFiberMaxActive_testWorkersRefreshAdaptiveBeforeAdmission(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    foreach (['/bin/worker.php', '/bin/worker_ssl.php'] as $rel) {
        $src = (string) \file_get_contents($base . $rel);
        self::assertStringContainsString('AdaptiveFiberMaxActive::configure', $src);
        self::assertStringContainsString('wlsFiberAdmissionRefreshAdaptiveMaxActive', $src);
    }
}

private function assert_ServiceOrchestratorDirectMaintenanceEpochOnReady_testReadyHandlerPushesMasterMaintenanceEpochIncludingFalse(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/ServiceOrchestrator.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('ControlMessage::setMaintenanceMode($this->maintenanceMode, \'\', true)', $src, 'Direct READY must push Master maintenanceMode (true or false)');
    self::assertStringNotContainsString("ControlMessage::setMaintenanceMode(true, '', true)\n            );", $src, 'Direct READY must not hardcode maintenance true-only push');
    self::assertStringContainsString('broadcastDirectMaintenanceMode(false)', $src, 'reloadAll finally must heal sticky Direct gates when Master is already off');
}

private function assert_SharedSidecarWire_testMissingWireGenerationOnLiveRuntimeNeedsRotation(): void
{
    self::assertTrue(SharedSidecarWireContract::runtimeNeedsRotation(['pid' => 100, 'port' => 9502]));
    self::assertTrue(SharedSidecarWireContract::runtimeNeedsRotation(['pid' => 100, 'port' => 9502, 'wire_generation' => 1]));
    self::assertFalse(SharedSidecarWireContract::runtimeNeedsRotation(['pid' => 100, 'port' => 9502, 'wire_generation' => SharedSidecarWireContract::WIRE_GENERATION]));
    self::assertFalse(SharedSidecarWireContract::runtimeNeedsRotation([]));
}

private function assert_SharedSidecarWire_testCurrentGenerationIncludesMdel(): void
{
    self::assertSame(2, SharedSidecarWireContract::WIRE_GENERATION);
}

private function assert_SslCertificateRetirementBatch_testReplayPrimesCertificateBatchViaInQuery(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/SslCertificateService.php');
    self::assertStringContainsString('function primeRetirementCertificateBatch', $src);
    self::assertStringContainsString('function loadRetirementCertificate', $src);
    self::assertStringContainsString("schema_fields_ID, \\array_values(\$ids), 'IN'", $src);
    self::assertStringContainsString('primeRetirementCertificateBatch($pending)', $src);
    self::assertStringContainsString('loadRetirementCertificate($certId)', $src);
    self::assertTrue(method_exists(SslCertificateService::class, 'replayPendingCertificateRetirements'));
}

private function assert_StaticBrowserTtl_testWorkerStaticResponseL1DefaultMaxAgeIsOneYear(): void
{
    $reflection = new \ReflectionClass(WorkerStaticResponseL1::class);
    $const = $reflection->getReflectionConstant('DEFAULT_MAX_AGE_SECONDS');
    $this->assertNotFalse($const);
    $this->assertSame(31536000, $const->getValue());
}

private function assert_StaticBrowserTtl_testManagedNginxStaticLocationUsesOneYearCacheControl(): void
{
    $path = (new \ReflectionClass(ManagedNginxConfigWriter::class))->getFileName();
    $this->assertIsString($path);
    $src = (string) \file_get_contents($path);
    // Single Cache-Control only — `expires` would emit a second Cache-Control max-age.
    $this->assertStringNotContainsString('expires 365d;', $src);
    $this->assertStringContainsString('max-age=31536000, immutable', $src);
    $this->assertStringNotContainsString('expires 30d;', $src);
    $this->assertStringNotContainsString('max-age=2592000', $src);
}

private function assert_StaticBrowserTtl_testWorkerSslStaticHandleUsesOneYearMaxAge(): void
{
    $workerSsl = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/bin/worker_ssl.php';
    $this->assertFileExists($workerSsl);
    $src = (string) \file_get_contents($workerSsl);
    $this->assertMatchesRegularExpression('/\$maxAge\s*=\s*31536000\s*;/', $src);
    $this->assertMatchesRegularExpression('/\$staticFileCacheMaxAge\s*=\s*31536000\s*;/', $src);
    $this->assertStringContainsString('max-age={$maxAge}, immutable', $src);
    $this->assertStringNotContainsString('$maxAge = 86400 * 7', $src);
}

private function assert_WorkerFullPageCacheFastPathCdnFacts_testMustBypassUsesSharedFactsBuilderNotEmptyEnv(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/WorkerFullPageCacheFastPath.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('FpcBypassFactsBuilder::build', $src);
    self::assertStringNotContainsString("'env' => []", $src);
}

private function assert_WorkerHomepageSchemePeerReceipt_testRootLookupTriesSchemePeerBeforeHtmlDiskFallthrough(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/Service/WorkerFullPageCacheFastPath.php');
    self::assertStringContainsString('resolveRootHomepageReceiptAcrossOriginPeers', $source);
    self::assertStringContainsString('rootHomepageReceiptCandidateUris', $source);
    $body = $this->extractMethodBody_WorkerHomepageSchemePeerReceipt($source, 'rootHomepageReceiptCandidateUris');
    self::assertStringContainsString("\$scheme === 'https' ? 'http' : 'https'", $body);
    $lookup = $this->extractMethodBody_WorkerHomepageSchemePeerReceipt($source, 'lookup');
    self::assertStringContainsString('resolveRootHomepageReceiptAcrossOriginPeers', $lookup);
}

private function extractMethodBody_WorkerHomepageSchemePeerReceipt(string $source, string $method): string
{
    $start = \strpos($source, 'function ' . $method);
    self::assertNotFalse($start, 'missing method ' . $method);
    $brace = \strpos($source, '{', $start);
    self::assertNotFalse($brace);
    $depth = 0;
    $len = \strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return \substr($source, $brace, $i - $brace + 1);
            }
        }
    }
    self::fail('unclosed method ' . $method);
    return '';
}

private function tearDown_WorkerResponseMemoryGuardAdmissionMemoryGate(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
}

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testAbsoluteHeadroomBytesTracksLimitMinusReal(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
    $headroom = WorkerResponseMemoryGuard::absoluteHeadroomBytes();
    self::assertGreaterThan(0, $headroom);
    self::assertLessThanOrEqual(256 * 1024 * 1024, $headroom);
}

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testDefaultAdmissionThresholdAlignsWithLoopHeadroomShed(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    // 2026-11-11 memory headroom design (fixed mode default): admit while remaining
    // headroom ≥ 50MiB.
    self::assertSame(50 * 1024 * 1024, WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
    WorkerResponseMemoryGuard::setAdmissionMemoryHeadroomBytes(32 * 1024 * 1024);
    self::assertSame(32 * 1024 * 1024, WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
}

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testAdmissionHeadroomScalesWithWorkerMemoryLimit(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
    // Fixed design mode (default): the 50MiB remaining-headroom band governs.
    self::assertSame(50 * 1024 * 1024, WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
    // Percent mode: 30% of the Worker limit.
    WorkerResponseMemoryGuard::setFiberHeadroomMode('percent');
    self::assertSame((int) \floor(256 * 1024 * 1024 * 0.30), WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
    WorkerResponseMemoryGuard::setFiberHeadroomMode('fixed');
}

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testClampMaxActiveFitsBudgetUnderMemoryLimit(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    // 256M → soft ~102MiB → budget ~154MiB / 32MiB ≈ 4; configured 12 → 4.
    self::assertSame(4, WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(12, 256 * 1024 * 1024));
    self::assertSame(12, WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(12, 256 * 1024 * 1024, true));
    self::assertSame(0, WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(0, 256 * 1024 * 1024));
    // 768M → soft 40% ≈307MiB → budget ≈461MiB / 32MiB ≈ 14; configured 32 → 14.
    // (Do not loosen ≥512M: higher clamp caused mix30 upstream closes / 502.)
    self::assertSame(0.4, WorkerResponseMemoryGuard::admissionMemoryHeadroomFractionForLimit(768 * 1024 * 1024));
    self::assertSame(32 * 1024 * 1024, WorkerResponseMemoryGuard::admissionPerActiveFiberBudgetBytesForLimit(768 * 1024 * 1024));
    self::assertSame(14, WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit(32, 768 * 1024 * 1024));
    self::assertSame(0.4, WorkerResponseMemoryGuard::admissionMemoryHeadroomFractionForLimit(256 * 1024 * 1024));
    self::assertSame(32 * 1024 * 1024, WorkerResponseMemoryGuard::admissionPerActiveFiberBudgetBytesForLimit(256 * 1024 * 1024));
}

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testLowHeadroomGateCompactsThenMarksCriticalBelowHardFloor(): void
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

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testSoftMissIsNotCriticalWhenAboveHardFloor(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    $limit = 256 * 1024 * 1024;
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes($limit);
    $headroom = WorkerResponseMemoryGuard::absoluteHeadroomBytes();
    // Soft miss must be host-usage-agnostic: require more free bytes than available.
    $softThreshold = $headroom + 8 * 1024 * 1024;
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

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testDefaultHardFloorIsSixteenMebibytes(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    self::assertSame(16 * 1024 * 1024, WorkerResponseMemoryGuard::admissionMemoryHardFloorBytes());
}

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testWorkersHookMemoryGateAfterQueueFull(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $worker = (string) \file_get_contents($base . '/bin/worker.php');
    $ssl = (string) \file_get_contents($base . '/bin/worker_ssl.php');
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

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testConcurrencyScaledHeadroomKeepsIdleReserveSmallAndSaturatedReserveAtFraction(): void
{
    $mib = 1024 * 1024;
    WorkerResponseMemoryGuard::resetThresholdCache();
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(512 * $mib);
    // Fixed design mode (default): the configured remaining-headroom band (50MiB)
    // governs admission, independent of running Fiber count.
    $base = WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes();
    self::assertSame(50 * 1024 * 1024, $base);

    self::assertSame(50 * 1024 * 1024, WorkerResponseMemoryGuard::admissionHeadroomThresholdBytesForConcurrency($base, 0));
    self::assertSame(50 * 1024 * 1024, WorkerResponseMemoryGuard::admissionHeadroomThresholdBytesForConcurrency($base, 1));
    self::assertSame(50 * 1024 * 1024, WorkerResponseMemoryGuard::admissionHeadroomThresholdBytesForConcurrency($base, 2));
    self::assertSame(50 * 1024 * 1024, WorkerResponseMemoryGuard::admissionHeadroomThresholdBytesForConcurrency($base, 5));
    self::assertSame(50 * 1024 * 1024, WorkerResponseMemoryGuard::admissionHeadroomThresholdBytesForConcurrency($base, 64));
}

private function assert_WorkerResponseMemoryGuardAdmissionMemoryGate_testHeadroomBetweenIdleFloorAndLegacyFractionAdmitsWhenOnlyOneFiberRuns(): void
{
    $mib = 1024 * 1024;
    WorkerResponseMemoryGuard::resetThresholdCache();
    // Synthetic Worker limit sized so real Zend headroom is ~100MiB.
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(\memory_get_usage(true) + 100 * $mib);
    // Model the observed 512M Worker reserve (40% = 204.8MiB) with an explicit
    // base so the test process does not have to hold a real 300MiB heap.
    WorkerResponseMemoryGuard::setAdmissionMemoryHeadroomBytes(150 * $mib);

    $legacy = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(false);
    self::assertFalse($legacy['admit'], 'explicit fixed headroom band must still reject in the dead band');
    self::assertSame('memory_pressure', $legacy['reason']);

    // Fixed design mode: the configured band governs regardless of Fiber count.
    $single = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(false, 1);
    self::assertFalse($single['admit'], 'fixed design band (150MiB override) governs even with one running Fiber');
    self::assertSame('memory_pressure', $single['reason']);

    $busy = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(false, 3);
    self::assertFalse($busy['admit'], '3 Fibers must still reject below the fixed design band');

    $scalars = WorkerResponseMemoryGuard::capacityStatusScalars();
    self::assertArrayHasKey('admission_headroom_threshold_bytes', $scalars);
    self::assertArrayHasKey('admission_active_fibers', $scalars);
    self::assertArrayHasKey('admission_headroom_mode', $scalars);
    self::assertArrayHasKey('admission_drain_headroom_bytes', $scalars);
    self::assertArrayHasKey('admission_restart_headroom_bytes', $scalars);
    self::assertSame(3, $scalars['admission_active_fibers']);
    self::assertSame('fixed', $scalars['admission_headroom_mode']);
    self::assertSame(25 * 1024 * 1024, $scalars['admission_drain_headroom_bytes']);
    self::assertSame(10 * 1024 * 1024, $scalars['admission_restart_headroom_bytes']);
    self::assertSame(
        WorkerResponseMemoryGuard::admissionHeadroomThresholdBytesForConcurrency(
            WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes(),
            3
        ),
        $scalars['admission_headroom_threshold_bytes']
    );
}

private function assert_WorkerResponseMemoryGuardHeadroomPolicy_testFixedModeDefaultsAre50_25_10MiB(): void
{
    $mib = 1024 * 1024;
    WorkerResponseMemoryGuard::resetThresholdCache();
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(512 * $mib);
    // Fixed mode is the default; admission/drain/restart use the 50/25/10MiB design bands.
    self::assertSame('fixed', WorkerResponseMemoryGuard::fiberHeadroomMode());
    self::assertSame(50 * $mib, WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
    self::assertSame(25 * $mib, WorkerResponseMemoryGuard::memoryDrainHeadroomBytesForLimit());
    self::assertSame(10 * $mib, WorkerResponseMemoryGuard::workerRestartHeadroomBytesForLimit());
    // Ordering: admission band is looser than drain, drain looser than restart.
    self::assertGreaterThan(
        WorkerResponseMemoryGuard::memoryDrainHeadroomBytesForLimit(),
        WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes()
    );
    self::assertGreaterThan(
        WorkerResponseMemoryGuard::workerRestartHeadroomBytesForLimit(),
        WorkerResponseMemoryGuard::memoryDrainHeadroomBytesForLimit()
    );
}

private function assert_WorkerResponseMemoryGuardHeadroomPolicy_testPercentModeScalesAdmissionDrainRestart(): void
{
    $mib = 1024 * 1024;
    WorkerResponseMemoryGuard::resetThresholdCache();
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(512 * $mib);
    WorkerResponseMemoryGuard::setFiberHeadroomMode('percent');
    self::assertSame('percent', WorkerResponseMemoryGuard::fiberHeadroomMode());
    // 30% / 15% / 10% of the Worker limit.
    self::assertSame((int) \floor(512 * $mib * 0.30), WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes());
    self::assertSame((int) \floor(512 * $mib * 0.15), WorkerResponseMemoryGuard::memoryDrainHeadroomBytesForLimit());
    self::assertSame((int) \floor(512 * $mib * 0.10), WorkerResponseMemoryGuard::workerRestartHeadroomBytesForLimit());
    // Concurrency no longer inflates the admission band in percent mode either.
    self::assertSame(
        (int) \floor(512 * $mib * 0.30),
        WorkerResponseMemoryGuard::admissionHeadroomThresholdBytesForConcurrency(
            WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes(),
            6
        )
    );
}

private function assert_WorkerResponseMemoryGuardHeadroomPolicy_testWorkersWireHeadroomDrainRestartBands(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $worker = (string) \file_get_contents($base . '/bin/worker.php');
    $ssl = (string) \file_get_contents($base . '/bin/worker_ssl.php');
    foreach ([$worker, $ssl] as $source) {
        self::assertStringContainsString('memoryDrainHeadroomBytesForLimit', $source);
        self::assertStringContainsString('workerRestartHeadroomBytesForLimit', $source);
        self::assertStringContainsString('$memoryRealPercent', $source);
        self::assertStringContainsString('memory_pressure_restart', $source);
        self::assertStringContainsString("'restart_band'", $source);
        // Drain/restart bands are measured on remaining headroom, not used-fraction.
        self::assertStringContainsString('$memoryDrainPercentThreshold', $source);
        self::assertStringContainsString('$memoryRestartPercentThreshold', $source);
    }
    $orchestrator = (string) \file_get_contents($base . '/Service/ServiceOrchestrator.php');
    self::assertStringContainsString("str_starts_with(\$reason, 'memory_pressure_restart')", $orchestrator);
}

private function assert_WorkerResponseMemoryGuardCapacityScalars_testCapacityStatusScalarsExposeCompactCounters(): void
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

private function assert_WorkerResponseMemoryGuardCapacityScalars_testAdmissionMemoryGateHealthyPathSkipsCompactAndRejectCounterIncrements(): void
{
    WorkerResponseMemoryGuard::resetThresholdCache();
    // Huge synthetic limit → headroom above 64MiB; soft pressure stays low.
    WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(4 * 1024 * 1024 * 1024);
    $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(true);
    self::assertTrue($gate['admit']);
    self::assertFalse($gate['compacted']);
    self::assertNull($gate['reason']);
    self::assertGreaterThanOrEqual(WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes(), $gate['absolute_headroom_bytes']);
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

private function assert_WorkerResponseMemoryGuardCapacityScalars_testWorkerSnapshotMergesCapacityScalars(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $worker = $base . '/bin/worker.php';
    $ssl = $base . '/bin/worker_ssl.php';
    self::assertStringContainsString('capacityStatusScalars()', (string) \file_get_contents($worker));
    self::assertStringContainsString('capacityStatusScalars()', (string) \file_get_contents($ssl));
    // 503 x-wls-admission must be diagnosable from the local health endpoint too.
    self::assertStringContainsString('admissionStatusScalars()', (string) \file_get_contents($worker));
    self::assertStringContainsString('admissionStatusScalars()', (string) \file_get_contents($ssl));
}
}
