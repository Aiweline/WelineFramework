<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskScheduler;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonStateService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AiSitePlanJsonBuildStateSourceTest extends TestCase
{
    public function testPlanJsonBlocksDrivePendingSelectionAndStateWrites(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope($this->PlanJsonJsonScope([
            'hero' => ['status' => 0, 'title' => 'Hero'],
            'proof' => ['status' => 1, 'title' => 'Proof', 'html' => '<section>Proof</section>'],
            'cta' => ['status' => -1, 'title' => 'CTA', 'error' => 'Previous failure'],
        ]), [], 'html_blocks');

        self::assertSame(
            ['page:home_page:content/home-page-hero', 'page:home_page:content/home-page-cta'],
            \array_column($service->listPendingTasks($scope), 'task_key')
        );

        $scope = $service->markTaskRunning($scope, 'page:home_page:content/home-page-hero');
        self::assertSame(2, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);

        $scope = $service->markTaskDone($scope, 'page:home_page:content/home-page-hero', [
            'page_type' => 'home_page',
            'section_code' => 'content/home-page-hero',
            'section_block' => [
                'html' => '<section>Hero</section>',
                'config' => ['content.title' => 'Hero'],
                'field_schema' => ['content.title' => ['type' => 'text']],
            ],
        ]);

        self::assertSame(1, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);
        self::assertSame('<section>Hero</section>', $scope['plan_json']['pages']['home_page']['hero']['html'] ?? null);
        self::assertSame(['content.title' => 'Hero'], $scope['plan_json']['pages']['home_page']['hero']['fields'] ?? null);
        self::assertSame(-1, $scope['plan_json']['pages']['home_page']['status'] ?? null);

        $scope = $service->markTaskDone($scope, 'page:home_page:content/home-page-cta', [
            'page_type' => 'home_page',
            'section_code' => 'content/home-page-cta',
            'section_block' => ['html' => '<section>CTA</section>'],
        ]);
        self::assertSame(1, $scope['plan_json']['pages']['home_page']['status'] ?? null);
    }

    public function testPlanJsonPagesAreTheOnlySelectedPageCoverageSource(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        self::assertSame(['home_page', 'about_page'], $service->collectMissingSelectedPlanPageTypes([
            'page_types' => ['home_page', 'about_page'],
            'plan_json' => ['pages' => []],
        ]));

        self::assertSame(['about_page'], $service->collectMissingSelectedPlanPageTypes([
            'page_types' => ['home_page', 'about_page'],
            'plan_json' => [
                'pages' => ['home_page' => ['hero' => ['status' => 0]]],
            ],
        ]));
    }

    public function testBuildConfirmationDoesNotCreatePlanJson(): void
    {
        $taskService = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $scheduler = new AiSitePlanJsonTaskScheduler($taskService);

        $patch = $scheduler->buildConfirmationScopePatch(
            $this->PlanJsonJsonScope(['hero' => ['status' => 0]]),
            [],
            'html_blocks'
        );

        self::assertSame(1, (int)($patch['plan_json']['confirmed'] ?? 0));
        self::assertArrayHasKey('plan_json', $patch);
        self::assertTrue((bool)($patch['plan_json_pages_validation']['valid'] ?? false));
    }

    public function testStageOnePlanJsonPersistsDynamicBlocks(): void
    {
        $service = new AiSitePlanJsonGenerationService(new AiSitePageBlueprintService());
        $method = new ReflectionMethod($service, 'PlanJsonJson');
        $method->setAccessible(true);

        $planJson = $method->invoke($service, [
            'i18n' => ['locale' => 'en_US'],
            'site_strategy' => ['site_display_name' => 'Weline Demo'],
            'page_types' => ['home_page'],
            'pages' => [
                'home_page' => [
                    'page_goal' => 'Explain the offer.',
                    'theme_alignment_summary' => 'Use a calm product story.',
                    'page_design_plan' => ['layout' => 'single_column'],
                    'hero' => [
                        'block_key' => 'hero',
                        'section_code' => 'content/home-page-hero',
                        'sort_order' => 10,
                        'content' => 'Hero explains the primary offer.',
                        'field_plan' => ['headline' => ['sample' => 'Built for teams']],
                    ],
                ],
            ],
        ]);
        self::assertIsArray($planJson);

        $normalized = (new AiSitePlanJsonStateService(123))->normalizePlanJson($planJson);

        self::assertArrayHasKey('home_page', $normalized['pages'] ?? []);
        self::assertArrayHasKey('hero', $normalized['pages']['home_page'] ?? []);
        self::assertSame(0, (int)($normalized['pages']['home_page']['hero']['status'] ?? 99));
        self::assertSame(
            ['headline' => ['sample' => 'Built for teams']],
            $normalized['pages']['home_page']['hero']['fields'] ?? null
        );
        self::assertArrayNotHasKey('blocks', $normalized['pages']['home_page']);
    }

    /**
     * @param array<string, array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function PlanJsonJsonScope(array $blocks): array
    {
        return [
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the offer clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'confirmed' => 1,
                'content_locale' => 'en_US',
                'pages' => [
                    'home_page' => \array_replace([
                        'page_type' => 'home_page',
                        'page_goal' => 'Explain the offer clearly.',
                    ], $blocks),
                ],
            ],
        ];
    }
}
