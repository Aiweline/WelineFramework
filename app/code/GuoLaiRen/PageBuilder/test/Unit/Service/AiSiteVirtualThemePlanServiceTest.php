<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
use PHPUnit\Framework\TestCase;

final class AiSiteVirtualThemePlanServiceTest extends TestCase
{
    public function testBuildTaskPlanArtifactsProducesStructuredPlan(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'shared:footer', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Footer', 'sort_order' => 20],
                ['task_key' => 'page:home_page:hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'sort_order' => 100],
            ],
        ];
        $scope = [
            'site_title' => 'Task Plan Test',
            'workspace_track' => 'virtual_theme',
            'execution_blueprint' => [
                'page_types' => ['home_page'],
                'pages' => [
                    'home_page' => [
                        'blocks' => [
                            ['block_key' => 'hero', 'goal' => 'Explain value', 'field_plan' => [['field' => 'title']], 'result_ref' => []],
                        ],
                    ],
                ],
            ],
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'plan_structured' => [
                'site_strategy' => ['site_display_name' => 'Task Plan Test', 'summary' => 'Summary'],
                'palette' => ['name' => 'Ocean Slate'],
                'theme_style' => ['name' => 'Plan-Driven Hybrid', 'responsive_rule' => 'Single column first'],
                'seo_strategy' => ['core_intent' => 'intent'],
                'navigation_plan' => ['header_items' => []],
                'footer_plan' => ['featured' => []],
            ],
        ];

        $artifacts = $service->buildTaskPlanArtifacts($scope, $buildBlueprint);

        self::assertIsArray($artifacts['structured'] ?? null);
        self::assertIsArray($artifacts['virtual_theme_plan'] ?? null);
        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertSame('phase-one-signature', (string)($artifacts['structured']['plan_signature'] ?? ''));
        self::assertIsArray($artifacts['structured']['execution_order'] ?? null);
    }

    public function testRefineDraftTaskPlanAddsChangeScopeReport(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $scope = [
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'execution_blueprint' => ['page_types' => ['home_page'], 'pages' => []],
            'plan_structured' => [],
        ];
        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
            ],
        ];

        $result = $service->refineDraftTaskPlan($scope, $buildBlueprint, [], [
            'instruction' => '只调整首页英雄区块',
            'target_scope' => 'page:home_page:hero',
            'round' => 2,
        ]);

        self::assertNotSame('', (string)($result['markdown'] ?? ''));
        self::assertIsArray($result['change_scope_report'] ?? null);
        self::assertSame('page:home_page:hero', (string)($result['change_scope_report']['target_scope'] ?? ''));
        self::assertSame(2, (int)($result['change_scope_report']['round'] ?? 0));
        self::assertIsArray($result['virtual_theme_plan']['change_scope_report'] ?? null);
    }

    public function testRebuildDraftTaskPlanAddsRebuildSummary(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $scope = [
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'execution_blueprint' => ['page_types' => ['home_page'], 'pages' => []],
            'plan_structured' => [],
        ];
        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'page:home_page:hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'sort_order' => 100],
            ],
        ];

        $result = $service->rebuildDraftTaskPlan($scope, $buildBlueprint, [
            'instruction' => '按新的品牌方向重建整个任务方案',
            'round' => 3,
        ]);

        self::assertNotSame('', (string)($result['markdown'] ?? ''));
        self::assertIsArray($result['rebuild_summary'] ?? null);
        self::assertSame(2, (int)($result['rebuild_summary']['task_count'] ?? 0));
        self::assertSame(3, (int)($result['rebuild_summary']['round'] ?? 0));
        self::assertIsArray($result['virtual_theme_plan']['rebuild_summary'] ?? null);
    }
}
