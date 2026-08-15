<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class OptimizationEvidenceAvailabilityContractTest extends TestCase
{
    public function testVisitorAndSearchEvidenceUnavailableFailClosedBeforeExperimentOrPublish(): void
    {
        $root = \dirname(__DIR__, 3);
        $evidence = (string)\file_get_contents($root . '/Service/OptimizationEvidenceService.php');
        $orchestrator = (string)\file_get_contents($root . '/Service/SeoOptimizationOrchestrator.php');

        self::assertStringContainsString('visitor_evidence_unavailable', $evidence);
        self::assertStringContainsString('search_evidence_unavailable', $evidence);
        self::assertStringContainsString('tryVisitorSnapshot', $evidence);
        self::assertStringContainsString('isQueryHeatEligible', $evidence);
        self::assertStringContainsString('queryHeatMetric', $evidence);
        self::assertStringContainsString('$this->finishRun($run, \'evidence_unavailable\'', $orchestrator);
        self::assertStringContainsString("'evidence_unavailable' : 'metric_evidence_failed'", $orchestrator);
        self::assertLessThan(
            \strpos($orchestrator, '$adapter->apply('),
            \strpos($orchestrator, '$this->evidenceService->evidence('),
        );
        self::assertLessThan(
            \strpos($orchestrator, '$this->createExperiment('),
            \strpos($orchestrator, '$this->evidenceService->evidence('),
        );
    }
}
