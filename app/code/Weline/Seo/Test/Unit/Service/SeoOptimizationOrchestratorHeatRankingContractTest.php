<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SeoOptimizationOrchestratorHeatRankingContractTest extends TestCase
{
    public function testSelectTargetsPrefersQueryHeatMatchesWhenCloudExists(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SeoOptimizationOrchestrator.php'
        );

        self::assertStringContainsString('rankTargetsByQueryHeat', $source);
        self::assertStringContainsString('$this->queryHeat->cloud($websiteId, 80)', $source);
        self::assertStringContainsString('current_values', $source);
        self::assertStringContainsString('apply_intent', $source);
        self::assertStringContainsString('effectiveMode', $source);
        self::assertStringContainsString('isQueryHeatEligible', $source);
        self::assertStringContainsString('min_heat_confidence', $source);
    }
}
