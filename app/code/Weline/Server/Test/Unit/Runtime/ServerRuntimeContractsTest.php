<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Server\Runtime\WlsRuntime;

require_once __DIR__ . '/../../../bin/worker_runtime_common.php';

/**
 * Aggregated contract suite (merged from 6 *ContractTest files).
 * Observe one overall pass/fail via testAllServerRuntimeContractsPass().
 */
final class ServerRuntimeContractsTest extends TestCase
{
    public function testAllServerRuntimeContractsPass(): void
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
            'FiberAdmissionQueue::testResolveDefaultsPreferQueueNotUnlimited' => function () {
            $this->assert_FiberAdmissionQueue_testResolveDefaultsPreferQueueNotUnlimited();
        },
            'FiberAdmissionQueue::testExplicitZeroMaxActiveKeepsUnlimited' => function () {
            $this->assert_FiberAdmissionQueue_testExplicitZeroMaxActiveKeepsUnlimited();
        },
            'FiberAdmissionQueue::testQueueHasRoomUntilDepth' => function () {
            $this->assert_FiberAdmissionQueue_testQueueHasRoomUntilDepth();
        },
            'FiberAdmissionQueue::testActiveCountExcludesWaitersAndSse' => function () {
            $this->assert_FiberAdmissionQueue_testActiveCountExcludesWaitersAndSse();
        },
            'FiberAdmissionQueue::testUnavailableResponseMarksAdmissionReason' => function () {
            $this->assert_FiberAdmissionQueue_testUnavailableResponseMarksAdmissionReason();
        },
            'FiberAdmissionQueue::testAwaitRejectsEarlyWhenHardFloorCritical' => function () {
            $this->assert_FiberAdmissionQueue_testAwaitRejectsEarlyWhenHardFloorCritical();
        },
            'FiberAdmissionQueue::testAwaitSoftMemoryMissWaitsUntilDeadline' => function () {
            $this->assert_FiberAdmissionQueue_testAwaitSoftMemoryMissWaitsUntilDeadline();
        },
            'FiberAdmissionQueue::testWaitMsDefaultAndHardCapFiveSeconds' => function () {
            $this->assert_FiberAdmissionQueue_testWaitMsDefaultAndHardCapFiveSeconds();
        },
            'FiberAdmissionQueue::testWaitingRequestCanFinishFromCacheBeforeAdmissionSlotOpens' => function () {
            $this->assert_FiberAdmissionQueue_testWaitingRequestCanFinishFromCacheBeforeAdmissionSlotOpens();
        },
            'FiberAdmissionQueue::testWaitingCacheProbesAreSingleFlightWithinWorker' => function () {
            $this->assert_FiberAdmissionQueue_testWaitingCacheProbesAreSingleFlightWithinWorker();
        },
            'FiberAdmissionQueue::testWaitingCacheProbeReturnsItsIdleDatabaseConnection' => function () {
            $this->assert_FiberAdmissionQueue_testWaitingCacheProbeReturnsItsIdleDatabaseConnection();
        },
            'FiberAdmissionQueue::testInstanceAdmissionPolicyIsLoadedFromEnvironmentBeforeWorkerStarts' => function () {
            $this->assert_FiberAdmissionQueue_testInstanceAdmissionPolicyIsLoadedFromEnvironmentBeforeWorkerStarts();
        },
            'WlsRuntimeNoStorefrontWarmSchedule::testNeedsDeferredStorefrontWarmupIsHardDisabled' => function () {
            $this->assert_WlsRuntimeNoStorefrontWarmSchedule_testNeedsDeferredStorefrontWarmupIsHardDisabled();
        },
            'WlsRuntimeNoStorefrontWarmSchedule::testHomepageKeepWarmIsHardDisabled' => function () {
            $this->assert_WlsRuntimeNoStorefrontWarmSchedule_testHomepageKeepWarmIsHardDisabled();
        },
            'WlsRuntimeNoStorefrontWarmSchedule::testBuildAheadProcessPullAndDynamicDeferredAreHardDisabled' => function () {
            $this->assert_WlsRuntimeNoStorefrontWarmSchedule_testBuildAheadProcessPullAndDynamicDeferredAreHardDisabled();
        },
            'WlsRuntimeNoStorefrontWarmSchedule::testServerHasNoParallelStorefrontWarmupOrchestrator' => function () {
            $this->assert_WlsRuntimeNoStorefrontWarmSchedule_testServerHasNoParallelStorefrontWarmupOrchestrator();
        },
            'WlsRuntimeOriginNoStoreFpcBypass::testCurrentFpcStatusHonorsSharedForbidAndCollectorBypass' => function () {
            $this->assert_WlsRuntimeOriginNoStoreFpcBypass_testCurrentFpcStatusHonorsSharedForbidAndCollectorBypass();
        },
            'WlsRuntimeProcessL1Hydrate::testRuntimeSchedulesHydrateWithoutRevivingDeferredSsr' => function () {
            $this->assert_WlsRuntimeProcessL1Hydrate_testRuntimeSchedulesHydrateWithoutRevivingDeferredSsr();
        },
            'WlsRuntimeProcessL1Hydrate::testWorkersScheduleProcessL1HydrateFiber' => function () {
            $this->assert_WlsRuntimeProcessL1Hydrate_testWorkersScheduleProcessL1HydrateFiber();
        },
            'WlsRuntimeProcessL1Hydrate::testEnvDefaultsProcessL1HydrateEnabled' => function () {
            $this->assert_WlsRuntimeProcessL1Hydrate_testEnvDefaultsProcessL1HydrateEnabled();
        },
            'WlsRuntimeStartPagePreviewMount::testStartPageNormalizesVisitorUriBeforeRootDetection' => function () {
            $this->assert_WlsRuntimeStartPagePreviewMount_testStartPageNormalizesVisitorUriBeforeRootDetection();
        },
            'WlsRuntimeStorefrontWarmup::testDeferredWarmupWaitsForTheFirstEventLoopAndPendingTransportWork' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testDeferredWarmupWaitsForTheFirstEventLoopAndPendingTransportWork();
        },
            'WlsRuntimeStorefrontWarmup::testDeferredWarmupKeepsAnIdleGraceWindowAfterTheFirstLoop' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testDeferredWarmupKeepsAnIdleGraceWindowAfterTheFirstLoop();
        },
            'WlsRuntimeStorefrontWarmup::testDynamicReadyGateKeepsTheUnifiedWarmupProviderAvailableWithoutBlockingStartup' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testDynamicReadyGateKeepsTheUnifiedWarmupProviderAvailableWithoutBlockingStartup();
        },
            'WlsRuntimeStorefrontWarmup::testReadyGateBuildsABoundedAnonymousStorefrontFpcBeforePublicTraffic' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testReadyGateBuildsABoundedAnonymousStorefrontFpcBeforePublicTraffic();
        },
            'WlsRuntimeStorefrontWarmup::testReadyGateRuntimeTraceHonorsTheConfiguredWlsDebugFlag' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testReadyGateRuntimeTraceHonorsTheConfiguredWlsDebugFlag();
        },
            'WlsRuntimeStorefrontWarmup::testReadyGatePrefetchesStorefrontNamespaceVectorBeforeHomepageFpc' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testReadyGatePrefetchesStorefrontNamespaceVectorBeforeHomepageFpc();
        },
            'WlsRuntimeStorefrontWarmup::testStorefrontWarmupPrefersPublicHostForFpcIdentity' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testStorefrontWarmupPrefersPublicHostForFpcIdentity();
        },
            'WlsRuntimeStorefrontWarmup::testSingleStorefrontPathIsWarmedByEveryWorker' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testSingleStorefrontPathIsWarmedByEveryWorker();
        },
            'WlsRuntimeStorefrontWarmup::testDeferredWarmupOwnsHomepageWhenReadyGateFailOpen' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testDeferredWarmupOwnsHomepageWhenReadyGateFailOpen();
        },
            'WlsRuntimeStorefrontWarmup::testDeferredStorefrontWarmupRunsOnlyOnConfiguredOwnerWorker' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testDeferredStorefrontWarmupRunsOnlyOnConfiguredOwnerWorker();
        },
            'WlsRuntimeStorefrontWarmup::testDynamicReadyProofAcceptsTheFirstBusinessPathWhenHomepageIsNotSampled' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testDynamicReadyProofAcceptsTheFirstBusinessPathWhenHomepageIsNotSampled();
        },
            'WlsRuntimeStorefrontWarmup::testSampleConfigurationLeavesCriticalPathsToModuleProviders' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testSampleConfigurationLeavesCriticalPathsToModuleProviders();
        },
            'WlsRuntimeStorefrontWarmup::testSlowDiagnosticWarmupDoesNotRenderTheSamePathThreeTimesByDefault' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testSlowDiagnosticWarmupDoesNotRenderTheSamePathThreeTimesByDefault();
        },
            'WlsRuntimeStorefrontWarmup::testRenderedBusinessPathWithoutControllerCacheHeaderIsStillReady' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testRenderedBusinessPathWithoutControllerCacheHeaderIsStillReady();
        },
            'WlsRuntimeStorefrontWarmup::testLowMemoryWorkerTightensDeferredBagPrimeDefaults' => function () {
            $this->assert_WlsRuntimeStorefrontWarmup_testLowMemoryWorkerTightensDeferredBagPrimeDefaults();
        },
        ];
    }

private function assert_FiberAdmissionQueue_testResolveDefaultsPreferQueueNotUnlimited(): void
{
    $cfg = wlsResolveFiberAdmissionConfig([]);
    self::assertSame(6, $cfg['max_active']);
    self::assertSame(5000, $cfg['queue_wait_ms']);
    // Default depth = max(max_active×8, 64) so mix can wait, not instant queue_full.
    self::assertSame(64, $cfg['queue_depth']);
    self::assertSame(25, $cfg['queue_slice_ms']);
}

private function assert_FiberAdmissionQueue_testExplicitZeroMaxActiveKeepsUnlimited(): void
{
    $cfg = wlsResolveFiberAdmissionConfig(['fiber' => ['max_active' => 0]]);
    self::assertSame(0, $cfg['max_active']);
    self::assertTrue(wlsFiberAdmissionQueueHasRoom(100, 24, 0));
}

private function assert_FiberAdmissionQueue_testQueueHasRoomUntilDepth(): void
{
    self::assertTrue(wlsFiberAdmissionQueueHasRoom(0, 24, 8));
    self::assertTrue(wlsFiberAdmissionQueueHasRoom(23, 24, 8));
    self::assertFalse(wlsFiberAdmissionQueueHasRoom(24, 24, 8));
}

private function assert_FiberAdmissionQueue_testActiveCountExcludesWaitersAndSse(): void
{
    $active = [1 => ['is_sse_protocol' => false], 2 => ['is_sse_protocol' => true], 3 => ['is_sse_protocol' => false, 'admission_waiting' => true], 4 => ['is_sse_protocol' => false]];
    $waiters = [4 => true];
    self::assertSame(1, wlsCountActiveFibersForAdmission($active, $waiters));
    self::assertSame(1, wlsCountFiberAdmissionWaiters($waiters));
}

private function assert_FiberAdmissionQueue_testUnavailableResponseMarksAdmissionReason(): void
{
    $resp = wlsFiberAdmissionUnavailableResponse('timeout');
    self::assertStringContainsString('503 Service Unavailable', $resp);
    self::assertStringContainsString('X-WLS-Admission: timeout', $resp);
    self::assertStringContainsString('Retry-After: 2', $resp);
    $full = wlsFiberAdmissionUnavailableResponse('queue_full');
    self::assertStringContainsString('X-WLS-Admission: queue_full', $full);
    self::assertStringContainsString('Retry-After: 1', $full);
    $memory = wlsFiberAdmissionUnavailableResponse('memory_pressure');
    self::assertStringContainsString('503 Service Unavailable', $memory);
    self::assertStringContainsString('X-WLS-Admission: memory_pressure', $memory);
    self::assertStringContainsString('Retry-After: 2', $memory);
}

private function assert_FiberAdmissionQueue_testAwaitRejectsEarlyWhenHardFloorCritical(): void
{
    \Weline\Server\Service\WorkerResponseMemoryGuard::resetThresholdCache();
    // Tiny pressure denominator → headroom always below 16MiB hard floor (critical).
    \Weline\Server\Service\WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(8 * 1024 * 1024);
    $active = ['waiting' => ['is_sse_protocol' => false]];
    $waiters = ['waiting' => true];
    $rejectReason = null;
    $started = \microtime(true);
    $result = wlsAwaitFiberAdmissionSlot($active, $waiters, 'waiting', 1, 500, 5, null, 5000, $rejectReason);
    $elapsedMs = (\microtime(true) - $started) * 1000.0;
    self::assertFalse($result);
    self::assertSame('memory_pressure', $rejectReason);
    self::assertSame([], $waiters);
    // Critical fail-closed must not burn the full soft-delay budget.
    // Must stay well below the 500ms soft-delay budget; keep headroom for
    // scheduler jitter on loaded CI hosts without weakening fail-closed.
    self::assertLessThan(450.0, $elapsedMs);
    \Weline\Server\Service\WorkerResponseMemoryGuard::resetThresholdCache();
}

private function assert_FiberAdmissionQueue_testAwaitSoftMemoryMissWaitsUntilDeadline(): void
{
    \Weline\Server\Service\WorkerResponseMemoryGuard::resetThresholdCache();
    // Soft miss host-agnostic: threshold above current headroom; hard floor tiny → wait then 503.
    \Weline\Server\Service\WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * 1024 * 1024);
    $headroom = \Weline\Server\Service\WorkerResponseMemoryGuard::absoluteHeadroomBytes();
    \Weline\Server\Service\WorkerResponseMemoryGuard::setAdmissionMemoryHeadroomBytes($headroom + 8 * 1024 * 1024);
    \Weline\Server\Service\WorkerResponseMemoryGuard::setAdmissionMemoryHardFloorBytes(1024);
    $active = ['waiting' => ['is_sse_protocol' => false]];
    $waiters = ['waiting' => true];
    $rejectReason = null;
    $started = \microtime(true);
    $result = wlsAwaitFiberAdmissionSlot($active, $waiters, 'waiting', 1, 80, 5, null, 5000, $rejectReason);
    $elapsedMs = (\microtime(true) - $started) * 1000.0;
    self::assertFalse($result);
    self::assertSame('memory_pressure', $rejectReason);
    self::assertGreaterThanOrEqual(60.0, $elapsedMs);
    \Weline\Server\Service\WorkerResponseMemoryGuard::resetThresholdCache();
}

private function assert_FiberAdmissionQueue_testWaitMsDefaultAndHardCapFiveSeconds(): void
{
    $configured = wlsResolveFiberAdmissionConfig(['fiber' => ['admission_queue_wait_ms' => 3000]]);
    self::assertSame(3000, $configured['queue_wait_ms']);
    $cfg = wlsResolveFiberAdmissionConfig(['fiber' => ['admission_queue_wait_ms' => 45000]]);
    self::assertSame(5000, $cfg['queue_wait_ms']);
    $moduleEnv = require '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/etc/env.php';
    self::assertSame(5000, $moduleEnv['wls']['fiber']['admission_queue_wait_ms']);
    self::assertSame(6, $moduleEnv['wls']['fiber']['max_active']);
    self::assertSame(64, $moduleEnv['wls']['fiber']['admission_queue_depth']);
    self::assertTrue((bool) $moduleEnv['wls']['fiber']['pin_max_active']);
    self::assertFalse((bool) $moduleEnv['wls']['fiber']['adaptive_max_active']);
}

private function assert_FiberAdmissionQueue_testWaitingRequestCanFinishFromCacheBeforeAdmissionSlotOpens(): void
{
    $active = ['busy' => ['is_sse_protocol' => false], 'waiting' => ['is_sse_protocol' => false]];
    $waiters = ['waiting' => true];
    $probes = 0;
    $result = wlsAwaitFiberAdmissionSlot($active, $waiters, 'waiting', 1, 100, 5, static function () use (&$probes): bool {
        $probes++;
        return true;
    }, 10);
    self::assertNull($result);
    self::assertSame(1, $probes);
    self::assertSame([], $waiters);
    self::assertFalse($active['waiting']['admission_waiting']);
}

private function assert_FiberAdmissionQueue_testWaitingCacheProbesAreSingleFlightWithinWorker(): void
{
    $nestedRan = false;
    $outer = wlsTryFiberAdmissionCacheProbe(static function () use (&$nestedRan): bool {
        $nested = wlsTryFiberAdmissionCacheProbe(static function () use (&$nestedRan): bool {
            $nestedRan = true;
            return true;
        });
        self::assertFalse($nested);
        return true;
    });
    self::assertTrue($outer);
    self::assertFalse($nestedRan);
    self::assertTrue(wlsTryFiberAdmissionCacheProbe(static fn(): bool => true));
}

private function assert_FiberAdmissionQueue_testWaitingCacheProbeReturnsItsIdleDatabaseConnection(): void
{
    $config = $this->createMock(ConfigProviderInterface::class);
    $config->method('getDbType')->willReturn('sqlite');
    $config->method('getHostName')->willReturn('local');
    $config->method('getHostPort')->willReturn(0);
    $config->method('getDatabase')->willReturn('wls_admission_probe');
    $config->method('getUsername')->willReturn('test');
    $config->method('getPoolSize')->willReturn(1);
    $lease = null;
    try {
        self::assertFalse(wlsTryFiberAdmissionCacheProbe(static function () use ($config, &$lease): bool {
            $lease = ConnectionPool::acquire($config, static fn(): \PDO => new \PDO('sqlite::memory:'));
            return false;
        }));
        self::assertSame(0, ConnectionPool::getPoolStats($config)['in_use']);
        self::assertSame(1, ConnectionPool::getPoolStats($config)['available']);
    } finally {
        ConnectionPool::closePool();
    }
}

private function assert_FiberAdmissionQueue_testInstanceAdmissionPolicyIsLoadedFromEnvironmentBeforeWorkerStarts(): void
{
    $path = tempnam(sys_get_temp_dir(), 'wls-fiber-policy-');
    self::assertIsString($path);
    try {
        file_put_contents($path, '<?php return ' . var_export(['wls' => ['fiber' => ['max_active' => 0], 'servers' => ['soak' => ['fiber' => ['max_active' => 12, 'admission_queue_wait_ms' => 45000, 'admission_queue_depth' => 256]]]]], true) . ';');
        $cfg = wlsResolveFiberAdmissionConfigFromEnvironment($path, 'soak');
        self::assertSame(12, $cfg['max_active']);
        self::assertSame(5000, $cfg['queue_wait_ms']);
        self::assertSame(256, $cfg['queue_depth']);
    } finally {
        @unlink($path);
    }
}

private function assert_WlsRuntimeNoStorefrontWarmSchedule_testNeedsDeferredStorefrontWarmupIsHardDisabled(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Runtime/WlsRuntime.php');
    self::assertMatchesRegularExpression('/function needsDeferredStorefrontWarmup\(\): bool\s*\{[^}]*return false;/s', $source);
    self::assertStringNotContainsString('homepageNeedsDeferredPrime', $source);
}

private function assert_WlsRuntimeNoStorefrontWarmSchedule_testHomepageKeepWarmIsHardDisabled(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Runtime/WlsRuntime.php');
    self::assertMatchesRegularExpression('/function homepageKeepWarmEnabled\(\): bool\s*\{[^}]*return false;/s', $source);
}

private function assert_WlsRuntimeNoStorefrontWarmSchedule_testBuildAheadProcessPullAndDynamicDeferredAreHardDisabled(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Runtime/WlsRuntime.php');
    foreach (['shouldRunDeferredFpcBuildAheadWarmup', 'shouldRunDeferredFpcProcessPullWarmup', 'shouldRunDeferredDynamicFirstRenderWarmup'] as $method) {
        self::assertMatchesRegularExpression('/function ' . $method . '\(\): bool\s*\{[^}]*return false;/s', $source, $method);
    }
}

private function assert_WlsRuntimeNoStorefrontWarmSchedule_testServerHasNoParallelStorefrontWarmupOrchestrator(): void
{
    $cronDir = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Cron';
    $files = \is_dir($cronDir) ? \glob($cronDir . '/*.php') : [];
    self::assertIsArray($files);
    foreach ($files as $file) {
        $base = \basename((string) $file);
        self::assertStringNotContainsString('StorefrontWarmup', $base);
        $src = (string) \file_get_contents((string) $file);
        self::assertStringNotContainsString('StorefrontWarmupOrchestrator', $src);
    }
}

private function assert_WlsRuntimeOriginNoStoreFpcBypass_testCurrentFpcStatusHonorsSharedForbidAndCollectorBypass(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $runtime = (string) file_get_contents($root . '/Runtime/WlsRuntime.php');
    self::assertStringContainsString('use Weline\Framework\Cache\SharedResponseCachePolicy;', $runtime);
    self::assertStringContainsString('use Weline\Framework\Http\HeaderCollector;', $runtime);
    self::assertStringContainsString('SharedResponseCachePolicy::isForbidden()', $runtime);
    self::assertStringContainsString('resolveExplicitFpcStatusHeader', $runtime);
    self::assertStringContainsString('GuardHeaders::CACHE_STATUS', $runtime);
    self::assertStringContainsString('GuardHeaders::STATUS_BYPASS', $runtime);
    self::assertMatchesRegularExpression("/private function currentFpcStatus\\(Response \\\$response, bool \\\$fpcHit\\): string\\s*\\{[\\s\\S]*?SharedResponseCachePolicy::isForbidden\\(\\)[\\s\\S]*?return 'MISS';/", $runtime);
}

private function assert_WlsRuntimeProcessL1Hydrate_testRuntimeSchedulesHydrateWithoutRevivingDeferredSsr(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    self::assertStringContainsString('StorefrontProcessL1HydrateCoordinator', $source);
    self::assertStringContainsString('runProcessL1HydrateCycle', $source);
    self::assertStringContainsString('shouldScheduleProcessL1Hydrate', $source);
    self::assertStringContainsString('shouldRunProcessL1HydrateAfterReady', $source);
    self::assertStringContainsString('homepage-fpc:process-l1-hydrate:adopted', $source);
    $coordinator = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Runtime/StorefrontProcessL1HydrateCoordinator.php');
    self::assertStringContainsString('process_l1_hydrate_enabled', $coordinator);
    self::assertStringContainsString('StorefrontProcessL1HydrateCoordinator::isEnabled()', $source);
    // Hydrate must use allowPrime=false (Shared pull only).
    self::assertStringContainsString('runHomepageFpcWarmupTransaction($host, 1, false)', $source);
    // allowPrime=false miss must fall back to Process/Shared inventory (no SSR).
    self::assertStringContainsString('resolveRootHomepageProcessReceipt', $source);
    self::assertStringContainsString('warmProcessCacheForFullUri', $source);
    self::assertStringContainsString('bindStorefrontContextForProcessL1Hydrate', $source);
    $coordinatorSource = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('buildProcessPayloadFromHtmlDisk', $coordinatorSource);
    // Deferred SSR stays hard-off.
    $needsBody = $this->extractMethodBody_WlsRuntimeProcessL1Hydrate($source, 'needsDeferredStorefrontWarmup');
    self::assertStringContainsString('return false;', $needsBody);
    $keepWarmBody = $this->extractMethodBody_WlsRuntimeProcessL1Hydrate($source, 'homepageKeepWarmEnabled');
    self::assertStringContainsString('return false;', $keepWarmBody);
    // Deferred bootstrap must call hydrate even when storefront critical is off.
    $deferredBody = $this->extractMethodBody_WlsRuntimeProcessL1Hydrate($source, 'runDeferredWorkerBootstrapWarmup');
    self::assertStringContainsString('runProcessL1HydrateCycle', $deferredBody);
    self::assertStringContainsString('shouldRunProcessL1HydrateAfterReady', $deferredBody);
    // Idle retries bags while Shared chrome still miss after homepage adopt.
    self::assertStringContainsString('processL1BagsPeekedOnce', $source);
    $scheduleBody = $this->extractMethodBody_WlsRuntimeProcessL1Hydrate($source, 'shouldScheduleProcessL1Hydrate');
    self::assertStringContainsString('processL1BagsPeekedOnce', $scheduleBody);
}

private function assert_WlsRuntimeProcessL1Hydrate_testWorkersScheduleProcessL1HydrateFiber(): void
{
    foreach (['worker.php', 'worker_ssl.php'] as $bin) {
        $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/' . $bin);
        self::assertStringContainsString('processL1HydrateFiber', $source, $bin);
        self::assertStringContainsString('shouldScheduleProcessL1Hydrate', $source, $bin);
        self::assertStringContainsString('runProcessL1HydrateCycle', $source, $bin);
        // Idle hydrate must not wait on the public application gate.
        $hydratePos = \strpos($source, 'shouldScheduleProcessL1Hydrate');
        self::assertNotFalse($hydratePos, $bin);
        $window = \substr($source, \max(0, $hydratePos - 500), 700);
        self::assertStringNotContainsString('isApplicationGateOpen()', $window, $bin . ': Process L1 Hydrate must retry under maintenance');
    }
}

private function assert_WlsRuntimeProcessL1Hydrate_testEnvDefaultsProcessL1HydrateEnabled(): void
{
    $env = require BP . 'app/code/Weline/Server/etc/env.php';
    self::assertTrue((bool) ($env['wls']['worker']['process_l1_hydrate_enabled'] ?? false));
    self::assertTrue((bool) ($env['wls']['worker']['ready_gate_homepage_fail_open'] ?? false));
}

private function extractMethodBody_WlsRuntimeProcessL1Hydrate(string $source, string $method): string
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

private function assert_WlsRuntimeStartPagePreviewMount_testStartPageNormalizesVisitorUriBeforeRootDetection(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $runtime = (string) file_get_contents($root . '/Runtime/WlsRuntime.php');
    self::assertStringContainsString('normalizeVisitorUriForStartPage', $runtime);
    self::assertStringContainsString('Url::normalizeVisitorUri', $runtime);
    self::assertStringContainsString('buildStartPageRequestUrlForSiteProbe', $runtime);
    self::assertMatchesRegularExpression('/private function applyFrontendRootStartPageRoute\(Request \$request\): void\s*\{[\s\S]*?normalizeVisitorUriForStartPage\(\$currentUri\)/', $runtime);
    // Website probe must run AFTER peel, using routing REQUEST_URI.
    self::assertMatchesRegularExpression('/normalizeVisitorUriForStartPage\(\$currentUri\);[\s\S]*?ensureWebsiteContextForStartPage\(\$request\)/', $runtime);
    self::assertMatchesRegularExpression('/\$routingUri = \$normalized\[\'routing_uri\'\];[\s\S]*?isRootRequestUri\(\$routingUri\)/', $runtime);
    // Path-locale visitor origin survives CMS start-page remap (/bn_BD → home).
    self::assertStringContainsString('preferVisitorOriginOverStartPageCanonical', $runtime);
    self::assertMatchesRegularExpression(
        '/preferVisitorOriginOverStartPageCanonical\(\$originUri,\s*\$canonicalUri\)/',
        $runtime
    );
    self::assertStringContainsString('resolveLocalizationFromPathSegments', $runtime);
    // Server must not hardcode Theme preview path segment.
    self::assertStringNotContainsString("'/~preview'", $runtime);
    self::assertStringNotContainsString('"/~preview"', $runtime);
}

private const RUNTIME_WlsRuntimeStorefrontWarmup = __DIR__ . '/../../../Runtime/WlsRuntime.php';

private const ENV_SAMPLE_WlsRuntimeStorefrontWarmup = __DIR__ . '/../../../../../../etc/env.sample.php';

private const WORKER_COMMON_WlsRuntimeStorefrontWarmup = __DIR__ . '/../../../bin/worker_runtime_common.php';

private function assert_WlsRuntimeStorefrontWarmup_testDeferredWarmupWaitsForTheFirstEventLoopAndPendingTransportWork(): void
{
    require_once self::WORKER_COMMON_WlsRuntimeStorefrontWarmup;
    self::assertFalse(wlsWorkerDeferredWarmupMayStart(false, true, false, false, false, false));
    self::assertTrue(wlsWorkerDeferredWarmupMayStart(false, true, false, true, false, false));
    self::assertFalse(wlsWorkerDeferredWarmupMayStart(false, true, false, true, true, false));
    self::assertTrue(wlsWorkerDeferredWarmupMayStart(false, true, false, true, false, false));
    self::assertFalse(wlsWorkerListenerHasPendingConnection(null));
}

private function assert_WlsRuntimeStorefrontWarmup_testDeferredWarmupKeepsAnIdleGraceWindowAfterTheFirstLoop(): void
{
    require_once self::WORKER_COMMON_WlsRuntimeStorefrontWarmup;
    $now = 100.0;
    self::assertGreaterThanOrEqual($now + 0.35, wlsWorkerDeferredWarmupNotBefore($now, 1));
    self::assertNotSame(wlsWorkerDeferredWarmupNotBefore($now, 1), wlsWorkerDeferredWarmupNotBefore($now, 2));
}

private function assert_WlsRuntimeStorefrontWarmup_testDynamicReadyGateKeepsTheUnifiedWarmupProviderAvailableWithoutBlockingStartup(): void
{
    $source = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    self::assertIsString($source);
    self::assertStringContainsString("Env::get('wls.worker.dynamic_ready_gate_enabled', '0')", $source);
    self::assertStringContainsString('FpcWarmupProviderInterface', $source);
    self::assertStringContainsString('warmupPaths()', $source);
    self::assertStringContainsString("Env::get('wls.worker.dynamic_ready_gate_fail_open', '1')", $source);
    self::assertStringContainsString('needsDeferredStorefrontWarmup', $source);
    self::assertStringContainsString('runStorefrontFpcWarmupInternal', $source);
    self::assertStringContainsString('ready:fpc-hit', $source);
    self::assertStringContainsString('logDeferredStorefrontWarmupStage', $source);
}

private function assert_WlsRuntimeStorefrontWarmup_testReadyGateBuildsABoundedAnonymousStorefrontFpcBeforePublicTraffic(): void
{
    $runtimeSource = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    $envSource = file_get_contents(self::ENV_SAMPLE_WlsRuntimeStorefrontWarmup);
    self::assertIsString($runtimeSource);
    self::assertIsString($envSource);
    self::assertStringContainsString('runReadyGateStorefrontFpcWarmup', $runtimeSource);
    self::assertStringContainsString("Env::get('wls.worker.storefront_ready_gate_enabled', '0')", $runtimeSource);
    self::assertStringContainsString("Env::get('wls.worker.storefront_ready_gate_max_paths', 4)", $runtimeSource);
    // Storefront deferred / dynamic deferred warm moved to Framework Cron;
    // Worker gates are hard-disabled (see WlsRuntimeNoStorefrontWarmScheduleContractTest).
    self::assertStringContainsString("Env::get('wls.worker.backend_deferred_warmup_enabled', '0')", $runtimeSource);
    self::assertStringContainsString('needsDeferredStorefrontWarmup', $runtimeSource);
    self::assertStringContainsString('shouldRunDeferredDynamicFirstRenderWarmup', $runtimeSource);
    self::assertStringContainsString("'storefront_ready_gate_enabled' => false", $envSource);
    self::assertStringContainsString("'storefront_ready_gate_max_paths' => 4", $envSource);
    self::assertStringContainsString("'storefront_deferred_warmup_enabled' => false", $envSource);
    self::assertStringContainsString("'backend_deferred_warmup_enabled' => false", $envSource);
    self::assertStringContainsString("'dynamic_deferred_warmup_enabled' => false", $envSource);
}

private function assert_WlsRuntimeStorefrontWarmup_testReadyGateRuntimeTraceHonorsTheConfiguredWlsDebugFlag(): void
{
    $runtimeSource = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    self::assertIsString($runtimeSource);
    self::assertStringContainsString("Env::get('wls.debug.worker_startup_trace', false)", $runtimeSource);
}

private function assert_WlsRuntimeStorefrontWarmup_testReadyGatePrefetchesStorefrontNamespaceVectorBeforeHomepageFpc(): void
{
    $runtimeSource = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    self::assertIsString($runtimeSource);
    self::assertStringContainsString('StorefrontNamespaceVectorWarmup::prefetchForReadyGate()', $runtimeSource);
    self::assertStringContainsString('storefront_namespace_prefetch_begin', $runtimeSource);
    $prefetchPos = \strpos($runtimeSource, 'StorefrontNamespaceVectorWarmup::prefetchForReadyGate()');
    $homepagePos = \strpos($runtimeSource, 'runRequiredHomepageProcessFpcReadyGate');
    self::assertNotFalse($prefetchPos);
    self::assertNotFalse($homepagePos);
    self::assertLessThan($homepagePos, $prefetchPos, 'namespace prefetch must run before the homepage FPC READY gate');
}

private function assert_WlsRuntimeStorefrontWarmup_testStorefrontWarmupPrefersPublicHostForFpcIdentity(): void
{
    $reflection = new \ReflectionClass(WlsRuntime::class);
    $runtime = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('selectStorefrontWarmupHost');
    $method->setAccessible(true);
    self::assertSame('p05113ef3.test.weline.com:9555', $method->invoke($runtime, ['p05113ef3.test.weline.com:9555', '127.0.0.1:9555']));
    self::assertSame('127.0.0.1:9555', $method->invoke($runtime, ['127.0.0.1:9555']));
}

private function assert_WlsRuntimeStorefrontWarmup_testSingleStorefrontPathIsWarmedByEveryWorker(): void
{
    $source = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    self::assertIsString($source);
    self::assertStringContainsString('if (\count($paths) <= 1) {', $source);
    self::assertStringContainsString("storefront_deferred_warmup_max_paths', 6)", $source);
}

private function assert_WlsRuntimeStorefrontWarmup_testDeferredWarmupOwnsHomepageWhenReadyGateFailOpen(): void
{
    $source = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    self::assertIsString($source);
    // Fail-open must NOT force Worker deferred storefront warm anymore.
    self::assertStringNotContainsString('homepageNeedsDeferredPrime', $source);
    self::assertStringContainsString('Abolish fail-open forced deferred', $source);
    self::assertStringContainsString("\$paths['/'] = '/';", $source);
}

private function assert_WlsRuntimeStorefrontWarmup_testDeferredStorefrontWarmupRunsOnlyOnConfiguredOwnerWorker(): void
{
    $reflection = new \ReflectionClass(WlsRuntime::class);
    $runtime = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('shouldRunDeferredStorefrontCriticalWarmup');
    $method->setAccessible(true);
    $previousRole = $_SERVER['WLS_PROCESS_ROLE'] ?? null;
    $previousWorkerId = $_SERVER['WLS_WORKER_ID'] ?? null;
    $_SERVER['WLS_PROCESS_ROLE'] = 'worker';
    $_SERVER['WLS_WORKER_ID'] = '1';
    \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=1');
    \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID=1');
    try {
        // Hard-disabled regardless of owner / env flag.
        self::assertFalse($method->invoke($runtime));
        $_SERVER['WLS_WORKER_ID'] = '2';
        self::assertFalse($method->invoke($runtime));
    } finally {
        if ($previousRole === null) {
            unset($_SERVER['WLS_PROCESS_ROLE']);
        } else {
            $_SERVER['WLS_PROCESS_ROLE'] = $previousRole;
        }
        if ($previousWorkerId === null) {
            unset($_SERVER['WLS_WORKER_ID']);
        } else {
            $_SERVER['WLS_WORKER_ID'] = $previousWorkerId;
        }
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED');
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID');
    }
}

private function assert_WlsRuntimeStorefrontWarmup_testDynamicReadyProofAcceptsTheFirstBusinessPathWhenHomepageIsNotSampled(): void
{
    $reflection = new \ReflectionClass(WlsRuntime::class);
    $runtime = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('buildDynamicFirstRenderReadyProof');
    $method->setAccessible(true);
    $proof = $method->invoke($runtime, ['samples' => [['host' => 'p05113ef3.test.weline.com:9555', 'path' => '/en_US/products', 'status' => 200, 'body_length' => 128, 'elapsed_ms' => 782.41, 'target_ms' => 70.0, 'attempts' => 1, 'fpc_status' => 'BYPASS', 'cache' => 'shared:storefront', 'ready' => true, 'reason' => 'ready:controller-cache']]]);
    self::assertIsArray($proof);
    self::assertTrue($proof['ready']);
    self::assertSame('/en_US/products', $proof['path']);
    self::assertSame(200, $proof['status_code']);
}

private function assert_WlsRuntimeStorefrontWarmup_testSampleConfigurationLeavesCriticalPathsToModuleProviders(): void
{
    $source = file_get_contents(self::ENV_SAMPLE_WlsRuntimeStorefrontWarmup);
    self::assertIsString($source);
    self::assertStringContainsString("'dynamic_ready_gate_enabled' => false", $source);
    self::assertStringContainsString("'dynamic_ready_gate_paths' => []", $source);
    self::assertStringContainsString("'dynamic_ready_gate_fail_open' => true", $source);
    self::assertStringContainsString("'worker_startup_trace' => false", $source);
    self::assertStringContainsString("'storefront_deferred_warmup_peer_wait_ms' => 5000", $source);
    self::assertStringContainsString("'storefront_deferred_warmup_owner_worker_id' => 1", $source);
}

private function assert_WlsRuntimeStorefrontWarmup_testSlowDiagnosticWarmupDoesNotRenderTheSamePathThreeTimesByDefault(): void
{
    $source = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    self::assertIsString($source);
    self::assertGreaterThanOrEqual(2, substr_count($source, '|| !$this->shouldBlockDynamicWarmupOnTargetMs()'));
}

private function assert_WlsRuntimeStorefrontWarmup_testRenderedBusinessPathWithoutControllerCacheHeaderIsStillReady(): void
{
    $reflection = new \ReflectionClass(WlsRuntime::class);
    $runtime = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('validateDynamicFirstRenderWarmup');
    $method->setAccessible(true);
    $validation = $method->invoke($runtime, ['headers' => ['X-WLS-FPC-Status' => 'BYPASS'], 'status_code' => 200, 'body_length' => 128, 'elapsed_ms' => 541.64], 70.0, true);
    self::assertIsArray($validation);
    self::assertTrue($validation['ok']);
    self::assertStringContainsString('ready:', $validation['reason']);
}

private function assert_WlsRuntimeStorefrontWarmup_testLowMemoryWorkerTightensDeferredBagPrimeDefaults(): void
{
    $source = file_get_contents(self::RUNTIME_WlsRuntimeStorefrontWarmup);
    self::assertIsString($source);
    self::assertStringContainsString('resolveStorefrontDeferredPrimeBudgetDefaults', $source);
    self::assertStringContainsString("Env::get('wls.worker_memory_limit'", $source);
    self::assertStringContainsString("'locale_max' => 2", $source);
    self::assertStringContainsString("'currency_max' => 1", $source);
    self::assertStringContainsString("'category_max_paths' => 2", $source);
    self::assertStringContainsString('$primeBudget[\'locale_max\']', $source);
    self::assertStringContainsString('$primeBudget[\'currency_max\']', $source);
    self::assertStringContainsString('$primeBudget[\'category_max_paths\']', $source);
    $reflection = new \ReflectionClass(WlsRuntime::class);
    $runtime = $reflection->newInstanceWithoutConstructor();
    $parse = $reflection->getMethod('parsePhpMemoryLimitToMb');
    $parse->setAccessible(true);
    self::assertSame(512, $parse->invoke($runtime, '512M'));
    self::assertSame(768, $parse->invoke($runtime, '768M'));
    self::assertSame(0, $parse->invoke($runtime, '-1'));
}
}
