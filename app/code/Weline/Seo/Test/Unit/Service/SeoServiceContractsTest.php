<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Cron\SeoOptimizationSchedule;
use Weline\Seo\Model\SeoOptimizationPolicy;
use Weline\Seo\Service\PlatformVisual;
use Weline\Seo\Service\SeoPlatformCode;

/**
 * Aggregated contract suite (merged from 13 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoServiceContractsPass().
 */
final class SeoServiceContractsTest extends TestCase
{
    public function testAllSeoServiceContractsPass(): void
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
            'OptimizationClockPropagation::testActivityAndControlCenterUseTheOptimizationClock' => function () {
            $this->assert_OptimizationClockPropagation_testActivityAndControlCenterUseTheOptimizationClock();
        },
            'OptimizationEvidenceAvailability::testVisitorAndSearchEvidenceUnavailableFailClosedBeforeExperimentOrPublish' => function () {
            $this->assert_OptimizationEvidenceAvailability_testVisitorAndSearchEvidenceUnavailableFailClosedBeforeExperimentOrPublish();
        },
            'PlatformVisualLogo::testMainstreamPlatformsRenderRealLogoAssetsNotLetterBlocks' => function () {
            $this->assert_PlatformVisualLogo_testMainstreamPlatformsRenderRealLogoAssetsNotLetterBlocks();
        },
            'SearchPerformanceSnapshotEvidenceAvailability::testEmptySearchWindowIsExplicitlyUnavailableToTheEvidenceBoundary' => function () {
            $this->assert_SearchPerformanceSnapshotEvidenceAvailability_testEmptySearchWindowIsExplicitlyUnavailableToTheEvidenceBoundary();
        },
            'SearchPerformanceSnapshotEvidenceAvailability::testAcceptanceFixtureIsDualGatedBackendOnlyAndUsesCanonicalStatsModel' => function () {
            $this->assert_SearchPerformanceSnapshotEvidenceAvailability_testAcceptanceFixtureIsDualGatedBackendOnlyAndUsesCanonicalStatsModel();
        },
            'SearchPerformanceSnapshotEvidenceAvailability::testAcceptanceFixtureContractKeepsWebsiteZeroAndExactCleanupSemantics' => function () {
            $this->assert_SearchPerformanceSnapshotEvidenceAvailability_testAcceptanceFixtureContractKeepsWebsiteZeroAndExactCleanupSemantics();
        },
            'SearchPerformanceSnapshotEvidenceAvailability::testCurrentAndPreviousSearchWindowFixtureMathIsExact' => function () {
            $this->assert_SearchPerformanceSnapshotEvidenceAvailability_testCurrentAndPreviousSearchWindowFixtureMathIsExact();
        },
            'SeoOptimizationControlCenterAuthorizedSites::testAuthorizedSitesUsesPublishedDirectoryInsteadOfBackendAreaQuery' => function () {
            $this->assert_SeoOptimizationControlCenterAuthorizedSites_testAuthorizedSitesUsesPublishedDirectoryInsteadOfBackendAreaQuery();
        },
            'SeoOptimizationControlCenterFrontend::testOptimizationConsoleUsesOfficialWebsiteSelectAndKeepEvolveSurface' => function () {
            $this->assert_SeoOptimizationControlCenterFrontend_testOptimizationConsoleUsesOfficialWebsiteSelectAndKeepEvolveSurface();
        },
            'SeoOptimizationEvaluationLifecycle::testEvaluationRecordsPublishObserveAndTerminalActivity' => function () {
            $this->assert_SeoOptimizationEvaluationLifecycle_testEvaluationRecordsPublishObserveAndTerminalActivity();
        },
            'SeoOptimizationEvaluationLifecycle::testEvaluatingCandidateReconcilesItsPublicationReceiptBeforeObservation' => function () {
            $this->assert_SeoOptimizationEvaluationLifecycle_testEvaluatingCandidateReconcilesItsPublicationReceiptBeforeObservation();
        },
            'SeoOptimizationEvaluationLifecycle::testUnrelatedPlanRevisionAdvanceKeepsExactCandidateEligible' => function () {
            $this->assert_SeoOptimizationEvaluationLifecycle_testUnrelatedPlanRevisionAdvanceKeepsExactCandidateEligible();
        },
            'SeoOptimizationOrchestratorHeatRanking::testSelectTargetsPrefersQueryHeatMatchesWhenCloudExists' => function () {
            $this->assert_SeoOptimizationOrchestratorHeatRanking_testSelectTargetsPrefersQueryHeatMatchesWhenCloudExists();
        },
            'SeoOptimizationQueueSchedulerAdmission::testOptimizationAdmissionsLeaveExecutionToTheSystemScheduler' => function () {
            $this->assert_SeoOptimizationQueueSchedulerAdmission_testOptimizationAdmissionsLeaveExecutionToTheSystemScheduler();
        },
            'SeoOptimizationQueueSchedulerAdmission::testAutomaticScheduleOnlyAdmitsExplicitPersistedPolicies' => function () {
            $this->assert_SeoOptimizationQueueSchedulerAdmission_testAutomaticScheduleOnlyAdmitsExplicitPersistedPolicies();
        },
            'SeoOptimizationQueueSchedulerAdmission::testAcceptanceExactTargetModeDisablesOnlyAutomaticSiteWideAnalysis' => function () {
            $this->assert_SeoOptimizationQueueSchedulerAdmission_testAcceptanceExactTargetModeDisablesOnlyAutomaticSiteWideAnalysis();
        },
            'SeoOptimizationTerminalCheckpointReconciliation::testTerminalCheckpointIsReleasedThroughTheAdapterAndRefreshed' => function () {
            $this->assert_SeoOptimizationTerminalCheckpointReconciliation_testTerminalCheckpointIsReleasedThroughTheAdapterAndRefreshed();
        },
            'SeoPlatformCode::testGoogleAliasesCanonicalize' => function () {
            $this->assert_SeoPlatformCode_testGoogleAliasesCanonicalize();
        },
            'SeoSearchQuerySyncService::testSyncAndEvolveStayOnPublishedBindingsAndHeatQueue' => function () {
            $this->assert_SeoSearchQuerySyncService_testSyncAndEvolveStayOnPublishedBindingsAndHeatQueue();
        },
            'SeoWebsiteAccountSinglePlatformBinding::testBindingServiceEnforcesSinglePlatformAndDedupe' => function () {
            $this->assert_SeoWebsiteAccountSinglePlatformBinding_testBindingServiceEnforcesSinglePlatformAndDedupe();
        },
        ];
    }

private function assert_OptimizationClockPropagation_testActivityAndControlCenterUseTheOptimizationClock(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $activity = (string) \file_get_contents($root . '/Service/SeoOptimizationActivityService.php');
    $controlCenter = (string) \file_get_contents($root . '/Service/SeoOptimizationControlCenterService.php');
    self::assertStringContainsString('?OptimizationTiming $timing = null', $activity);
    self::assertStringContainsString('$occurredAt = $this->timing->now();', $activity);
    self::assertStringNotContainsString("\\gmdate('Y-m-d H:i:s'", $activity);
    self::assertStringNotContainsString('\time()', $activity);
    self::assertStringContainsString('?OptimizationTiming $timing = null', $controlCenter);
    self::assertStringContainsString("'server_time' => \$this->iso(\$this->nowSql())", $controlCenter);
    self::assertStringContainsString('$this->timing->isFuture($next)', $controlCenter);
    self::assertStringNotContainsString("\\gmdate('Y-m-d H:i:s')", $controlCenter);
    self::assertStringNotContainsString('\time()', $controlCenter);
}

private function assert_OptimizationEvidenceAvailability_testVisitorAndSearchEvidenceUnavailableFailClosedBeforeExperimentOrPublish(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $evidence = (string) \file_get_contents($root . '/Service/OptimizationEvidenceService.php');
    $orchestrator = (string) \file_get_contents($root . '/Service/SeoOptimizationOrchestrator.php');
    self::assertStringContainsString('visitor_evidence_unavailable', $evidence);
    self::assertStringContainsString('search_evidence_unavailable', $evidence);
    self::assertStringContainsString('tryVisitorSnapshot', $evidence);
    self::assertStringContainsString('isQueryHeatEligible', $evidence);
    self::assertStringContainsString('queryHeatMetric', $evidence);
    self::assertStringContainsString('$this->finishRun($run, \'evidence_unavailable\'', $orchestrator);
    self::assertStringContainsString("'evidence_unavailable' : 'metric_evidence_failed'", $orchestrator);
    self::assertLessThan(\strpos($orchestrator, '$adapter->apply('), \strpos($orchestrator, '$this->evidenceService->evidence('));
    self::assertLessThan(\strpos($orchestrator, '$this->createExperiment('), \strpos($orchestrator, '$this->evidenceService->evidence('));
}

private function assert_PlatformVisualLogo_testMainstreamPlatformsRenderRealLogoAssetsNotLetterBlocks(): void
{
    $visual = new PlatformVisual();
    self::assertDirectoryExists($visual->logoDirectory());
    self::assertFileExists($visual->logoDirectory() . '/google.svg');
    self::assertFileExists($visual->logoDirectory() . '/bing.svg');
    self::assertFileExists($visual->logoDirectory() . '/baidu.svg');
    foreach (['google', 'bing', 'baidu', 'yandex', 'duckduckgo', 'indexnow'] as $code) {
        self::assertTrue($visual->hasLogoAsset($code), $code . ' should have logo asset');
        $html = $visual->renderIcon($code, strtoupper($code), null, 30, 'seo-platform-icon');
        self::assertStringContainsString('data-seo-platform-logo="' . $code . '"', $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringNotContainsString('data-seo-platform-logo-fallback=', $html);
        // Old letter-block used a magnifier circle decoration.
        self::assertStringNotContainsString('circle cx="20.5" cy="11.5"', $html);
    }
    $google = $visual->renderIcon('google_search_console', 'Google', null, 28);
    self::assertStringContainsString('data-seo-platform-logo="google"', $google);
    self::assertStringContainsString('#4285F4', $google);
}

private function assert_SearchPerformanceSnapshotEvidenceAvailability_testEmptySearchWindowIsExplicitlyUnavailableToTheEvidenceBoundary(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $snapshot = (string) \file_get_contents($root . '/Service/SearchPerformanceSnapshotService.php');
    $evidence = (string) \file_get_contents($root . '/Service/OptimizationEvidenceService.php');
    self::assertStringContainsString("'complete' => \$hasRows", $snapshot);
    self::assertStringContainsString("'reasons' => \$hasRows ? [] : ['evidence_unavailable']", $snapshot);
    self::assertStringContainsString('search_evidence_unavailable', $evidence);
}

private function assert_SearchPerformanceSnapshotEvidenceAvailability_testAcceptanceFixtureIsDualGatedBackendOnlyAndUsesCanonicalStatsModel(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $model = (string) \file_get_contents($root . '/Model/SeoWebsiteStats.php');
    $provider = (string) \file_get_contents($root . '/extends/module/Weline_Framework/Query/SeoAdminQueryProvider.php');
    $sync = (string) \file_get_contents($root . '/Cron/StatsSync.php');
    self::assertStringContainsString("getenv('WELINE_SEO_ACCEPTANCE_MODE') !== '1'", $model);
    self::assertStringContainsString("getenv('WELINE_SEO_ACCEPTANCE_FIXTURES') !== '1'", $model);
    self::assertStringContainsString('SEO_SEARCH_ACCEPTANCE_FIXTURE_DISABLED', $model);
    self::assertStringContainsString('seo.search_acceptance_fixture_receipt.v1', $model);
    self::assertStringContainsString('SEO_SEARCH_ACCEPTANCE_FIXTURE_REQUEST_CONFLICT', $model);
    self::assertStringContainsString("setData(self::schema_fields_STATS_DATE, \$row['date'])", $model);
    self::assertStringContainsString("'row_count' => \$rowCount", $model);
    self::assertStringContainsString("'receipt_digest' => \$receiptDigest", $model);
    self::assertStringContainsString("'frontend' => \$frontend", $provider);
    self::assertStringContainsString("], 'write', 1, false)", $provider);
    self::assertStringContainsString("'external' => false", $provider);
    self::assertStringContainsString('private readonly SeoWebsiteStats $statsModel', $provider);
    self::assertStringContainsString('ObjectManager::getInstance(SeoWebsiteStats::class)', $sync);
    self::assertStringNotContainsString('INSERT INTO', $model);
    self::assertStringNotContainsString('DELETE FROM', $model);
}

private function assert_SearchPerformanceSnapshotEvidenceAvailability_testAcceptanceFixtureContractKeepsWebsiteZeroAndExactCleanupSemantics(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $model = (string) \file_get_contents($root . '/Model/SeoWebsiteStats.php');
    self::assertStringContainsString('website_id must be a non-negative integer.', $model);
    self::assertStringContainsString("'acceptance_payload_hash' => \$payloadHash", $model);
    self::assertStringContainsString("'acceptance_row_index' => \$index", $model);
    self::assertStringContainsString("'deleted_rows' => \$deleted", $model);
    self::assertStringContainsString('if ($item === null)', $model);
    self::assertStringContainsString('$itemsToDelete[] = $item;', $model);
    self::assertStringContainsString('$item->delete();', $model);
    self::assertStringContainsString('hash_equals($expectedReceiptDigest, $receiptDigest)', $model);
    self::assertStringContainsString("(int)\$item->getId() !== \$receiptRow['id']", $model);
    self::assertStringContainsString("(\$meta['date'] ?? '') !== \$receiptRow['date']", $model);
    self::assertStringContainsString('clicks must not exceed impressions.', $model);
    self::assertStringContainsString('duplicate dated query/page row.', $model);
}

private function assert_SearchPerformanceSnapshotEvidenceAvailability_testCurrentAndPreviousSearchWindowFixtureMathIsExact(): void
{
    $aggregate = static function (array $rows): array {
        $impressions = array_sum(array_column($rows, 'impressions'));
        $clicks = array_sum(array_column($rows, 'clicks'));
        $weightedPosition = 0.0;
        foreach ($rows as $row) {
            $weightedPosition += $row['average_position'] * $row['impressions'];
        }
        return ['impressions' => $impressions, 'clicks' => $clicks, 'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0, 'average_position' => $impressions > 0 ? $weightedPosition / $impressions : 0.0, 'indexed_pages' => max(array_column($rows, 'indexed_pages')), 'error_count' => array_sum(array_column($rows, 'error_count'))];
    };
    $previous = $aggregate([['impressions' => 600, 'clicks' => 120, 'average_position' => 12.0, 'indexed_pages' => 8, 'error_count' => 2], ['impressions' => 600, 'clicks' => 120, 'average_position' => 8.0, 'indexed_pages' => 8, 'error_count' => 1]]);
    $current = $aggregate([['impressions' => 600, 'clicks' => 144, 'average_position' => 9.0, 'indexed_pages' => 8, 'error_count' => 1], ['impressions' => 600, 'clicks' => 144, 'average_position' => 7.0, 'indexed_pages' => 8, 'error_count' => 0]]);
    self::assertSame(1200, $previous['impressions']);
    self::assertSame(240, $previous['clicks']);
    self::assertEqualsWithDelta(0.2, $previous['ctr'], 1.0E-6);
    self::assertEqualsWithDelta(10.0, $previous['average_position'], 1.0E-6);
    self::assertSame(8, $previous['indexed_pages']);
    self::assertSame(3, $previous['error_count']);
    self::assertSame(1200, $current['impressions']);
    self::assertSame(288, $current['clicks']);
    self::assertEqualsWithDelta(0.24, $current['ctr'], 1.0E-6);
    self::assertEqualsWithDelta(8.0, $current['average_position'], 1.0E-6);
    self::assertSame(8, $current['indexed_pages']);
    self::assertSame(1, $current['error_count']);
    $snapshot = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/SearchPerformanceSnapshotService.php');
    foreach (['impressions', 'clicks', 'ctr', 'average_position', 'indexed_pages', 'error_count', 'daily'] as $field) {
        self::assertStringContainsString("'{$field}' =>", $snapshot);
    }
    self::assertStringContainsString('->where(SeoWebsiteStats::schema_fields_STATS_DATE', $snapshot);
    self::assertStringContainsString("->order(SeoWebsiteStats::schema_fields_STATS_DATE, 'ASC')", $snapshot);
}

private function assert_SeoOptimizationControlCenterAuthorizedSites_testAuthorizedSitesUsesPublishedDirectoryInsteadOfBackendAreaQuery(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/SeoOptimizationControlCenterService.php');
    self::assertStringContainsString('$this->websiteDirectory->listWebsites()', $source);
    self::assertStringContainsString('fallbackWebsiteIds', $source);
    self::assertStringContainsString('listWebsiteIds', $source);
    self::assertStringContainsString("'sites_only'", $source);
    self::assertStringContainsString('seo.optimization.control-center.sites.v1', $source);
    self::assertStringNotContainsString("w_query('websites', 'getWebsiteList', [], 'backend')", $source);
    $provider = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/extends/module/Weline_Framework/Query/SeoOptimizationControlQueryProvider.php');
    self::assertStringContainsString("'sites_only' => \$this->truthy", $provider);
    self::assertStringContainsString("['name' => 'sites_only', 'type' => 'bool'", $provider);
}

private function assert_SeoOptimizationControlCenterFrontend_testOptimizationConsoleUsesOfficialWebsiteSelectAndKeepEvolveSurface(): void
{
    $template = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/view/templates/backend/Optimization/index.phtml');
    self::assertStringContainsString('w:websites:website:select', $template);
    self::assertStringContainsString('seoWebsiteSelectOptionsJson', $template);
    self::assertStringContainsString('handleSeoOptWebsiteSelectChange', $template);
    self::assertStringContainsString('seo_opt_website_id', $template);
    self::assertStringNotContainsString('websitePicker', $template);
    self::assertStringNotContainsString('id="websiteSelect"', $template);
    self::assertStringNotContainsString('seo-opt__site-picker', $template);
    self::assertStringNotContainsString('function loadWebsites(', $template);
    self::assertStringNotContainsString('state.directoryApi.optimizationControlCenterSnapshot({sites_only:true})', $template);
    self::assertStringNotContainsString("resource('websites')", $template);
    self::assertStringNotContainsString('state.websitesApi.getWebsiteList({})', $template);
    self::assertStringContainsString("resource('seo_optimization_control')", $template);
    self::assertStringContainsString('searchQueryCloud', $template);
    self::assertStringContainsString('siteEventHeat', $template);
    self::assertStringContainsString('syncSearchQueryCloud', $template);
    self::assertStringContainsString('evolveFromQueryHeat', $template);
    self::assertStringContainsString('seo-opt__cloud', $template);
    self::assertStringContainsString('GSC 词云与访客事件', $template);
    self::assertStringContainsString('syncCloudButton', $template);
    self::assertStringContainsString('evolveHeatButton', $template);
    self::assertStringContainsString("item.source==='search_query'", $template);
    self::assertStringContainsString('eventHeatGscFallback', $template);
    self::assertStringContainsString('当前无访客事件，以下为 GSC 搜索词热度', $template);
    self::assertStringContainsString('eventHeatVisitorHint', $template);
    self::assertStringContainsString('真实访客 PV 需站点上线', $template);
    self::assertStringContainsString('evolveScheduleHint', $template);
    self::assertStringContainsString('evolveScheduleHintByMode', $template);
    self::assertStringContainsString('renderScheduleHint', $template);
    self::assertStringContainsString('function withTimeout(', $template);
    self::assertStringContainsString('小时调度默认跟随上方自动化模式', $template);
    self::assertStringContainsString('当前为影子模式：小时调度只分析不改稿', $template);
    self::assertStringContainsString('.seo-opt__panel-head h2{margin:4px 0 0;font-size:19px;color:var(--ink);font-weight:800', $template);
    self::assertStringContainsString('暂无访客事件或搜索词热度', $template);
}

private function assert_SeoOptimizationEvaluationLifecycle_testEvaluationRecordsPublishObserveAndTerminalActivity(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $evaluation = (string) \file_get_contents($root . '/Service/SeoOptimizationEvaluationService.php');
    $activity = (string) \file_get_contents($root . '/Service/SeoOptimizationActivityService.php');
    self::assertStringContainsString('private readonly SeoOptimizationActivityService $activityService', $evaluation);
    self::assertStringContainsString('$this->activityService->recordLifecycle(', $evaluation);
    self::assertStringContainsString('public function recordLifecycle(', $activity);
}

private function assert_SeoOptimizationEvaluationLifecycle_testEvaluatingCandidateReconcilesItsPublicationReceiptBeforeObservation(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $evaluation = (string) \file_get_contents($root . '/Service/SeoOptimizationEvaluationService.php');
    self::assertStringContainsString('$reconciledPublication = $this->reconcileEvaluatingCandidatePublication($experiment);', $evaluation);
    self::assertStringContainsString('private function reconcileEvaluatingCandidatePublication(', $evaluation);
    self::assertStringContainsString("return \$this->markPublicationPending(\n                \$experiment,\n                \$run,\n                'candidate',", $evaluation);
}

private function assert_SeoOptimizationEvaluationLifecycle_testUnrelatedPlanRevisionAdvanceKeepsExactCandidateEligible(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $evaluation = (string) \file_get_contents($root . '/Service/SeoOptimizationEvaluationService.php');
    self::assertStringContainsString('$currentRevision = (int)($current[\'revision\'] ?? -1);', $evaluation);
    self::assertSame(2, \substr_count($evaluation, '|| $currentRevision < $candidateRevision'));
    self::assertStringContainsString('$metricTarget[\'revision\'] = $currentRevision;', $evaluation);
    self::assertSame(3, \substr_count($evaluation, "'expected_revision' => \$currentRevision"));
    self::assertStringNotContainsString('$candidateRevision !== (int)($current[\'revision\'] ?? -1)', $evaluation);
}

private function assert_SeoOptimizationOrchestratorHeatRanking_testSelectTargetsPrefersQueryHeatMatchesWhenCloudExists(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/SeoOptimizationOrchestrator.php');
    self::assertStringContainsString('rankTargetsByQueryHeat', $source);
    self::assertStringContainsString('$this->queryHeat->cloud($websiteId, 80)', $source);
    self::assertStringContainsString('current_values', $source);
    self::assertStringContainsString('apply_intent', $source);
    self::assertStringContainsString('effectiveMode', $source);
    self::assertStringContainsString('isQueryHeatEligible', $source);
    self::assertStringContainsString('min_heat_confidence', $source);
}

private function assert_SeoOptimizationQueueSchedulerAdmission_testOptimizationAdmissionsLeaveExecutionToTheSystemScheduler(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/SeoOptimizationQueueService.php');
    self::assertStringContainsString("'dispatch' => false", $source);
}

private function assert_SeoOptimizationQueueSchedulerAdmission_testAutomaticScheduleOnlyAdmitsExplicitPersistedPolicies(): void
{
    $method = new \ReflectionMethod(SeoOptimizationSchedule::class, 'websiteIdsFromPolicies');
    $ids = $method->invoke(null, [[SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '10'], [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => 0], [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '2'], [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => 10], [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '-1'], [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '01'], [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => ''], ['unrelated' => 99]]);
    self::assertSame([0, 2, 10], $ids);
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Cron/SeoOptimizationSchedule.php');
    self::assertStringContainsString('$this->policyService->persistedPolicies()', $source);
    self::assertStringContainsString('listWebsiteIds', $source);
    self::assertStringContainsString("'trigger_source' => 'scheduler'", $source);
    self::assertStringNotContainsString("w_query('websites', 'getWebsiteList'", $source);
}

private function assert_SeoOptimizationQueueSchedulerAdmission_testAcceptanceExactTargetModeDisablesOnlyAutomaticSiteWideAnalysis(): void
{
    $originalAcceptance = \getenv('WELINE_SEO_ACCEPTANCE_MODE');
    $originalExactTarget = \getenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY');
    $method = new \ReflectionMethod(SeoOptimizationSchedule::class, 'scheduledAnalysisEnabled');
    try {
        \putenv('WELINE_SEO_ACCEPTANCE_MODE');
        \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY');
        self::assertTrue($method->invoke(null));
        \putenv('WELINE_SEO_ACCEPTANCE_MODE=1');
        \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY');
        self::assertTrue($method->invoke(null));
        \putenv('WELINE_SEO_ACCEPTANCE_MODE');
        \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY=1');
        self::assertTrue($method->invoke(null));
        \putenv('WELINE_SEO_ACCEPTANCE_MODE=1');
        \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY=1');
        self::assertFalse($method->invoke(null));
    } finally {
        $originalAcceptance === false ? \putenv('WELINE_SEO_ACCEPTANCE_MODE') : \putenv('WELINE_SEO_ACCEPTANCE_MODE=' . $originalAcceptance);
        $originalExactTarget === false ? \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY') : \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY=' . $originalExactTarget);
    }
}

private function assert_SeoOptimizationTerminalCheckpointReconciliation_testTerminalCheckpointIsReleasedThroughTheAdapterAndRefreshed(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/SeoOptimizationOrchestrator.php');
    self::assertStringContainsString('$snapshot = $this->reconcileTerminalCheckpoint($adapter, $websiteId, $snapshot);', $source);
    self::assertStringContainsString('private function reconcileTerminalCheckpoint(', $source);
    self::assertStringContainsString('SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION,', $source);
    self::assertStringContainsString('$release = $adapter->finalize([', $source);
    self::assertStringContainsString("if (!empty(\$release['success']) || !empty(\$release['checkpoint_released'])) {", $source);
    self::assertStringContainsString('return $adapter->snapshot($websiteId, [', $source);
    $ownerAvailable = \substr($source, (int) \strpos($source, 'private function ownerAvailable('), (int) \strpos($source, 'private function reconcileTerminalCheckpoint(') - (int) \strpos($source, 'private function ownerAvailable('));
    self::assertStringNotContainsString('SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION', $ownerAvailable, 'manual_intervention is terminal; only a still-present owner checkpoint may keep it busy.');
}

private function assert_SeoPlatformCode_testGoogleAliasesCanonicalize(): void
{
    self::assertSame('google', SeoPlatformCode::canonicalize('google'));
    self::assertSame('google', SeoPlatformCode::canonicalize('google_search_console'));
    self::assertSame('google', SeoPlatformCode::canonicalize('google_indexing_api'));
    self::assertSame('google', SeoPlatformCode::canonicalize('GSC'));
    self::assertTrue(SeoPlatformCode::isGoogle('google_search_console'));
    self::assertFalse(SeoPlatformCode::isGoogle('bing'));
    self::assertSame('bing', SeoPlatformCode::canonicalize('bing'));
}

private function assert_SeoSearchQuerySyncService_testSyncAndEvolveStayOnPublishedBindingsAndHeatQueue(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/SeoSearchQuerySyncService.php');
    self::assertStringContainsString('function syncWebsite', $source);
    self::assertStringContainsString('function evolveFromQueryHeat', $source);
    self::assertStringContainsString('rankTargetsByQueryHeat', $source);
    self::assertStringContainsString("enqueueAnalyze(", $source);
    self::assertStringContainsString("'pagebuilder_ai_site'", $source);
    self::assertStringContainsString("'trigger_source' => 'query_heat'", $source);
    self::assertStringContainsString("'apply_intent' => 'auto_draft'", $source);
    self::assertStringContainsString('$ranked[0]', $source);
    self::assertStringNotContainsString('GuoLaiRen\PageBuilder', $source);
}

private function assert_SeoWebsiteAccountSinglePlatformBinding_testBindingServiceEnforcesSinglePlatformAndDedupe(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $bindingSrc = (string) file_get_contents($root . '/Service/SeoWebsiteAccountBindingService.php');
    $modelSrc = (string) file_get_contents($root . '/Model/SeoWebsiteAccount.php');
    self::assertStringContainsString('enforceSinglePlatformBinding', $bindingSrc);
    self::assertStringContainsString('dedupeByCanonicalPlatform', $bindingSrc);
    self::assertStringContainsString('enforceSinglePlatformBinding', $modelSrc);
    self::assertStringContainsString('return $this->dedupeByCanonicalPlatform($accounts);', $bindingSrc);
}
}
