<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SeoOptimizationEvaluationLifecycleContractTest extends TestCase
{
    public function testEvaluationRecordsPublishObserveAndTerminalActivity(): void
    {
        $root = \dirname(__DIR__, 3);
        $evaluation = (string)\file_get_contents($root . '/Service/SeoOptimizationEvaluationService.php');
        $activity = (string)\file_get_contents($root . '/Service/SeoOptimizationActivityService.php');

        self::assertStringContainsString('private readonly SeoOptimizationActivityService $activityService', $evaluation);
        self::assertStringContainsString('$this->activityService->recordLifecycle(', $evaluation);
        self::assertStringContainsString('public function recordLifecycle(', $activity);
    }

    public function testEvaluatingCandidateReconcilesItsPublicationReceiptBeforeObservation(): void
    {
        $root = \dirname(__DIR__, 3);
        $evaluation = (string)\file_get_contents($root . '/Service/SeoOptimizationEvaluationService.php');

        self::assertStringContainsString('$reconciledPublication = $this->reconcileEvaluatingCandidatePublication($experiment);', $evaluation);
        self::assertStringContainsString('private function reconcileEvaluatingCandidatePublication(', $evaluation);
        self::assertStringContainsString("return \$this->markPublicationPending(\n                \$experiment,\n                \$run,\n                'candidate',", $evaluation);
    }

    public function testUnrelatedPlanRevisionAdvanceKeepsExactCandidateEligible(): void
    {
        $root = \dirname(__DIR__, 3);
        $evaluation = (string)\file_get_contents($root . '/Service/SeoOptimizationEvaluationService.php');

        self::assertStringContainsString(
            '$currentRevision = (int)($current[\'revision\'] ?? -1);',
            $evaluation
        );
        self::assertSame(2, \substr_count($evaluation, '|| $currentRevision < $candidateRevision'));
        self::assertStringContainsString('$metricTarget[\'revision\'] = $currentRevision;', $evaluation);
        self::assertSame(3, \substr_count($evaluation, "'expected_revision' => \$currentRevision"));
        self::assertStringNotContainsString(
            '$candidateRevision !== (int)($current[\'revision\'] ?? -1)',
            $evaluation
        );
    }
}
