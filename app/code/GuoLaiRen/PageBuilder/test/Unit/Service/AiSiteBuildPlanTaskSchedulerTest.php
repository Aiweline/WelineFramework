<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanTaskScheduler;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPlanTaskSchedulerTest extends TestCase
{
    public function testBuildConfirmationScopePatchRejectsMissingSelectedPageTypes(): void
    {
        $buildTaskService = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scheduler = new AiSiteBuildPlanTaskScheduler(new AiSiteBuildPlanService(), $buildTaskService);

        $patch = $scheduler->buildConfirmationScopePatch([
            'page_types' => ['home_page', 'about_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => ['page_type' => 'home_page', 'blocks' => []],
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

        self::assertSame(0, (int)($patch['build_plan_confirmed'] ?? 0));
        self::assertFalse((bool)($patch['build_plan_v2_validation']['valid'] ?? true));
    }

    public function testCollectMissingSelectedPlanPageTypesDetectsGap(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
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

    public function testCollectMissingSelectedPlanPageTypesUsesPersistedPagePlanSources(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $missing = $service->collectMissingSelectedPlanPageTypes([
            'page_types' => ['home_page', 'about_page', 'contact_page'],
            'plan_json' => [
                'page_plans' => [
                    'home_page' => ['page_type' => 'home_page'],
                ],
            ],
            'plan_workbench' => [
                'stage1' => [
                    'page_plans' => [
                        'about_page' => ['page_type' => 'about_page'],
                    ],
                ],
                'confirmed' => [
                    'structured_plan' => [
                        'pages' => [
                            'contact_page' => ['page_type' => 'contact_page'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $missing);
    }

    public function testCollectMissingSelectedPlanPageTypesUsesConfirmedPlanBookStructuredSources(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $missing = $service->collectMissingSelectedPlanPageTypes([
            'page_types' => ['home_page', 'about_page', 'contact_page'],
            'plan_workbench' => [
                'confirmed' => [
                    'plan_book' => [
                        'structured' => [
                            'pages' => [
                                'home_page' => ['page_type' => 'home_page'],
                                'about_page' => ['page_type' => 'about_page'],
                            ],
                            'page_plans' => [
                                'contact_page' => ['blocks' => [['block_key' => 'form']]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $missing);
    }
}
