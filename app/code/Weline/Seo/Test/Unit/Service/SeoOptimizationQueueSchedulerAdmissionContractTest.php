<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Cron\SeoOptimizationSchedule;
use Weline\Seo\Model\SeoOptimizationPolicy;

final class SeoOptimizationQueueSchedulerAdmissionContractTest extends TestCase
{
    public function testOptimizationAdmissionsLeaveExecutionToTheSystemScheduler(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SeoOptimizationQueueService.php'
        );

        self::assertStringContainsString("'dispatch' => false", $source);
    }

    public function testAutomaticScheduleOnlyAdmitsExplicitPersistedPolicies(): void
    {
        $method = new \ReflectionMethod(SeoOptimizationSchedule::class, 'websiteIdsFromPolicies');
        $ids = $method->invoke(null, [
            [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '10'],
            [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => 0],
            [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '2'],
            [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => 10],
            [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '-1'],
            [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => '01'],
            [SeoOptimizationPolicy::schema_fields_WEBSITE_ID => ''],
            ['unrelated' => 99],
        ]);

        self::assertSame([0, 2, 10], $ids);

        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Cron/SeoOptimizationSchedule.php'
        );
        self::assertStringContainsString('$this->policyService->persistedPolicies()', $source);
        self::assertStringNotContainsString("w_query('websites', 'getWebsiteList'", $source);
    }

    public function testAcceptanceExactTargetModeDisablesOnlyAutomaticSiteWideAnalysis(): void
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
            $originalAcceptance === false
                ? \putenv('WELINE_SEO_ACCEPTANCE_MODE')
                : \putenv('WELINE_SEO_ACCEPTANCE_MODE=' . $originalAcceptance);
            $originalExactTarget === false
                ? \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY')
                : \putenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY=' . $originalExactTarget);
        }
    }
}
