<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskScheduler;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

final class AiSitePlanJsonTaskSchedulerTest extends TestCase
{
    public function testBuildConfirmationScopePatchRejectsMissingSelectedPageTypes(): void
    {
        $planJsonTaskService = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $scheduler = new AiSitePlanJsonTaskScheduler($planJsonTaskService);

        $patch = $scheduler->buildConfirmationScopePatch([
            'page_types' => ['home_page', 'about_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'page_type' => 'home_page',
                        'hero' => ['block_key' => 'hero', 'status' => 0],
                    ],
                ],
                'site_strategy' => ['site_display_name' => 'Demo'],
                'theme_style' => [],
                'palette' => [],
                'theme_design' => ['sections' => []],
                'navigation_plan' => [],
                'footer_plan' => [],
                'seo_strategy' => [],
            ],
            'website_profile' => ['site_title' => 'Demo'],
        ], ['site_title' => 'Demo'], 'virtual_theme');

        self::assertSame(0, (int)($patch['plan_json']['confirmed'] ?? 1));
        self::assertFalse((bool)($patch['plan_json_pages_validation']['valid'] ?? true));
    }

    public function testCollectMissingSelectedPlanPageTypesDetectsGap(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $missing = $service->collectMissingSelectedPlanPageTypes([
            'page_types' => ['home_page', 'about_page', 'contact_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => ['page_type' => 'home_page'],
                    'about_page' => ['page_type' => 'about_page'],
                ],
            ],
        ]);

        self::assertSame(['contact_page'], $missing);
    }

}
