<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Runtime\Task\Watch;
use Weline\Server\Service\Runtime\ResumableTaskWatchdogGateway;
use Weline\Server\Service\Runtime\WorkerReadinessState;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
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
            'RuntimeWatchdogBatch::testDueSubjectsBatchesActiveLeaseLookup' => function () {
            $this->assert_RuntimeWatchdogBatch_testDueSubjectsBatchesActiveLeaseLookup();
        },
            'RuntimeWatchdogBatch::testWatchDaemonIdlesWithBoundedBackoff' => function () {
            $this->assert_RuntimeWatchdogBatch_testWatchDaemonIdlesWithBoundedBackoff();
        },
            'WorkerReadinessHomepageStatusReport::testStatusReportHomepageFieldsFlattenHitProof' => function () {
            $this->setUp_WorkerReadinessHomepageStatusReport();
            try {
                $this->assert_WorkerReadinessHomepageStatusReport_testStatusReportHomepageFieldsFlattenHitProof();
            } finally {
                $this->tearDown_WorkerReadinessHomepageStatusReport();
            }
        },
            'WorkerReadinessHomepageStatusReport::testFailOpenDeferredMustNotReportWarm' => function () {
            $this->setUp_WorkerReadinessHomepageStatusReport();
            try {
                $this->assert_WorkerReadinessHomepageStatusReport_testFailOpenDeferredMustNotReportWarm();
            } finally {
                $this->tearDown_WorkerReadinessHomepageStatusReport();
            }
        },
            'WorkerReadinessHomepageStatusReport::testOverlayPrefersLastStatusReportAdoptedProofOverFailOpenMeta' => function () {
            $this->setUp_WorkerReadinessHomepageStatusReport();
            try {
                $this->assert_WorkerReadinessHomepageStatusReport_testOverlayPrefersLastStatusReportAdoptedProofOverFailOpenMeta();
            } finally {
                $this->tearDown_WorkerReadinessHomepageStatusReport();
            }
        },
            'WorkerReadinessHomepageStatusReport::testHomepageMetaFromStatusReportFieldsReturnsNullWithoutHitKey' => function () {
            $this->setUp_WorkerReadinessHomepageStatusReport();
            try {
                $this->assert_WorkerReadinessHomepageStatusReport_testHomepageMetaFromStatusReportFieldsReturnsNullWithoutHitKey();
            } finally {
                $this->tearDown_WorkerReadinessHomepageStatusReport();
            }
        },
            'WorkerReadinessHomepageStatusReport::testOrchestratorAuditAppliesHomepageFpcFromStatusReport' => function () {
            $this->setUp_WorkerReadinessHomepageStatusReport();
            try {
                $this->assert_WorkerReadinessHomepageStatusReport_testOrchestratorAuditAppliesHomepageFpcFromStatusReport();
            } finally {
                $this->tearDown_WorkerReadinessHomepageStatusReport();
            }
        },
            'WorkerReadinessHomepageStatusReport::testStatusSnapshotAndCliOverlayLastStatusReport' => function () {
            $this->setUp_WorkerReadinessHomepageStatusReport();
            try {
                $this->assert_WorkerReadinessHomepageStatusReport_testStatusSnapshotAndCliOverlayLastStatusReport();
            } finally {
                $this->tearDown_WorkerReadinessHomepageStatusReport();
            }
        },
            'WorkerReadinessHomepageStatusReport::testWorkersPushHomepageFieldsOnDeferredWarmupSuccess' => function () {
            $this->setUp_WorkerReadinessHomepageStatusReport();
            try {
                $this->assert_WorkerReadinessHomepageStatusReport_testWorkersPushHomepageFieldsOnDeferredWarmupSuccess();
            } finally {
                $this->tearDown_WorkerReadinessHomepageStatusReport();
            }
        },
        ];
    }

private function assert_RuntimeWatchdogBatch_testDueSubjectsBatchesActiveLeaseLookup(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Runtime/ResumableTaskWatchdogGateway.php');
    self::assertStringContainsString('hasActiveLeasesForTaskIds', $src);
    self::assertStringNotContainsString('hasActiveLeases($taskId, $now->getTimestamp())', $src);
}

private function assert_RuntimeWatchdogBatch_testWatchDaemonIdlesWithBoundedBackoff(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Console/Runtime/Task/Watch.php');
    self::assertStringContainsString('IDLE_TICK_MAX_MILLISECONDS', $src);
    self::assertStringContainsString('inspected > 0', $src);
    self::assertTrue((new \ReflectionClass(Watch::class))->hasConstant('IDLE_TICK_MAX_MILLISECONDS'));
}

private function setUp_WorkerReadinessHomepageStatusReport(): void
{
    WorkerReadinessState::reset('direct');
}

private function tearDown_WorkerReadinessHomepageStatusReport(): void
{
    WorkerReadinessState::reset('direct');
}

private function assert_WorkerReadinessHomepageStatusReport_testStatusReportHomepageFieldsFlattenHitProof(): void
{
    WorkerReadinessState::markBusinessHomepageHot(['hit' => true, 'fpc_status' => 'HIT', 'source' => 'process', 'full_uri' => 'https://p05113ef3.test.weline.com:9555/', 'reason' => 'homepage-fpc:deferred-warmup:adopted', 'http_status' => 200]);
    $fields = WorkerReadinessState::statusReportHomepageFields();
    self::assertSame(1, $fields['homepage_fpc_hit']);
    self::assertSame('HIT', $fields['homepage_fpc_status']);
    self::assertSame('process', $fields['homepage_fpc_source']);
    self::assertSame('homepage-fpc:deferred-warmup:adopted', $fields['homepage_fpc_reason']);
    self::assertSame('https://p05113ef3.test.weline.com:9555/', $fields['homepage_fpc_full_uri']);
    self::assertSame(200, $fields['homepage_fpc_http_status']);
    self::assertSame('hot', $fields['warmup_state']);
}

private function assert_WorkerReadinessHomepageStatusReport_testFailOpenDeferredMustNotReportWarm(): void
{
    WorkerReadinessState::markBusinessHomepageHot(['hit' => false, 'fpc_status' => '', 'source' => '', 'full_uri' => '', 'reason' => 'homepage-fpc:deferred-after-ready:fail-open', 'http_status' => 0]);
    $fields = WorkerReadinessState::statusReportHomepageFields();
    self::assertSame(0, $fields['homepage_fpc_hit']);
    self::assertSame('deferred', $fields['warmup_state']);
    self::assertNotSame('warm', $fields['warmup_state']);
}

private function assert_WorkerReadinessHomepageStatusReport_testOverlayPrefersLastStatusReportAdoptedProofOverFailOpenMeta(): void
{
    $metadata = WorkerReadinessState::overlayHomepageFpcMetaFromLastStatusReport(['warmup_state' => 'warm', 'homepage_fpc' => ['hit' => false, 'fpc_status' => '', 'source' => '', 'full_uri' => '', 'reason' => 'homepage-fpc:deferred-after-ready:fail-open', 'http_status' => 0], 'last_status_report' => ['warmup_state' => 'hot', 'homepage_fpc_hit' => 1, 'homepage_fpc_status' => 'HIT', 'homepage_fpc_source' => 'process', 'homepage_fpc_reason' => 'homepage-fpc:deferred-warmup:adopted', 'homepage_fpc_full_uri' => 'https://p05113ef3.test.weline.com:9555/', 'homepage_fpc_http_status' => 200]]);
    self::assertTrue($metadata['homepage_fpc']['hit']);
    self::assertSame('HIT', $metadata['homepage_fpc']['fpc_status']);
    self::assertSame('process', $metadata['homepage_fpc']['source']);
    self::assertSame('homepage-fpc:deferred-warmup:adopted', $metadata['homepage_fpc']['reason']);
    self::assertSame('hot', $metadata['warmup_state']);
}

private function assert_WorkerReadinessHomepageStatusReport_testHomepageMetaFromStatusReportFieldsReturnsNullWithoutHitKey(): void
{
    self::assertNull(WorkerReadinessState::homepageMetaFromStatusReportFields(['connections' => 0, 'memory' => 1]));
}

private function assert_WorkerReadinessHomepageStatusReport_testOrchestratorAuditAppliesHomepageFpcFromStatusReport(): void
{
    $source = \file_get_contents(BP . 'app/code/Weline/Server/Service/ServiceOrchestrator.php');
    self::assertIsString($source);
    self::assertStringContainsString('homepageMetaFromStatusReportFields', $source);
    self::assertStringContainsString("setMeta('homepage_fpc', \$homepageOverlay['homepage_fpc'])", $source);
}

private function assert_WorkerReadinessHomepageStatusReport_testStatusSnapshotAndCliOverlayLastStatusReport(): void
{
    $registrySource = (string) \file_get_contents(BP . 'app/code/Weline/Server/Service/ServiceRegistry.php');
    $statusSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/Console/Server/Status.php');
    $managerSource = (string) \file_get_contents(BP . 'app/code/Weline/Server/Service/ServerInstanceManager.php');
    self::assertStringContainsString('overlayHomepageFpcMetaFromLastStatusReport', $registrySource);
    self::assertStringContainsString('overlayHomepageFpcMetaFromLastStatusReport', $statusSource);
    self::assertStringContainsString('overlayHomepageFpcMetaFromLastStatusReport', $managerSource);
}

private function assert_WorkerReadinessHomepageStatusReport_testWorkersPushHomepageFieldsOnDeferredWarmupSuccess(): void
{
    foreach (['worker.php', 'worker_ssl.php'] as $script) {
        $source = \file_get_contents(BP . 'app/code/Weline/Server/bin/' . $script);
        self::assertIsString($source, $script);
        self::assertStringContainsString('WorkerReadinessState::statusReportHomepageFields', $source, $script);
        self::assertStringContainsString('homepage_fpc_hit', $source, $script);
    }
}
}
