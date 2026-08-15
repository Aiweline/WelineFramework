<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Model\SeoWebsiteStats;
use Weline\Seo\Service\OptimizationEvidenceService;
use Weline\Seo\Service\SearchPerformanceSnapshotService;

final class OptimizationEvidenceServiceTest extends TestCase
{
    private function service(): OptimizationEvidenceService
    {
        return new OptimizationEvidenceService(
            new SearchPerformanceSnapshotService($this->createMock(SeoWebsiteStats::class))
        );
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'min_page_views' => 500,
            'min_conversions' => 30,
            'min_search_impressions' => 1000,
        ];
    }

    /** @return array<string,mixed> */
    private function blockEvidence(bool $complete, bool $eligible, int $pageViews = 500, int $targetEvents = 30): array
    {
        return [
            'visitor' => [
                'summary' => [
                    'page_views' => $pageViews,
                    'target_events' => $targetEvents,
                ],
                'data_quality' => [
                    // This is the fixed public Visitor snapshot contract.
                    'complete' => $complete,
                    'eligible' => $eligible,
                ],
            ],
        ];
    }

    public function testBlockEligibilityConsumesCanonicalVisitorEligibleField(): void
    {
        $target = [
            'target_type' => 'block',
            'block_key' => 'hero',
        ];

        self::assertTrue($this->service()->sampleEligible(
            $target,
            $this->policy(),
            $this->blockEvidence(true, true),
        ));
        self::assertFalse($this->service()->sampleEligible(
            $target,
            $this->policy(),
            $this->blockEvidence(true, false),
        ));
        self::assertFalse($this->service()->sampleEligible(
            $target,
            $this->policy(),
            $this->blockEvidence(false, true),
        ));
    }

    public function testBlockEligibilityStillEnforcesPolicyThresholds(): void
    {
        $target = [
            'target_type' => 'block',
            'block_key' => 'hero',
        ];

        self::assertFalse($this->service()->sampleEligible(
            $target,
            $this->policy(),
            $this->blockEvidence(true, true, 499, 30),
        ));
        self::assertFalse($this->service()->sampleEligible(
            $target,
            $this->policy(),
            $this->blockEvidence(true, true, 500, 29),
        ));
    }

    public function testPageEligibilityContinuesToUseSearchImpressions(): void
    {
        $target = [
            'target_type' => 'page',
            'block_key' => '',
        ];

        self::assertTrue($this->service()->sampleEligible(
            $target,
            $this->policy(),
            ['search' => ['current' => ['impressions' => 1000]]],
        ));
        self::assertFalse($this->service()->sampleEligible(
            $target,
            $this->policy(),
            ['search' => ['current' => ['impressions' => 999]]],
        ));
    }

    public function testBlockEligibilityAcceptsMatchingQueryHeatWithoutVisitorSample(): void
    {
        $target = [
            'target_type' => 'block',
            'block_key' => 'hero',
        ];
        $evidence = $this->blockEvidence(false, false, 0, 0);
        $evidence['search_queries'] = [
            'top' => [
                ['query' => 'teen patti', 'heat' => 88.2, 'impressions' => 900, 'clicks' => 40],
            ],
            'matching_owner' => [
                ['query' => 'teen patti', 'heat' => 88.2, 'impressions' => 900, 'clicks' => 40],
            ],
        ];

        self::assertTrue($this->service()->sampleEligible($target, $this->policy(), $evidence));
        self::assertTrue($this->service()->isQueryHeatEligible($target, $this->policy(), $evidence));
        $evidence['search_queries']['matching_owner'] = [
            ['query' => 'teen patti', 'heat' => 4.0, 'impressions' => 10, 'clicks' => 0],
        ];
        self::assertFalse($this->service()->sampleEligible($target, $this->policy(), $evidence));
    }
}
