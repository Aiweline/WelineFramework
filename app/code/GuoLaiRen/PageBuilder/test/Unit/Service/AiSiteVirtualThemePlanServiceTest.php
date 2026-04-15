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
                ['task_key' => 'page:home_page:content/home-page-hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'section_code' => 'content/home-page-hero', 'sort_order' => 100],
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
                                'section_code' => 'content/home-page-hero',
                                'goal' => 'Explain value',
                                'why' => 'Hero should translate the main value into first-screen conversion intent.',
                                'content_brief' => ['goal' => 'Lead with value', 'cta_direction' => 'Drive to the main CTA first.'],
                                'execution_script' => ['feature_points' => ['Primary headline', 'Primary CTA'], 'core_copy' => 'Hero core copy'],
                                'seo_brief' => ['keywords' => ['india games'], 'anchors' => ['#hero'], 'internal_links' => ['/about']],
                                'field_plan' => [['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer']],
                                'result_ref' => [],
                            ],
                        ],
                    ],
                ],
                'shared_components' => [
                    'header' => [
                        'goal' => 'Build a reusable header with primary navigation.',
                        'payload' => ['header_items' => [['label' => 'Home', 'href' => '/']]],
                        'style_brief' => ['palette' => ['name' => 'Ocean Slate'], 'theme_style' => ['name' => 'Plan-Driven Hybrid']],
                        'seo_brief' => ['core_intent' => 'intent'],
                    ],
                    'footer' => [
                        'goal' => 'Build a reusable footer with policies.',
                        'payload' => ['featured' => [['label' => 'About', 'href' => '/about']]],
                        'style_brief' => ['palette' => ['name' => 'Ocean Slate'], 'theme_style' => ['name' => 'Plan-Driven Hybrid']],
                        'seo_brief' => ['core_intent' => 'intent'],
                    ],
                ],
            ],
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'plan_markdown' => "# Stage 1 Plan\n\n## Home\n- Hero focuses on first-screen conversion",
            'plan_structured' => [
                'site_strategy' => ['site_display_name' => 'Task Plan Test', 'summary' => 'Summary'],
                'palette' => ['name' => 'Ocean Slate'],
                'theme_style' => ['name' => 'Plan-Driven Hybrid', 'responsive_rule' => 'Single column first'],
                'seo_strategy' => ['core_intent' => 'intent'],
                'navigation_plan' => ['header_items' => [['label' => 'Home', 'href' => '/']]],
                'footer_plan' => ['featured' => [['label' => 'About', 'href' => '/about']], 'policies' => [['label' => 'Privacy', 'href' => '/privacy']]],
            ],
        ];

        $artifacts = $service->buildTaskPlanArtifacts($scope, $buildBlueprint);

        self::assertIsArray($artifacts['structured'] ?? null);
        self::assertIsArray($artifacts['virtual_theme_plan'] ?? null);
        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertSame('phase-one-signature', (string)($artifacts['structured']['plan_signature'] ?? ''));
        self::assertIsArray($artifacts['structured']['execution_order'] ?? null);
        self::assertNotEmpty($artifacts['structured']['page_tasks']['home_page'] ?? []);
        self::assertSame('Open with a clear value proposition.', (string)($artifacts['structured']['page_tasks']['home_page'][0]['plan_context']['block_goal'] ?? ''));
        self::assertSame('hero', (string)($artifacts['structured']['page_tasks']['home_page'][0]['plan_context']['block_code'] ?? ''));
        self::assertSame('content/home-page-hero', (string)($artifacts['structured']['page_tasks']['home_page'][0]['plan_context']['section_code'] ?? ''));
        self::assertIsArray($artifacts['structured']['stage1_task_cues']['pages']['page:home_page:content/home-page-hero'] ?? null);
        self::assertSame('Build a reusable header with primary navigation.', (string)($artifacts['structured']['stage1_task_cues']['shared']['shared:header']['stage1_goal'] ?? ''));
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

    public function testBuildTaskPlanArtifactsPassesStageOneTaskCuesIntoAiPrompt(): void
    {
        $capturedPrompt = null;
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturnCallback(function (string $prompt) use (&$capturedPrompt): string {
                $capturedPrompt = $prompt;
                return $this->buildTaskPlanResponse();
            });
        $service = new AiSiteVirtualThemePlanService($aiService);

        $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());

        self::assertIsString($capturedPrompt);
        self::assertStringContainsString('Extracted stage-1 task cues:', $capturedPrompt);
        self::assertStringContainsString('page:home_page:content\\/home-page-hero', $capturedPrompt);
        self::assertStringContainsString('Build a reusable header with primary navigation.', $capturedPrompt);
        self::assertStringContainsString('Hero should translate the main value into first-screen conversion intent.', $capturedPrompt);
        self::assertStringContainsString('This is the confirmed virtual-theme task plan for stage 2: output must be directly usable for virtual_theme_plan.confirmed persistence after user confirmation.', $capturedPrompt);
        self::assertStringContainsString('The task plan must make shared -> home -> other page execution explicit and explain why shared tasks block later tasks.', $capturedPrompt);
    }

    public function testBuildTaskPlanArtifactsUsesNonStreamJsonModeWhenNoChunkCallbackIsProvided(): void
    {
        $capturedParams = null;
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generateStream');
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturnCallback(function (
                string $prompt,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedParams): string {
                $capturedParams = $params;
                return $this->buildTaskPlanResponse();
            });

        $service = new AiSiteVirtualThemePlanService($aiService);
        $artifacts = $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());

        self::assertSame('ai', (string)($artifacts['generation_source'] ?? ''));
        self::assertIsArray($capturedParams);
        self::assertSame(120, (int)($capturedParams['timeout'] ?? 0));
        self::assertSame(['type' => 'json_object'], $capturedParams['response_format'] ?? null);
    }

    public function testBuildTaskPlanArtifactsStreamEnforcesTimeoutAndFallsBackToJsonGenerateWhenStreamResponseIsInvalid(): void
    {
        $capturedStreamParams = null;
        $capturedGenerateParams = null;
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedStreamParams): void {
                $capturedStreamParams = $params;
                $callback('not-json');
            });
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturnCallback(function (
                string $prompt,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedGenerateParams): string {
                $capturedGenerateParams = $params;
                return $this->buildTaskPlanResponse();
            });

        $service = new AiSiteVirtualThemePlanService($aiService);
        $artifacts = $service->buildTaskPlanArtifactsStream(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint(),
            static function (string $chunk): void {
            }
        );

        self::assertSame('ai', (string)($artifacts['generation_source'] ?? ''));
        self::assertIsArray($capturedStreamParams);
        self::assertTrue((bool)($capturedStreamParams['enforce_timeout_in_stream'] ?? false));
        self::assertSame(120, (int)($capturedStreamParams['timeout'] ?? 0));
        self::assertSame(['type' => 'json_object'], $capturedStreamParams['response_format'] ?? null);
        self::assertIsArray($capturedGenerateParams);
        self::assertSame(['type' => 'json_object'], $capturedGenerateParams['response_format'] ?? null);
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

    /**
     * @return array<string, mixed>
     */
    private function buildPromptBlueprint(): array
    {
        return [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'shared:footer', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Footer', 'sort_order' => 20],
                ['task_key' => 'page:home_page:content/home-page-hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'section_code' => 'content/home-page-hero', 'sort_order' => 100],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPromptScope(): array
    {
        return [
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
                                'section_code' => 'content/home-page-hero',
                                'goal' => 'Open with a clear value proposition.',
                                'why' => 'Hero should translate the main value into first-screen conversion intent.',
                                'content_brief' => ['goal' => 'Lead with value', 'body_direction' => 'Keep the first fold scannable.'],
                                'execution_script' => ['feature_points' => ['Primary headline', 'Primary CTA'], 'core_copy' => 'Hero core copy'],
                                'seo_brief' => ['keywords' => ['india games'], 'anchors' => ['#hero'], 'internal_links' => ['/about']],
                                'field_plan' => [['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer']],
                                'result_ref' => [],
                            ],
                        ],
                    ],
                ],
                'shared_components' => [
                    'header' => [
                        'goal' => 'Build a reusable header with primary navigation.',
                        'payload' => ['header_items' => [['label' => 'Home', 'href' => '/'], ['label' => 'About', 'href' => '/about']]],
                        'style_brief' => ['palette' => ['name' => 'Ocean Slate', 'primary' => '#0f172a'], 'theme_style' => ['name' => 'Plan-Driven Hybrid', 'visual_tone' => 'Trustworthy']],
                        'seo_brief' => ['core_intent' => 'intent'],
                    ],
                    'footer' => [
                        'goal' => 'Build a reusable footer with policies.',
                        'payload' => ['featured' => [['label' => 'About', 'href' => '/about']]],
                        'style_brief' => ['palette' => ['name' => 'Ocean Slate', 'primary' => '#0f172a'], 'theme_style' => ['name' => 'Plan-Driven Hybrid', 'visual_tone' => 'Trustworthy']],
                        'seo_brief' => ['core_intent' => 'intent'],
                    ],
                ],
            ],
            'execution_blueprint_confirmed_signature' => 'phase-one-signature',
            'plan_markdown' => "# Stage 1 Plan\n\n## Home\n- Hero focuses on first-screen conversion",
            'plan_structured' => [
                'site_strategy' => ['site_display_name' => 'Task Plan Test', 'summary' => 'Summary'],
                'palette' => ['name' => 'Ocean Slate'],
                'theme_style' => ['name' => 'Plan-Driven Hybrid', 'responsive_rule' => 'Single column first'],
                'seo_strategy' => ['core_intent' => 'intent'],
                'navigation_plan' => ['header_items' => [['label' => 'Home', 'href' => '/'], ['label' => 'About', 'href' => '/about']]],
                'footer_plan' => ['featured' => [['label' => 'About', 'href' => '/about']], 'policies' => [['label' => 'Privacy', 'href' => '/privacy']]],
            ],
        ];
    }
}
