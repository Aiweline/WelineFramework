<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;

final class AiSiteVirtualThemePlanServiceTest extends TestCase
{
    public function testBuildTaskPlanArtifactsProducesStructuredPlan(): void
    {
        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub($this->buildTaskPlanResponse())
        );
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
                        'page_goal' => 'Explain value',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Explain value',
                                'field_plan' => [['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer']],
                                'result_ref' => [],
                            ],
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
        self::assertNotEmpty($artifacts['structured']['page_tasks']['home_page'] ?? []);
    }

    public function testBuildTaskPlanArtifactsFallsBackDeterministicallyWhenFakeModeIsEnabled(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generate');
        $service = new AiSiteVirtualThemePlanService($aiService);

        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'page:home_page:hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'sort_order' => 100],
            ],
        ];
        $scope = [
            'fake_mode' => 1,
            'site_title' => 'Fallback Task Plan Test',
            'workspace_track' => 'virtual_theme',
            'execution_blueprint' => [
                'page_types' => ['home_page'],
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain value',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Open with a clear value proposition.',
                                'field_plan' => [['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer']],
                                'result_ref' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'plan_structured' => [
                'site_strategy' => ['site_display_name' => 'Fallback Task Plan Test', 'summary' => 'Summary'],
                'palette' => ['name' => 'Ocean Slate'],
                'theme_style' => ['name' => 'Plan-Driven Hybrid', 'responsive_rule' => 'Single column first'],
                'seo_strategy' => ['core_intent' => 'intent'],
                'navigation_plan' => ['header_items' => []],
                'footer_plan' => ['featured' => []],
            ],
        ];

        $artifacts = $service->buildTaskPlanArtifacts($scope, $buildBlueprint);

        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['virtual_theme_plan']['page_tasks']['home_page'] ?? null);
        $pageTask = $artifacts['virtual_theme_plan']['page_tasks']['home_page'][0] ?? [];
        self::assertSame('Open with a clear value proposition.', (string)($pageTask['task_script']['story_goal'] ?? ''));
        self::assertNotEmpty($pageTask['task_script']['field_content_requirements'] ?? []);
        self::assertNotEmpty($pageTask['implementation_contract']['acceptance'] ?? []);
    }

    public function testRefineDraftTaskPlanAddsChangeScopeReport(): void
    {
        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub($this->buildTaskPlanResponse())
        );
        $scope = [
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'execution_blueprint' => [
                'page_types' => ['home_page'],
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain value',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Explain value',
                                'field_plan' => [['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer']],
                                'result_ref' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'plan_structured' => [],
        ];
        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'page:home_page:hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'sort_order' => 100],
            ],
        ];

        $result = $service->refineDraftTaskPlan($scope, $buildBlueprint, [], [
            'instruction' => 'Only refine the home hero section.',
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
        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub($this->buildTaskPlanResponse())
        );
        $scope = [
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'execution_blueprint' => [
                'page_types' => ['home_page'],
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain value',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Explain value',
                                'field_plan' => [['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer']],
                                'result_ref' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'plan_structured' => [],
        ];
        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'page:home_page:hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'sort_order' => 100],
            ],
        ];

        $result = $service->rebuildDraftTaskPlan($scope, $buildBlueprint, [
            'instruction' => 'Rebuild the task plan around a new brand direction.',
            'round' => 3,
        ]);

        self::assertNotSame('', (string)($result['markdown'] ?? ''));
        self::assertIsArray($result['rebuild_summary'] ?? null);
        self::assertSame(2, (int)($result['rebuild_summary']['task_count'] ?? 0));
        self::assertSame(3, (int)($result['rebuild_summary']['round'] ?? 0));
        self::assertIsArray($result['virtual_theme_plan']['rebuild_summary'] ?? null);
    }

    private function createAiServiceStub(string $response): AiService
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->method('generate')->willReturn($response);
        return $aiService;
    }

    private function buildTaskPlanResponse(): string
    {
        return \json_encode([
            'markdown' => '# Task Plan',
            'virtual_theme_plan' => [
                'task_script_brief' => [
                    'goal' => 'Generate task-ready implementation scripts.',
                    'rule' => 'Each task must stay self-contained.',
                ],
                'virtual_theme_strategy' => [
                    'workspace_track' => 'virtual_theme',
                    'site_summary' => 'Summary',
                    'site_display_name' => 'Task Plan Test',
                ],
                'shared_tasks' => [
                    [
                        'task_key' => 'shared:header',
                        'group_key' => 'shared',
                        'page_type' => '',
                        'label' => 'Header',
                        'sort_order' => 10,
                        'task_script' => [
                            'scene' => 'shared:header',
                            'story_goal' => 'Build a reusable header.',
                            'content_fill_rule' => 'Keep navigation concise.',
                            'stage3_directive' => 'Implement the header component.',
                            'field_content_requirements' => [
                                ['field' => 'title', 'sample' => 'Site Title', 'reason' => 'Brand identification'],
                            ],
                        ],
                    ],
                ],
                'page_tasks' => [
                    'home_page' => [
                        [
                            'task_key' => 'page:home_page:hero',
                            'group_key' => 'home_page',
                            'page_type' => 'home_page',
                            'label' => 'Hero',
                            'sort_order' => 100,
                            'plan_context' => [
                                'page_goal' => 'Explain value',
                                'block_goal' => 'Open with a clear value proposition.',
                            ],
                            'implementation_contract' => [
                                'acceptance' => ['Hero must render value proposition and CTA.'],
                            ],
                            'task_script' => [
                                'scene' => 'page:home_page/block:hero',
                                'story_goal' => 'Make the hero conversion-ready.',
                                'content_fill_rule' => 'Use short headline and one CTA.',
                                'stage3_directive' => 'Generate the hero component.',
                                'field_content_requirements' => [
                                    ['field' => 'title', 'sample' => 'Grow faster with our service', 'reason' => 'Lead with value'],
                                ],
                            ],
                        ],
                    ],
                ],
                'meta_field_matrix' => [
                    'home_page' => [
                        'hero' => [
                            'goal' => 'Open with a clear value proposition.',
                            'field_plan' => [
                                ['field' => 'title', 'sample' => 'Grow faster with our service', 'reason' => 'Lead with value'],
                            ],
                            'result_ref' => [],
                        ],
                    ],
                ],
                'style_tokens' => [
                    'palette' => ['name' => 'Ocean Slate'],
                    'theme_style' => ['name' => 'Plan-Driven Hybrid'],
                ],
                'content_rules' => [
                    'seo_strategy' => ['core_intent' => 'intent'],
                    'navigation_plan' => ['header_items' => []],
                    'footer_plan' => ['featured' => []],
                ],
                'responsive_rules' => [
                    'global_rule' => 'Single column first',
                    'page_types' => ['home_page'],
                ],
                'execution_order' => [
                    ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'sort_order' => 10, 'dependencies' => []],
                    ['task_key' => 'page:home_page:hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'sort_order' => 100, 'dependencies' => ['shared:header']],
                ],
                'risk_notes' => [
                    'Finish shared components before page tasks.',
                ],
            ],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
