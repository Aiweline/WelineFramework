<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Capacity\CapacityAdvisor;
use Weline\Server\Service\Capacity\CapacityAdvisorConfigService;
use Weline\Server\Service\Capacity\CapacityIntent;
use Weline\Server\Service\Capacity\CapacityScaleApplier;
use Weline\Server\Service\Capacity\HostCapacityTier;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllServerCapacityContractsPass().
 */
final class ServerCapacityContractsTest extends TestCase
{
    public function testAllServerCapacityContractsPass(): void
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
            'CapacityAdvisorEvaluate::testSustainedSoftTriggersAdviceWithoutScaleApi' => function () {
            $this->setUp_CapacityAdvisorEvaluate();
            $this->assert_CapacityAdvisorEvaluate_testSustainedSoftTriggersAdviceWithoutScaleApi();
        },
            'CapacityAdvisorEvaluate::testRunnerSourceDoesNotCallScale' => function () {
            $this->setUp_CapacityAdvisorEvaluate();
            $this->assert_CapacityAdvisorEvaluate_testRunnerSourceDoesNotCallScale();
        },
            'CapacityAdvisorEvaluate::testConfigServiceDefaultsHaveFloorInterval' => function () {
            $this->setUp_CapacityAdvisorEvaluate();
            $this->assert_CapacityAdvisorEvaluate_testConfigServiceDefaultsHaveFloorInterval();
        },
            'CapacityAdvisorEvaluate::testFiberAdmissionSaturatedAdvisesRaiseWorkerCount' => function () {
            $this->setUp_CapacityAdvisorEvaluate();
            $this->assert_CapacityAdvisorEvaluate_testFiberAdmissionSaturatedAdvisesRaiseWorkerCount();
        },
            'CapacityAdvisorIntent::testHostPressureForcesNoopEvenWhenBusy' => function () {
            $this->setUp_CapacityAdvisorIntent();
            $this->assert_CapacityAdvisorIntent_testHostPressureForcesNoopEvenWhenBusy();
        },
            'CapacityAdvisorIntent::testBusyWithHostHeadroomProducesScaleTo' => function () {
            $this->setUp_CapacityAdvisorIntent();
            $this->assert_CapacityAdvisorIntent_testBusyWithHostHeadroomProducesScaleTo();
        },
            'CapacityAdvisorIntent::testRunnerStillMustNeverCallScaleApis' => function () {
            $this->setUp_CapacityAdvisorIntent();
            $this->assert_CapacityAdvisorIntent_testRunnerStillMustNeverCallScaleApis();
        },
            'CapacityDispatcherRouteFence::testOrchestratorCapacityFenceUsesSetRouteTableAuthority' => function () {
            $this->assert_CapacityDispatcherRouteFence_testOrchestratorCapacityFenceUsesSetRouteTableAuthority();
        },
            'CapacityDispatcherRouteFence::testScalingControllerAutoScaleDisabledDualBrain' => function () {
            $this->assert_CapacityDispatcherRouteFence_testScalingControllerAutoScaleDisabledDualBrain();
        },
            'CapacityScaleApplier::testAppliesRuntimeDesiredWithoutTouchingBaselineSemantics' => function () {
            $this->setUp_CapacityScaleApplier();
            $this->assert_CapacityScaleApplier_testAppliesRuntimeDesiredWithoutTouchingBaselineSemantics();
        },
            'CapacityScaleApplier::testClampsDownToBaseline' => function () {
            $this->setUp_CapacityScaleApplier();
            $this->assert_CapacityScaleApplier_testClampsDownToBaseline();
        },
            'CapacityScaleApplier::testOpsMutexNoops' => function () {
            $this->setUp_CapacityScaleApplier();
            $this->assert_CapacityScaleApplier_testOpsMutexNoops();
        },
            'CapacityScaleApplier::testBudgetGateNoopsOnScaleUp' => function () {
            $this->setUp_CapacityScaleApplier();
            $this->assert_CapacityScaleApplier_testBudgetGateNoopsOnScaleUp();
        },
            'CapacityScaleApplier::testPressurePriorityReasonPreserved' => function () {
            $this->setUp_CapacityScaleApplier();
            $this->assert_CapacityScaleApplier_testPressurePriorityReasonPreserved();
        },
            'HostCapacityTier::testTierBoundaries' => function () {
            $this->assert_HostCapacityTier_testTierBoundaries();
        },
            'HostCapacityTier::testUnknownHostFailsOpenToMedium' => function () {
            $this->assert_HostCapacityTier_testUnknownHostFailsOpenToMedium();
        },
            'HostCapacityTier::testForceAndDisabled' => function () {
            $this->assert_HostCapacityTier_testForceAndDisabled();
        },
            'HostCapacityTier::testSurvivalOverridesPinAndUnlimitedOnTiny' => function () {
            $this->assert_HostCapacityTier_testSurvivalOverridesPinAndUnlimitedOnTiny();
        },
            'HostCapacityTier::testMediumRespectsPinAndDoesNotCapFiber' => function () {
            $this->assert_HostCapacityTier_testMediumRespectsPinAndDoesNotCapFiber();
        },
            'HostCapacityTier::testPinnedStorefrontWorkerFloorRaisesMediumPinBelowFour' => function () {
            $this->assert_HostCapacityTier_testPinnedStorefrontWorkerFloorRaisesMediumPinBelowFour();
        },
            'HostCapacityTier::testStartWiresPinnedStorefrontWorkerFloor' => function () {
            $this->assert_HostCapacityTier_testStartWiresPinnedStorefrontWorkerFloor();
        },
            'HostCapacityTier::testNginxAndEdgeFragments' => function () {
            $this->assert_HostCapacityTier_testNginxAndEdgeFragments();
        },
            'HostCapacityTier::testScaleAttackRulesDoesNotMutateWhenScaleOne' => function () {
            $this->assert_HostCapacityTier_testScaleAttackRulesDoesNotMutateWhenScaleOne();
        },
        ];
    }

private function setUp_CapacityAdvisorEvaluate(): void
{
    CapacityAdvisor::resetRingsForTests();
}

private function assert_CapacityAdvisorEvaluate_testSustainedSoftTriggersAdviceWithoutScaleApi(): void
{
    $advisor = new CapacityAdvisor();
    $config = CapacityAdvisorConfigService::defaults();
    $config['sustained_soft_sample_ratio'] = 0.5;
    $config['soft_pressure'] = 0.7;
    $now = 1000000.0;
    $result = null;
    for ($i = 0; $i < 6; $i++) {
        $result = $advisor->evaluate('default', [['report' => ['memory_percent' => 0.85, 'connections' => 2, 'active_requests' => 1, 'compact_soft_count' => 0, 'compact_hard_count' => 0], 'at' => $now + $i]], 0.2, 0.5, $config, $now + $i);
    }
    self::assertNotNull($result);
    self::assertTrue($result['triggered']);
    self::assertContains('sustained_soft', $result['reason_codes']);
    self::assertContains('raise_worker_memory_limit', $result['advice']);
}

private function assert_CapacityAdvisorEvaluate_testRunnerSourceDoesNotCallScale(): void
{
    $serverRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $runnerFile = $serverRoot . '/Service/Capacity/CapacityAdviseRunner.php';
    $advisorFile = $serverRoot . '/Service/Capacity/CapacityAdvisor.php';
    self::assertFileExists($runnerFile);
    self::assertFileExists($advisorFile);
    $runner = (string) \file_get_contents($runnerFile);
    $advisor = (string) \file_get_contents($advisorFile);
    self::assertStringNotContainsString('ACTION_SCALE_WORKERS', $runner);
    self::assertStringNotContainsString('scaleDown', $runner);
    self::assertStringNotContainsString('scaleUp', $runner);
    self::assertStringNotContainsString('ACTION_SCALE_WORKERS', $advisor);
    self::assertStringNotContainsString('Env::set', $runner);
    self::assertStringNotContainsString('env.php', $runner);
}

private function assert_CapacityAdvisorEvaluate_testConfigServiceDefaultsHaveFloorInterval(): void
{
    $svc = new CapacityAdvisorConfigService(null);
    $cfg = $svc->get();
    self::assertGreaterThanOrEqual(60, (int) $cfg['interval_sec']);
    self::assertTrue((bool) $cfg['enabled']);
}

private function assert_CapacityAdvisorEvaluate_testFiberAdmissionSaturatedAdvisesRaiseWorkerCount(): void
{
    $advisor = new CapacityAdvisor();
    $config = CapacityAdvisorConfigService::defaults();
    $result = $advisor->evaluate('default', [['report' => ['last_pressure' => 0.4, 'connections' => 4, 'active_requests' => 2, 'compact_soft_count' => 0, 'compact_hard_count' => 0, 'fiber_admission_saturated' => 1], 'at' => 1000000.0]], 0.2, 0.5, $config, 1000000.0);
    self::assertTrue($result['triggered']);
    self::assertContains('fiber_admission_saturated', $result['reason_codes']);
    self::assertContains('raise_worker_count', $result['advice']);
    self::assertSame(1, $result['sample']['fiber_admission_saturated_workers']);
}

private function setUp_CapacityAdvisorIntent(): void
{
    CapacityAdvisor::resetRingsForTests();
}

private function assert_CapacityAdvisorIntent_testHostPressureForcesNoopEvenWhenBusy(): void
{
    $advisor = new CapacityAdvisor();
    $config = CapacityAdvisorConfigService::defaults();
    $config['sustained_soft_sample_ratio'] = 0.5;
    $config['soft_pressure'] = 0.7;
    $now = 1000000.0;
    $intent = null;
    for ($i = 0; $i < 6; $i++) {
        $intent = $advisor->evaluateIntent('default', [['report' => ['memory_percent' => 0.85, 'connections' => 2, 'active_requests' => 4, 'compact_soft_count' => 0, 'compact_hard_count' => 0], 'at' => $now + $i]], 0.95, 0.05, $config, $now + $i, ['current_desired' => 2, 'baseline' => 2, 'max_workers' => 8, 'auto_scale_enabled' => true]);
    }
    self::assertInstanceOf(CapacityIntent::class, $intent);
    self::assertSame(CapacityIntent::ACTION_NOOP, $intent->action);
    self::assertSame('host_pressure', $intent->reason);
}

private function assert_CapacityAdvisorIntent_testBusyWithHostHeadroomProducesScaleTo(): void
{
    CapacityAdvisor::resetRingsForTests();
    $advisor = new CapacityAdvisor();
    $config = CapacityAdvisorConfigService::defaults();
    $config['sustained_soft_sample_ratio'] = 0.5;
    $config['soft_pressure'] = 0.7;
    $now = 2000000.0;
    $intent = null;
    for ($i = 0; $i < 6; $i++) {
        $intent = $advisor->evaluateIntent('default', [['report' => ['memory_percent' => 0.85, 'connections' => 2, 'active_requests' => 4, 'compact_soft_count' => 0, 'compact_hard_count' => 0], 'at' => $now + $i]], 0.2, 0.5, $config, $now + $i, ['current_desired' => 2, 'baseline' => 2, 'max_workers' => 8, 'auto_scale_enabled' => true]);
    }
    self::assertInstanceOf(CapacityIntent::class, $intent);
    self::assertTrue($intent->isScale());
    self::assertSame(3, $intent->target);
    self::assertSame('workers_busy_host_headroom', $intent->reason);
}

private function assert_CapacityAdvisorIntent_testRunnerStillMustNeverCallScaleApis(): void
{
    $serverRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $runner = (string) \file_get_contents($serverRoot . '/Service/Capacity/CapacityAdviseRunner.php');
    $advisor = (string) \file_get_contents($serverRoot . '/Service/Capacity/CapacityAdvisor.php');
    self::assertStringNotContainsString('updateRuntimeDesiredWorkerCount', $runner);
    self::assertStringNotContainsString('updateDesiredWorkerCount', $runner);
    self::assertStringNotContainsString('CapacityScaleApplier', $runner);
    self::assertStringNotContainsString('updateRuntimeDesiredWorkerCount', $advisor);
    self::assertStringNotContainsString('desiredState', $advisor);
}

private function assert_CapacityDispatcherRouteFence_testOrchestratorCapacityFenceUsesSetRouteTableAuthority(): void
{
    $orchestrator = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/ServiceOrchestrator.php';
    $applier = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Capacity/CapacityScaleApplier.php';
    self::assertFileExists($orchestrator);
    self::assertFileExists($applier);
    $src = (string) \file_get_contents($orchestrator);
    $applierSrc = (string) \file_get_contents($applier);
    self::assertStringContainsString('publishCapacityScaleRouteFence', $src);
    self::assertStringContainsString('syncDispatcherFullWorkerPoolFromRegistry', $src);
    self::assertStringContainsString('before_scale_down', $src);
    self::assertStringContainsString('after_scale_down', $src);
    self::assertStringContainsString('after_scale_up', $src);
    self::assertStringContainsString('workerRoutePublishSuppressedInstanceIds', $src);
    // Applier publishes fence before drain execute on scale-down.
    $beforePos = \strpos($applierSrc, "publish('before_scale_down')");
    $executePos = \strpos($applierSrc, '$execute(');
    self::assertNotFalse($beforePos);
    self::assertNotFalse($executePos);
    self::assertLessThan($executePos, $beforePos, 'SET_ROUTE_TABLE fence must precede drain execute');
    self::assertSame('set_route_table', ControlMessage::TYPE_SET_ROUTE_TABLE);
    self::assertTrue(\method_exists(ControlMessage::class, 'setRouteTable'));
}

private function assert_CapacityDispatcherRouteFence_testScalingControllerAutoScaleDisabledDualBrain(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/ScalingController.php';
    $src = (string) \file_get_contents($file);
    self::assertStringContainsString('dual-brain disabled', $src);
    self::assertMatchesRegularExpression('/function\s+autoScale\s*\([^)]*\)\s*:\s*\?array\s*\{[^}]*return\s+null;/s', $src);
}

private function setUp_CapacityScaleApplier(): void
{
    CapacityScaleApplier::resetForTests();
}

private function assert_CapacityScaleApplier_testAppliesRuntimeDesiredWithoutTouchingBaselineSemantics(): void
{
    $desired = 2;
    $persisted = null;
    $applier = new CapacityScaleApplier();
    $intent = CapacityIntent::scaleTo('default', 3, 'workers_busy_host_headroom');
    $result = $applier->apply($intent, ['instance' => 'default', 'current_desired' => $desired, 'baseline' => 2, 'max_workers' => 8, 'min_workers' => 1, 'skip_budget_gate' => true, 'set_desired' => static function (int $n) use (&$desired): void {
        $desired = $n;
    }, 'persist_runtime' => static function (int $n) use (&$persisted): void {
        $persisted = $n;
    }, 'execute' => static fn(): bool => true]);
    self::assertTrue($result['applied']);
    self::assertSame(3, $desired);
    self::assertSame(3, $persisted);
    self::assertSame(3, (int) $result['to']);
    self::assertSame(2, (int) $result['from']);
}

private function assert_CapacityScaleApplier_testClampsDownToBaseline(): void
{
    $desired = 4;
    $applier = new CapacityScaleApplier();
    $intent = CapacityIntent::scaleTo('default', 1, 'idle_revert_baseline');
    $result = $applier->apply($intent, ['instance' => 'default', 'current_desired' => $desired, 'baseline' => 2, 'max_workers' => 8, 'skip_budget_gate' => true, 'set_desired' => static function (int $n) use (&$desired): void {
        $desired = $n;
    }, 'persist_runtime' => static function (): void {
    }, 'execute' => static fn(): bool => true]);
    self::assertTrue($result['applied']);
    self::assertSame(2, $desired, 'must not shrink below baseline');
}

private function assert_CapacityScaleApplier_testOpsMutexNoops(): void
{
    $applier = new CapacityScaleApplier();
    $result = $applier->apply(CapacityIntent::scaleTo('default', 3, 'workers_busy_host_headroom'), ['instance' => 'default', 'current_desired' => 2, 'baseline' => 2, 'max_workers' => 8, 'ops_mutex' => true, 'skip_budget_gate' => true]);
    self::assertFalse($result['applied']);
    self::assertSame('ops_mutex', $result['reason']);
}

private function assert_CapacityScaleApplier_testBudgetGateNoopsOnScaleUp(): void
{
    $applier = new CapacityScaleApplier();
    $result = $applier->apply(CapacityIntent::scaleTo('default', 3, 'workers_busy_host_headroom'), ['instance' => 'default', 'current_desired' => 2, 'baseline' => 2, 'max_workers' => 8, 'env' => ['wls' => ['fiber' => ['max_active' => 12]], 'db' => ['master' => ['pool_size' => 4]]], 'pg_max_connections' => 200]);
    self::assertFalse($result['applied']);
    self::assertSame('db_budget', $result['reason']);
}

private function assert_CapacityScaleApplier_testPressurePriorityReasonPreserved(): void
{
    $desired = 3;
    $applier = new CapacityScaleApplier();
    $result = $applier->apply(CapacityIntent::scaleTo('default', 2, 'pressure_reclaim', priority: CapacityScaleApplier::PRIORITY_PRESSURE_RECLAIM), ['instance' => 'default', 'current_desired' => $desired, 'baseline' => 2, 'max_workers' => 8, 'skip_budget_gate' => true, 'set_desired' => static function (int $n) use (&$desired): void {
        $desired = $n;
    }, 'persist_runtime' => static function (): void {
    }, 'execute' => static fn(): bool => true]);
    self::assertTrue($result['applied']);
    self::assertSame('pressure_reclaim', $result['reason']);
}

private function assert_HostCapacityTier_testTierBoundaries(): void
{
    self::assertSame(HostCapacityTier::TIER_TINY, HostCapacityTier::resolve(1024)['tier']);
    self::assertSame(HostCapacityTier::TIER_TINY, HostCapacityTier::resolve(1536)['tier']);
    self::assertSame(HostCapacityTier::TIER_SMALL, HostCapacityTier::resolve(1537)['tier']);
    self::assertSame(HostCapacityTier::TIER_SMALL, HostCapacityTier::resolve(2300)['tier']);
    self::assertSame(HostCapacityTier::TIER_MEDIUM, HostCapacityTier::resolve(2301)['tier']);
    self::assertSame(HostCapacityTier::TIER_MEDIUM, HostCapacityTier::resolve(8192)['tier']);
    self::assertSame(HostCapacityTier::TIER_LARGE, HostCapacityTier::resolve(8193)['tier']);
}

private function assert_HostCapacityTier_testUnknownHostFailsOpenToMedium(): void
{
    $snap = HostCapacityTier::resolve(0, ['enabled' => true, 'force' => '']);
    // resolve(0) with no force: treat as unknown → medium via detect path.
    // Passing null triggers detect; passing 0 is treated as unknown in resolve.
    $snap = HostCapacityTier::resolve(null, ['enabled' => true, 'force' => '']);
    // When detect also fails in test env, fail-open medium.
    if (($snap['reason'] ?? '') === 'host_limit_unknown_fail_open_medium' || $snap['host_limit_mb'] <= 0) {
        self::assertSame(HostCapacityTier::TIER_MEDIUM, $snap['tier']);
        self::assertFalse($snap['survival_overrides_pin']);
    } else {
        self::assertContains($snap['tier'], [HostCapacityTier::TIER_TINY, HostCapacityTier::TIER_SMALL, HostCapacityTier::TIER_MEDIUM, HostCapacityTier::TIER_LARGE]);
    }
}

private function assert_HostCapacityTier_testForceAndDisabled(): void
{
    $forced = HostCapacityTier::resolve(65536, ['force' => 'tiny']);
    self::assertSame(HostCapacityTier::TIER_TINY, $forced['tier']);
    self::assertTrue($forced['survival_overrides_pin']);
    $off = HostCapacityTier::resolve(512, ['enabled' => false]);
    self::assertFalse($off['enabled']);
    self::assertFalse($off['edge_limit_enabled']);
    self::assertSame(HostCapacityTier::TIER_LARGE, $off['tier']);
}

private function assert_HostCapacityTier_testSurvivalOverridesPinAndUnlimitedOnTiny(): void
{
    $applied = HostCapacityTier::applyFiberAdmission(['max_active' => 6, 'queue_wait_ms' => 30000, 'queue_depth' => 64, 'queue_slice_ms' => 25, 'admission_memory_headroom_bytes' => 0, 'pin_max_active' => true, 'adaptive_max_active' => false], 6, 1024);
    self::assertSame(2, $applied['max_active']);
    self::assertFalse($applied['pin_max_active']);
    self::assertSame(10000, $applied['queue_wait_ms']);
    self::assertSame(HostCapacityTier::TIER_TINY, $applied['capacity_tier']);
    $unlimited = HostCapacityTier::applyFiberAdmission(['max_active' => 0, 'queue_wait_ms' => 30000, 'queue_depth' => 0, 'queue_slice_ms' => 25, 'pin_max_active' => true, 'adaptive_max_active' => false], 0, 1024);
    self::assertSame(2, $unlimited['max_active']);
}

private function assert_HostCapacityTier_testMediumRespectsPinAndDoesNotCapFiber(): void
{
    $applied = HostCapacityTier::applyFiberAdmission(['max_active' => 6, 'queue_wait_ms' => 30000, 'queue_depth' => 64, 'queue_slice_ms' => 25, 'pin_max_active' => true, 'adaptive_max_active' => false], 6, 4096);
    self::assertSame(6, $applied['max_active']);
    self::assertTrue($applied['pin_max_active']);
    self::assertSame(30000, $applied['queue_wait_ms']);
    self::assertSame(HostCapacityTier::TIER_MEDIUM, $applied['capacity_tier']);
}

private function assert_HostCapacityTier_testPinnedStorefrontWorkerFloorRaisesMediumPinBelowFour(): void
{
    $snap = HostCapacityTier::resolve(4096);
    $raised = HostCapacityTier::applyPinnedStorefrontWorkerFloor(2, true, $snap);
    self::assertTrue($raised['applied']);
    self::assertSame(4, $raised['count']);
    $kept = HostCapacityTier::applyPinnedStorefrontWorkerFloor(8, true, $snap);
    self::assertFalse($kept['applied']);
    self::assertSame(8, $kept['count']);
    $tiny = HostCapacityTier::resolve(1024);
    $skip = HostCapacityTier::applyPinnedStorefrontWorkerFloor(2, true, $tiny);
    self::assertFalse($skip['applied']);
    self::assertSame(2, $skip['count']);
}

private function assert_HostCapacityTier_testStartWiresPinnedStorefrontWorkerFloor(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Server/Console/Server/Start.php');
    self::assertStringContainsString('applyPinnedStorefrontWorkerFloor', $src);
    self::assertStringContainsString('worker_count_requested', $src);
    self::assertStringContainsString('_pinned_storefront_worker_floor_applied', $src);
}

private function assert_HostCapacityTier_testNginxAndEdgeFragments(): void
{
    self::assertSame(4096, HostCapacityTier::clampNginxWorkerConnections(32768, 1024));
    self::assertSame(32, HostCapacityTier::clampNginxUpstreamKeepalive(256, 1024));
    $edge = HostCapacityTier::nginxEdgeLimitFragments(1024);
    self::assertTrue($edge['enabled']);
    self::assertStringContainsString('limit_req_zone', $edge['http_zone_block']);
    self::assertStringContainsString('limit_req zone=wls_req', $edge['location_directives']);
    $large = HostCapacityTier::nginxEdgeLimitFragments(16384);
    self::assertTrue($large['enabled']);
    self::assertSame(200, $large['rate']);
}

private function assert_HostCapacityTier_testScaleAttackRulesDoesNotMutateWhenScaleOne(): void
{
    $rules = ['rate_limit' => ['enabled' => true, 'window' => 60, 'max_requests' => 3000], 'path_rate_limits' => ['enabled' => true, 'rules' => [['path' => '/api/framework/query', 'max_requests' => 120]]]];
    $scaled = HostCapacityTier::scaleAttackRules($rules, 1024);
    self::assertSame(750, $scaled['rate_limit']['max_requests']);
    self::assertSame(30, $scaled['path_rate_limits']['rules'][0]['max_requests']);
    self::assertSame(3000, $rules['rate_limit']['max_requests']);
}
}
