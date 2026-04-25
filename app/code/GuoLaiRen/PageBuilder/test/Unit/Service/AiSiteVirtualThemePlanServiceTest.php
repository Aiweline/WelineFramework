<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;

final class AiSiteVirtualThemePlanServiceTest extends TestCase
{
    public function testStageTwoBatchPromptIncludesFrontendDesignSkillReference(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $method = new \ReflectionMethod($service, 'buildTaskPlanGenerationBatchPrompt');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs($service, [
            [
                'site_title' => 'Frontend Skill Site',
                'brief_description' => 'Build a distinctive landing page for a premium analytics product.',
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
                'plan_locale' => 'zh_Hans_CN',
                'plan_structured' => [
                    'theme_context_snapshot' => ['site_positioning' => 'premium analytics'],
                    'palette' => ['name' => 'Deep graphite with electric lime'],
                    'theme_style' => ['name' => 'editorial control room'],
                ],
            ],
            ['tasks' => [['task_key' => 'page:home_page:hero', 'section_code' => 'hero']]],
            [
                'page_tasks' => [
                    'home_page' => [[
                        'task_key' => 'page:home_page:hero',
                        'page_type' => 'home_page',
                        'block_key' => 'hero',
                        'sort_order' => 10,
                    ]],
                ],
                'stage1_task_cues' => [
                    'pages' => [
                        'page:home_page:hero' => [
                            'block_goal' => 'Make the analytics product memorable in the first viewport.',
                            'page_design_plan' => ['color_layering' => 'ink base, glass panel, lime CTA, lighter metrics band'],
                            'style_direction' => 'editorial dashboard with bold contrast',
                        ],
                    ],
                ],
            ],
            ['page_tasks' => ['home_page' => []]],
            [
                'type' => 'page',
                'key' => 'home_page',
                'block_key' => 'hero',
                'task_keys' => ['page:home_page:hero'],
                'fanout_group' => 'stage2.block_task_plan',
                'queue_job_key' => 'stage2.block_task_plan:home_page:hero',
            ],
        ]);

        self::assertIsString($prompt);
        self::assertStringContainsString('Frontend design skill reference', $prompt);
        self::assertStringContainsString('Service/AI/prompt_guides/frontend-design/SKILL.md', $prompt);
        self::assertStringContainsString('https://github.com/anthropics/claude-code/blob/main/plugins/frontend-design/skills/frontend-design/SKILL.md', $prompt);
        self::assertStringContainsString('Avoid generic AI aesthetics', $prompt);
        self::assertStringContainsString('block_task.style_plan', $prompt);
        self::assertStringContainsString('page_design_plan', $prompt);
        self::assertStringContainsString('color_layering', $prompt);
        self::assertStringContainsString('task_script.responsive_contract', $prompt);
        self::assertStringContainsString('Never use task_key, page_type, section_code, block_key, component paths, or internal IDs as customer-visible copy.', $prompt);
        self::assertStringContainsString('Visible-language rule', $prompt);
        self::assertStringContainsString('Website content locale: en_US', $prompt);
        self::assertStringContainsString('Planned-content language lock', $prompt);
        self::assertStringContainsString('task_script.story_goal, task_script.content_fill_rule', $prompt);
        self::assertStringContainsString('translate/adapt it instead of copying it', $prompt);
    }

    public function testStageTwoFullTaskPlanPromptLocksPlannedContentToWebsiteLanguage(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $method = new \ReflectionMethod($service, 'buildTaskPlanGenerationPrompt');
        $method->setAccessible(true);

        $scope = $this->buildPromptScope();
        $scope['content_locale'] = 'en_US';
        $scope['default_locale'] = 'en_US';
        $scope['plan_locale'] = 'zh_Hans_CN';

        $prompt = $method->invokeArgs($service, [
            $scope,
            $this->buildPromptBlueprint(),
            ['stage1_task_cues' => []],
            [],
        ]);

        self::assertIsString($prompt);
        self::assertStringContainsString('Website content locale: en_US', $prompt);
        self::assertStringContainsString('content_locale/default_locale is the website language for both final copy and planned content', $prompt);
        self::assertStringContainsString('plan_locale is only an internal planning hint', $prompt);
        self::assertStringContainsString('Planned-content language lock', $prompt);
        self::assertStringContainsString('markdown task-plan descriptions, task_script.story_goal, task_script.content_fill_rule', $prompt);
        self::assertStringContainsString('translate/adapt it before writing the task plan', $prompt);
    }

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
                                'design_tags' => ['visual' => ['premium banner'], 'motion' => ['5s fade in/out'], 'interaction' => ['CTA hover'], 'texture' => ['soft gradient'], 'responsive' => ['mobile stacked'], 'implementation_note' => 'Carry tags forward.'],
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
                'theme_context_snapshot' => [
                    'context_hash' => 'stage1-theme-hash',
                    'site_positioning' => 'Shared-first positioning',
                ],
                'site_strategy' => ['site_display_name' => 'Task Plan Test', 'summary' => 'Summary'],
                'palette' => ['name' => 'Ocean Slate'],
                'theme_style' => ['name' => 'Plan-Driven Hybrid', 'responsive_rule' => 'Single column first'],
                'seo_strategy' => ['core_intent' => 'intent'],
                'navigation_plan' => ['header_items' => [['label' => 'Home', 'href' => '/']]],
                'footer_plan' => ['featured' => [['label' => 'About', 'href' => '/about']], 'policies' => [['label' => 'Privacy', 'href' => '/privacy']]],
            ],
            'plan_workbench' => [
                'stage1' => [
                    'theme_context_snapshot' => [
                        'context_hash' => 'stage1-theme-hash',
                        'site_positioning' => 'Shared-first positioning',
                    ],
                ],
                'confirmed' => [
                    'shared_prompt_context' => [
                        'context_hash' => 'stage1-shared-hash',
                        'generation_rule' => 'shared first',
                    ],
                ],
            ],
        ];

        $scope['plan_workbench']['confirmed']['plan_book']['structured'] = $this->buildMinimalConfirmedPlanBook();
        $artifacts = $service->buildTaskPlanArtifacts($scope, $buildBlueprint);

        self::assertIsArray($artifacts['structured'] ?? null);
        self::assertIsArray($artifacts['virtual_theme_plan'] ?? null);
        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertSame('phase-one-signature', (string)($artifacts['structured']['plan_signature'] ?? ''));
        self::assertIsArray($artifacts['structured']['execution_order'] ?? null);
        self::assertNotEmpty($artifacts['structured']['page_tasks']['home_page'] ?? []);
        self::assertSame('Open with a clear value proposition.', (string)($artifacts['structured']['page_tasks']['home_page'][0]['plan_context']['block_goal'] ?? ''));
        self::assertSame('content/home-page-hero', (string)($artifacts['structured']['page_tasks']['home_page'][0]['plan_context']['block_code'] ?? ''));
        self::assertSame('hero', (string)($artifacts['structured']['page_tasks']['home_page'][0]['plan_context']['section_code'] ?? ''));
        self::assertSame(
            ['task_goal', 'meta_fields', 'content_plan', 'style_plan', 'planning_reason', 'sort_order'],
            $artifacts['structured']['block_task_schema']['required_fields'] ?? []
        );
        self::assertSame(
            ['color', 'font', 'spacing', 'responsive'],
            $artifacts['structured']['block_task_schema']['style_plan_required_keys'] ?? []
        );
        $blockTask = $artifacts['structured']['page_tasks']['home_page'][0]['block_task'] ?? [];
        self::assertSame('done', (string)($artifacts['structured']['page_tasks']['home_page'][0]['status'] ?? ''));
        self::assertSame('done', (string)($artifacts['structured']['page_tasks']['home_page'][0]['task_status'] ?? ''));
        self::assertSame(1, (int)($artifacts['structured']['page_tasks']['home_page'][0]['attempt_no'] ?? 0));
        self::assertSame('stage2-block-task-v1', (string)($blockTask['schema_version'] ?? ''));
        self::assertSame('Open with a clear value proposition.', (string)($blockTask['task_goal'] ?? ''));
        self::assertSame(100, (int)($blockTask['sort_order'] ?? 0));
        self::assertSame('title', (string)($blockTask['meta_fields'][0]['field'] ?? ''));
        self::assertSame('string', (string)($blockTask['meta_fields'][0]['type'] ?? ''));
        self::assertSame('Grow faster with our service', (string)($blockTask['meta_fields'][0]['default'] ?? ''));
        self::assertSame('Grow faster with our service', (string)($blockTask['meta_fields'][0]['sample'] ?? ''));
        self::assertIsArray($blockTask['content_plan'] ?? null);
        self::assertSame('Hero title', (string)($blockTask['content_plan']['content_copy'][0]['copy'] ?? ''));
        self::assertSame('Start now', (string)($blockTask['content_plan']['cta_plan'][0]['label'] ?? ''));
        self::assertSame('#contact', (string)($blockTask['content_plan']['link_plan'][0]['href'] ?? ''));
        self::assertSame('primary_visual', (string)($blockTask['content_plan']['asset_plan'][0]['slot'] ?? ''));
        self::assertStringContainsString('Hero', (string)($blockTask['content_plan']['asset_plan'][0]['description'] ?? ''));
        self::assertIsArray($blockTask['style_plan'] ?? null);
        self::assertNotSame('', (string)($blockTask['style_plan']['color'] ?? ''));
        self::assertNotSame('', (string)($blockTask['style_plan']['font'] ?? ''));
        self::assertNotSame('', (string)($blockTask['style_plan']['spacing'] ?? ''));
        self::assertNotSame('', (string)($blockTask['style_plan']['responsive'] ?? ''));
        self::assertNotSame('', (string)($blockTask['planning_reason'] ?? ''));
        self::assertIsArray($artifacts['structured']['stage1_task_cues']['pages']['page:home_page:content/home-page-hero'] ?? null);
        self::assertSame(
            'Open with a clear value proposition.',
            (string)($artifacts['structured']['stage1_task_cues']['pages']['page:home_page:content/home-page-hero']['block_goal'] ?? '')
        );
        self::assertArrayHasKey(
            'realtime_content',
            $artifacts['structured']['stage1_task_cues']['pages']['page:home_page:content/home-page-hero'] ?? []
        );
        self::assertArrayHasKey(
            'style_direction',
            $artifacts['structured']['stage1_task_cues']['pages']['page:home_page:content/home-page-hero'] ?? []
        );
        self::assertSame(
            ['visual' => ['premium banner'], 'motion' => ['5s fade in/out'], 'interaction' => ['CTA hover'], 'texture' => ['soft gradient'], 'responsive' => ['mobile stacked'], 'implementation_note' => 'Carry tags forward.'],
            $artifacts['structured']['stage1_task_cues']['pages']['page:home_page:content/home-page-hero']['design_tags'] ?? []
        );
        self::assertArrayHasKey(
            'reason',
            $artifacts['structured']['stage1_task_cues']['pages']['page:home_page:content/home-page-hero'] ?? []
        );
        self::assertSame('Build a reusable header with primary navigation.', (string)($artifacts['structured']['stage1_task_cues']['shared']['shared:header']['stage1_goal'] ?? ''));
        self::assertIsArray($artifacts['structured']['stage2_context_snapshot'] ?? null);
        self::assertSame('stage1-theme-hash', (string)($artifacts['structured']['stage2_context_snapshot']['theme_context_snapshot']['context_hash'] ?? ''));
        self::assertSame('stage1-shared-hash', (string)($artifacts['structured']['stage2_context_snapshot']['shared_prompt_context']['context_hash'] ?? ''));
        self::assertIsArray($artifacts['structured']['stage2_context_snapshot']['shared_task_summary']['shared:header'] ?? null);
        self::assertIsArray($artifacts['structured']['stage2_context_snapshot']['page_content_tone']['home_page'] ?? null);
        self::assertSame('stage2-block-task-plan-v2', (string)($artifacts['structured']['stage2_context_snapshot']['prompt_version'] ?? ''));
        self::assertNotSame('', (string)($artifacts['structured']['stage2_context_snapshot']['context_hash'] ?? ''));
        self::assertSame(
            (string)($artifacts['structured']['stage2_context_snapshot']['context_hash'] ?? ''),
            (string)($artifacts['structured']['page_tasks']['home_page'][0]['runtime_context']['stage2_context_hash'] ?? '')
        );
        self::assertSame(
            (string)($artifacts['structured']['stage2_context_snapshot']['context_hash'] ?? ''),
            (string)($artifacts['structured']['execution_blueprint']['tasks'][2]['runtime_context']['stage2_context_hash'] ?? '')
        );
        self::assertSame(
            (string)($artifacts['structured']['stage2_context_snapshot']['context_hash'] ?? ''),
            (string)($artifacts['virtual_theme_plan']['page_tasks']['home_page'][0]['runtime_context']['stage2_context_hash'] ?? '')
        );
    }

    public function testBuildTaskPlanArtifactsRecordsStageTwoLocalRegenReportWhenTaskCopyIsInstructionLike(): void
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        $decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['task_script']['story_goal'] = '围绕首页主承诺补充写作说明';
        $decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['task_script']['content_fill_rule'] = '列出 2-4 个卖点并说明如何写';
        $decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['task_script']['field_content_requirements'][0]['sample'] = '待补充';

        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub(\json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $artifacts = $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());

        $report = $artifacts['structured']['_stage2_local_regen_report'] ?? [];
        self::assertIsArray($report);
        self::assertArrayHasKey('final_issue_count', $report);
        self::assertIsArray($artifacts['structured']['page_tasks']['home_page'] ?? null);
        self::assertNotEmpty($artifacts['structured']['page_tasks']['home_page'] ?? []);
    }

    public function testBuildTaskPlanArtifactsRejectsAiPageTasksMissingBlockTaskMetaFields(): void
    {
        $this->assertMissingBlockTaskFieldIsRejected('meta_fields');
    }

    public function testBuildTaskPlanArtifactsRejectsAiPageTasksMissingBlockTaskContentPlan(): void
    {
        $this->assertMissingBlockTaskFieldIsRejected('content_plan');
    }

    public function testBuildTaskPlanArtifactsRejectsAiPageTasksMissingBlockTaskStylePlan(): void
    {
        $this->assertMissingBlockTaskFieldIsRejected('style_plan');
    }

    public function testBuildTaskPlanArtifactsRejectsAiPageTasksMissingBlockTaskPlanningReason(): void
    {
        $this->assertMissingBlockTaskFieldIsRejected('planning_reason');
    }

    public function testBuildTaskPlanArtifactsCopiesSortOrderFromBaselineWhenAiOmitsIt(): void
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        unset($decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['sort_order']);
        unset($decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['block_task']['sort_order']);

        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub(\json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $artifacts = $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());
        $task = $artifacts['structured']['page_tasks']['home_page'][0] ?? [];

        self::assertSame(100, (int)($task['sort_order'] ?? 0));
        self::assertSame(100, (int)($task['block_task']['sort_order'] ?? 0));
    }

    public function testBuildTaskPlanArtifactsBuildsMissingBlockTaskFromStageOneBaselineAlias(): void
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        $decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['task_key'] = 'page:home_page:hero';
        $decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['section_code'] = 'hero';
        unset($decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['block_task']);

        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub(\json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $artifacts = $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());
        $task = $artifacts['structured']['page_tasks']['home_page'][0] ?? [];

        self::assertSame('page:home_page:content/home-page-hero', (string)($task['task_key'] ?? ''));
        self::assertSame(100, (int)($task['block_task']['sort_order'] ?? 0));
        self::assertSame('Open with a clear value proposition.', (string)($task['block_task']['task_goal'] ?? ''));
        self::assertNotEmpty($task['block_task']['content_plan']['field_content_requirements'] ?? []);
    }

    public function testBuildTaskPlanArtifactsBuildsTaskScriptFromBlockTaskWhenAiReturnsEmptyScript(): void
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        $task =& $decoded['virtual_theme_plan']['page_tasks']['home_page'][0];
        $task['task_key'] = 'page:home_page:hero';
        $task['section_code'] = 'hero';
        $task['task_script'] = [
            'scene' => '',
            'story_goal' => '',
            'content_fill_rule' => '',
            'stage3_directive' => '',
            'field_content_requirements' => [
                ['field' => 'headline', 'sample' => '', 'reason' => 'AI omitted the concrete sample'],
            ],
        ];
        $task['block_task']['content_plan']['story_goal'] = 'Visitors see the Royal Indian Games launch offer and understand how to start.';
        $task['block_task']['content_plan']['content_fill_rule'] = 'headline=Royal Indian Games lobby | primary_cta=Play Teen Patti now.';
        $task['block_task']['content_plan']['field_content_requirements'] = [
            ['field' => 'headline', 'sample' => 'Royal Indian Games lobby', 'reason' => 'Use the confirmed offer wording'],
            ['field' => 'primary_cta', 'sample' => 'Play Teen Patti now', 'reason' => 'Use the confirmed conversion action'],
        ];
        $task['block_task']['meta_fields'] = $task['block_task']['content_plan']['field_content_requirements'];

        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub(\json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $artifacts = $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());
        $task = $artifacts['structured']['page_tasks']['home_page'][0] ?? [];

        self::assertSame('page:home_page:content/home-page-hero', (string)($task['task_key'] ?? ''));
        self::assertSame(
            'Visitors see the Royal Indian Games launch offer and understand how to start.',
            (string)($task['task_script']['story_goal'] ?? '')
        );
        self::assertStringContainsString('Royal Indian Games lobby', (string)($task['task_script']['content_fill_rule'] ?? ''));
        self::assertStringContainsString('frontend block', (string)($task['task_script']['stage3_directive'] ?? ''));
        self::assertSame('Royal Indian Games lobby', (string)($task['task_script']['field_content_requirements'][0]['sample'] ?? ''));
        self::assertNotEmpty($task['field_content_requirements'] ?? []);
    }

    public function testBuildTaskPlanArtifactsRepairsInternalComponentPathsInTaskCopy(): void
    {
        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub($this->buildTaskPlanResponseWithInternalComponentPathCopy())
        );

        $artifacts = $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());
        $task = $artifacts['structured']['page_tasks']['home_page'][0] ?? [];
        $contentPlan = \is_array($task['block_task']['content_plan'] ?? null) ? $task['block_task']['content_plan'] : [];

        self::assertNotSame('content/home-page-hero', (string)($contentPlan['field_content_requirements'][0]['sample'] ?? ''));
        self::assertNotSame('page:home_page:content/home-page-hero', (string)($contentPlan['content_copy'][0]['copy'] ?? ''));
        self::assertNotSame('content/home-page-hero', (string)($contentPlan['cta_plan'][0]['label'] ?? ''));
    }

    public function testStageTwoFieldPlanReplacesComponentPathSamplesWithStageOneContent(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $method = new \ReflectionMethod($service, 'buildStageTwoFieldPlanFromPlanBookBlock');
        $method->setAccessible(true);

        $fieldPlan = $method->invokeArgs($service, [[
            'field_plan' => [
                ['field' => 'title', 'sample' => 'content/home-page-hero', 'reason' => 'bad internal key'],
                ['field' => 'primary_cta', 'sample' => 'page:home_page:content/home-page-hero', 'reason' => 'bad internal key'],
            ],
            'realtime_content' => [
                'headline' => 'Premium Indian game lobby',
                'supporting_copy' => ['Play Teen Patti and Rummy in a safe modern lobby.'],
                'cta' => [['label' => 'Join safely now']],
            ],
        ]]);

        self::assertSame('Premium Indian game lobby', $fieldPlan[0]['sample'] ?? '');
        self::assertSame('Join safely now', $fieldPlan[1]['sample'] ?? '');
    }


    public function testStageTwoFieldPlanBuildsSamplesFromRealtimeContentWhenFieldPlanIsMissing(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $method = new \ReflectionMethod($service, 'buildStageTwoFieldPlanFromPlanBookBlock');
        $method->setAccessible(true);

        $fieldPlan = $method->invokeArgs($service, [[
            'title' => 'content/home-page-hero',
            'editable_fields' => ['title', 'subtitle', 'description', 'button_text', 'button_link', 'image'],
            'realtime_content' => [
                'headline' => 'Welcome to Teenipiya',
                'supporting_copy' => [
                    'Play Teen Patti and Rummy with a fast APK download path.',
                    'Compare games, check rules, and start from one clear entry.',
                ],
                'cta' => [
                    ['label' => 'Download Now', 'target' => '#start'],
                ],
                'media' => [
                    ['kind' => 'image', 'rule' => 'SVG card-table visual with warm Indian gaming colors.'],
                ],
            ],
        ]]);

        $encoded = \json_encode($fieldPlan, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '';

        self::assertStringContainsString('Welcome to Teenipiya', $encoded);
        self::assertStringContainsString('Download Now', $encoded);
        self::assertStringContainsString('#start', $encoded);
        self::assertStringContainsString('SVG card-table visual', $encoded);
        self::assertStringNotContainsString('content/home-page-hero', $encoded);
    }

    public function testBuildTaskPlanArtifactsUsesConfirmedPlanBookBlockTreeAsStageTwoInput(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $scope = $this->buildPromptScope();
        $scope['fake_mode'] = 1;
        $scope['plan_markdown'] = "# Stale Markdown\n\n- stale_markdown_only";
        $scope['execution_blueprint_confirmed_signature'] = 'stale-execution-signature';
        $scope['execution_blueprint']['pages']['home_page']['blocks'] = [
            [
                'block_key' => 'stale_markdown_only',
                'section_code' => 'content/stale-markdown-only',
                'goal' => 'Stale markdown-only goal that must not feed stage two.',
            ],
        ];
        $scope['plan_workbench']['confirmed']['plan_book']['structured'] = [
            'source' => 'stage1.block_tree',
            'source_signature' => 'confirmed-block-tree-signature',
            'context_hash' => 'confirmed-plan-book-hash',
            'plan_locale' => 'zh_CN',
            'theme_context_hash' => 'stage1-theme-hash',
            'shared_context_hash' => 'stage1-shared-hash',
            'shared_blocks' => [
                [
                    'task_key' => 'shared:header',
                    'block_key' => 'shared:header',
                    'component' => 'header',
                    'sort_order' => 10,
                    'title' => 'Header',
                    'goal' => 'Confirmed header goal from block tree.',
                    'context_hash' => 'confirmed-header-hash',
                ],
            ],
            'pages' => [
                'home_page' => [
                    'page_key' => 'home_page',
                    'page_label' => 'Home',
                    'page_goal' => 'Confirmed home page goal from block tree.',
                    'shared_context_hash' => 'stage1-shared-hash',
                    'theme_context_hash' => 'stage1-theme-hash',
                    'page_context_hash' => 'confirmed-home-context-hash',
                    'blocks' => [
                        [
                            'task_key' => 'page:home_page:confirmed_hero',
                            'block_key' => 'page:home_page:confirmed_hero',
                            'source_block_key' => 'confirmed_hero',
                            'component_kind' => 'content/confirmed-hero',
                            'sort_order' => 30,
                            'title' => 'Confirmed Hero',
                            'goal' => 'Confirmed hero goal from block tree.',
                            'implementation_detail' => 'Render the confirmed hero, not the stale markdown block.',
                            'realtime_content' => ['headline' => 'Confirmed hero headline'],
                            'reason' => 'Confirmed reason from stage one.',
                            'completion_rule' => 'Confirmed hero can be generated without markdown parsing.',
                            'editable_fields' => ['title'],
                            'style_direction' => 'Use confirmed style direction.',
                            'context_hash' => 'confirmed-hero-hash',
                        ],
                    ],
                ],
            ],
            'counts' => ['shared_blocks' => 1, 'pages' => 1, 'page_blocks' => 1, 'total_blocks' => 2],
        ];
        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'page:home_page:stale_markdown_only', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Stale Markdown', 'section_code' => 'content/stale-markdown-only', 'sort_order' => 100],
            ],
        ];

        $artifacts = $service->buildTaskPlanArtifacts($scope, $buildBlueprint);
        $pageTasks = $artifacts['structured']['page_tasks']['home_page'] ?? [];

        self::assertCount(1, $pageTasks);
        self::assertSame('confirmed-block-tree-signature', (string)($artifacts['structured']['plan_signature'] ?? ''));
        self::assertSame('page:home_page:confirmed_hero', (string)($pageTasks[0]['task_key'] ?? ''));
        self::assertSame('confirmed_hero', (string)($pageTasks[0]['plan_context']['block_code'] ?? ''));
        self::assertSame('content/confirmed-hero', (string)($pageTasks[0]['plan_context']['section_code'] ?? ''));
        self::assertSame('Confirmed hero goal from block tree.', (string)($pageTasks[0]['plan_context']['block_goal'] ?? ''));
        self::assertSame('Confirmed reason from stage one.', (string)($pageTasks[0]['plan_context']['block_reason'] ?? ''));
        self::assertSame('Confirmed hero headline', (string)($pageTasks[0]['plan_context']['realtime_content']['headline'] ?? ''));
        self::assertSame('Use confirmed style direction.', (string)($pageTasks[0]['plan_context']['style_direction'] ?? ''));
        self::assertSame('Confirmed reason from stage one.', (string)($artifacts['structured']['stage1_task_cues']['pages']['page:home_page:confirmed_hero']['reason'] ?? ''));
        self::assertSame('Confirmed hero headline', (string)($artifacts['structured']['stage1_task_cues']['pages']['page:home_page:confirmed_hero']['realtime_content']['headline'] ?? ''));
        self::assertSame('Use confirmed style direction.', (string)($artifacts['structured']['stage1_task_cues']['pages']['page:home_page:confirmed_hero']['style_direction'] ?? ''));
        self::assertSame('confirmed-hero-hash', (string)($pageTasks[0]['plan_context']['result_ref']['context_hash'] ?? ''));
        self::assertSame('confirmed_stage1_plan_book', (string)($artifacts['structured']['stage2_context_snapshot']['confirmed_stage1_source'] ?? ''));
        self::assertSame('confirmed-plan-book-hash', (string)($artifacts['structured']['stage2_context_snapshot']['confirmed_plan_book_context_hash'] ?? ''));
        self::assertStringNotContainsString('stale_markdown_only', \json_encode($artifacts['structured']['page_tasks'], \JSON_UNESCAPED_UNICODE));
    }

    public function testBuildTaskPlanArtifactsFansOutOneStageTwoBlockTaskPlanPerConfirmedBlock(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        $scope = $this->buildPromptScope();
        $scope['fake_mode'] = 1;
        $scope['execution_blueprint']['page_types'] = ['home_page'];
        $scope['plan_workbench']['confirmed']['plan_book']['structured'] = [
            'source' => 'stage1.block_tree',
            'source_signature' => 'confirmed-block-tree-signature',
            'context_hash' => 'confirmed-plan-book-hash',
            'pages' => [
                'home_page' => [
                    'page_key' => 'home_page',
                    'page_goal' => 'Confirmed home page goal.',
                    'blocks' => [
                        [
                            'block_key' => 'hero',
                            'component_kind' => 'content/hero',
                            'sort_order' => 30,
                            'title' => 'Hero',
                            'goal' => 'Hero must state the offer.',
                            'reason' => 'Hero is the primary conversion block.',
                            'editable_fields' => ['title'],
                            'realtime_content' => ['title' => 'Launch with confidence'],
                            'context_hash' => 'hero-hash',
                        ],
                        [
                            'block_key' => 'proof',
                            'component_kind' => 'content/proof',
                            'sort_order' => 40,
                            'title' => 'Proof',
                            'goal' => 'Proof must support the offer.',
                            'reason' => 'Proof reduces first-screen doubt.',
                            'editable_fields' => ['title'],
                            'realtime_content' => ['title' => 'Trusted by growing teams'],
                            'context_hash' => 'proof-hash',
                        ],
                    ],
                ],
            ],
        ];

        $artifacts = $service->buildTaskPlanArtifacts($scope, ['tasks' => []]);
        $pageTasks = $artifacts['structured']['page_tasks']['home_page'] ?? [];
        $confirmedBlocks = $scope['plan_workbench']['confirmed']['plan_book']['structured']['pages']['home_page']['blocks'];

        self::assertCount(2, $pageTasks);
        self::assertCount(\count($confirmedBlocks), $pageTasks);
        self::assertSame(['hero', 'proof'], \array_map(
            static fn(array $task): string => (string)($task['block_key'] ?? ''),
            $pageTasks
        ));
        foreach ($pageTasks as $task) {
            self::assertSame('stage2.block_task_plan', (string)($task['fanout_group'] ?? ''));
            self::assertStringStartsWith('stage2.block_task_plan:home_page:', (string)($task['fanout_job_key'] ?? ''));
            self::assertSame((string)($task['block_key'] ?? ''), (string)($task['runtime_context']['block_key'] ?? ''));
        }

        $stage2Queue = $artifacts['structured']['stage2_queue'] ?? [];
        $blockJobKeys = $stage2Queue['fanout']['block_job_keys'] ?? [];

        self::assertSame('stage2.block_task_plan', (string)($stage2Queue['fanout']['fanout_group'] ?? ''));
        self::assertSame('one_block_one_task', (string)($stage2Queue['fanout']['task_granularity'] ?? ''));
        self::assertSame(\count($confirmedBlocks) + 2, (int)($stage2Queue['fanout']['block_job_count'] ?? 0));
        self::assertSame(
            [
                'stage2.block_task_plan:shared:header',
                'stage2.block_task_plan:shared:footer',
                'stage2.block_task_plan:home_page:hero',
                'stage2.block_task_plan:home_page:proof',
            ],
            $blockJobKeys
        );
        self::assertSame(\count($confirmedBlocks) + 2, \count($blockJobKeys));
        self::assertSame(
            'stage2.block_task_plan',
            (string)($stage2Queue['jobs']['stage2.block_task_plan:home_page:hero']['job_type'] ?? '')
        );
        self::assertSame(
            'hero',
            (string)($stage2Queue['jobs']['stage2.block_task_plan:home_page:hero']['inputs']['block_key'] ?? '')
        );
    }

    public function testBuildTaskPlanArtifactsFallsBackDeterministicallyWhenFakeModeIsEnabled(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->method('generate')->willReturn($this->buildTaskPlanResponse());
        $service = new AiSiteVirtualThemePlanService($aiService);

        $buildBlueprint = [
            'tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'shared:footer', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Footer', 'sort_order' => 20],
                ['task_key' => 'page:home_page:content/home-page-hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'section_code' => 'content/home-page-hero', 'sort_order' => 100],
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

        $scope['plan_workbench']['confirmed']['plan_book']['structured'] = $this->buildMinimalConfirmedPlanBook();
        $artifacts = $service->buildTaskPlanArtifacts($scope, $buildBlueprint);

        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['virtual_theme_plan']['page_tasks']['home_page'] ?? null);
        $pageTask = $artifacts['virtual_theme_plan']['page_tasks']['home_page'][0] ?? [];
        self::assertStringContainsString('Hero title', (string)($pageTask['task_script']['story_goal'] ?? ''));
        self::assertStringNotContainsString('Stage one only gives direction', (string)($pageTask['task_script']['story_goal'] ?? ''));
        self::assertStringNotContainsString('write around', (string)($pageTask['task_script']['content_fill_rule'] ?? ''));
        self::assertNotEmpty($pageTask['task_script']['field_content_requirements'] ?? []);
        self::assertNotEmpty($pageTask['task_script']['field_content_requirements'][0]['sample'] ?? '');
        self::assertNotEmpty($pageTask['implementation_contract']['acceptance'] ?? []);
    }

    public function testBuildTaskPlanArtifactsRejectsChineseVisibleContentForEnglishLocale(): void
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        $task =& $decoded['virtual_theme_plan']['page_tasks']['home_page'][0];
        $task['task_script']['field_content_requirements'][0]['sample'] = "\u{6B22}\u{8FCE}\u{5F00}\u{59CB}";
        $task['block_task']['meta_fields'][0]['sample'] = "\u{6B22}\u{8FCE}\u{5F00}\u{59CB}";
        $task['block_task']['meta_fields'][0]['default'] = "\u{6B22}\u{8FCE}\u{5F00}\u{59CB}";
        $task['block_task']['content_plan']['content_copy'][0]['copy'] = "\u{6B22}\u{8FCE}\u{5F00}\u{59CB}";
        $task['block_task']['content_plan']['cta_plan'][0]['label'] = "\u{7ACB}\u{5373}\u{5F00}\u{59CB}";
        $task['block_task']['content_plan']['asset_plan'][0]['description'] = "\u{4E3B}\u{89C6}\u{89C9}";

        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub(\json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );
        $scope = $this->buildPromptScope();
        $scope['content_locale'] = 'en_US';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('content_locale');

        $service->buildTaskPlanArtifacts($scope, $this->buildPromptBlueprint());
    }

    public function testBuildTaskPlanArtifactsPassesStageOneTaskCuesIntoAiPrompt(): void
    {
        $capturedPrompts = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::atLeast(3))
            ->method('generate')
            ->willReturnCallback(function (string $prompt) use (&$capturedPrompts): string {
                $capturedPrompts[] = $prompt;
                return $this->buildTaskPlanResponse();
            });
        $service = new AiSiteVirtualThemePlanService($aiService);

        $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());

        self::assertCount(3, $capturedPrompts);
        $allPrompts = \implode("\n---batch---\n", $capturedPrompts);
        self::assertStringContainsString('Batch type: shared', $allPrompts);
        self::assertStringContainsString('Batch type: page', $allPrompts);
        self::assertStringContainsString('Compact context for this batch only:', $allPrompts);
        self::assertStringContainsString('"stage1_cues"', $allPrompts);
        self::assertStringContainsString('page:home_page:content\\/home-page-hero', $allPrompts);
        self::assertStringContainsString('Build a reusable header with primary navigation.', $allPrompts);
        self::assertStringContainsString('Hero should translate the main value into first-screen conversion intent.', $allPrompts);
        self::assertStringContainsString('Treat this as a customer-visible implementation plan', $allPrompts);
        self::assertStringContainsString('block_task', $allPrompts);
        self::assertStringContainsString('shared_tasks[] item MUST include planning_reason', $allPrompts);
        self::assertStringContainsString('task_goal, meta_fields, content_plan, style_plan, planning_reason, sort_order', $allPrompts);
        self::assertStringContainsString('Every planning_reason must be concrete and traceable to stage-1', $allPrompts);
        self::assertStringContainsString('concrete color, font, spacing, and responsive keys', $allPrompts);
        self::assertStringContainsString('page_design_plan', $allPrompts);
        self::assertStringContainsString('page-level color layering', $allPrompts);
        self::assertStringContainsString('UI/interaction designer handoff', $allPrompts);
        self::assertStringContainsString('hero panel over slate base', $allPrompts);
        self::assertStringNotContainsString('Stage-1 compact context summary:', $allPrompts);
        self::assertStringNotContainsString('Stage-1 plan_json:', $allPrompts);
        self::assertStringNotContainsString('Baseline virtual_theme_plan compatibility snapshot:', $allPrompts);
        self::assertStringNotContainsString('confirmed_plan_book', $allPrompts);
        self::assertStringNotContainsString("# Stage 1 Plan\n\n## Home\n- Hero focuses on first-screen conversion", $allPrompts);
    }

    public function testBuildTaskPlanArtifactsFanoutsPageBlocksAsSingleTaskBatches(): void
    {
        $capturedPrompts = [];
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        $virtualThemePlan = \is_array($decoded['virtual_theme_plan'] ?? null) ? $decoded['virtual_theme_plan'] : [];
        $heroTask = $virtualThemePlan['page_tasks']['home_page'][0] ?? [];
        $proofTask = \array_replace_recursive($heroTask, [
            'task_key' => 'page:home_page:content/home-page-proof',
            'label' => 'Proof',
            'sort_order' => 110,
            'plan_context' => [
                'block_goal' => 'Show concrete proof for the offer.',
                'block_code' => 'proof',
            ],
            'task_script' => [
                'scene' => 'page:home_page/block:proof',
                'story_goal' => 'Make the proof block credibility-ready.',
            ],
        ]);

        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::atLeast(3))
            ->method('generate')
            ->willReturnCallback(function (string $prompt) use (&$capturedPrompts, $virtualThemePlan, $heroTask, $proofTask): string {
                $capturedPrompts[] = $prompt;
                if (\str_contains($prompt, 'Batch type: shared')) {
                    return \json_encode([
                        'shared_tasks' => $virtualThemePlan['shared_tasks'] ?? [],
                        'risk_notes' => ['Shared batch payload'],
                    ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
                }

                return \json_encode([
                    'page_type' => 'home_page',
                    'page_tasks' => \str_contains($prompt, 'content/home-page-proof') ? [$proofTask] : [$heroTask],
                    'risk_notes' => ['Block batch payload'],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
            });
        $service = new AiSiteVirtualThemePlanService($aiService);
        $scope = $this->buildPromptScope();
        $scope['execution_blueprint']['pages']['home_page']['blocks'][] = [
            'block_key' => 'proof',
            'section_code' => 'content/home-page-proof',
            'goal' => 'Show concrete proof for the offer.',
            'why' => 'Proof reduces doubt after the hero CTA.',
            'content_brief' => ['goal' => 'Show proof points.'],
            'execution_script' => ['feature_points' => ['Trust metric'], 'core_copy' => 'Trusted by growing teams'],
            'field_plan' => [['field' => 'title', 'sample' => 'Trusted by growing teams', 'reason' => 'Credibility headline']],
            'result_ref' => ['context_hash' => 'proof-hash'],
        ];
        $scope['plan_workbench']['confirmed']['plan_book']['structured']['pages']['home_page']['blocks'][] = [
            'task_key' => 'page:home_page:content/home-page-proof',
            'block_key' => 'page:home_page:content/home-page-proof',
            'source_block_key' => 'content/home-page-proof',
            'component_kind' => 'proof',
            'sort_order' => 110,
            'title' => 'Proof',
            'goal' => 'Show concrete proof for the offer.',
            'implementation_detail' => 'Render proof cards after the hero.',
            'realtime_content' => [
                'headline' => 'Trusted by growing teams',
                'supporting_copy' => ['Proof reduces doubt after the hero CTA.'],
            ],
            'reason' => 'Proof reduces doubt after the hero CTA.',
            'completion_rule' => 'Proof block has concrete copy and style direction.',
            'editable_fields' => ['title'],
            'field_plan' => [['field' => 'title', 'sample' => 'Trusted by growing teams', 'reason' => 'Credibility headline']],
            'style_direction' => 'Use proof cards.',
            'dependencies' => ['shared:header'],
            'context_hash' => 'proof-hash',
        ];
        $blueprint = $this->buildPromptBlueprint();
        $blueprint['tasks'][] = [
            'task_key' => 'page:home_page:content/home-page-proof',
            'group_key' => 'home_page',
            'page_type' => 'home_page',
            'label' => 'Proof',
            'section_code' => 'content/home-page-proof',
            'sort_order' => 110,
            'dependencies' => ['shared:header'],
        ];

        $artifacts = $service->buildTaskPlanArtifacts($scope, $blueprint);

        self::assertCount(4, $capturedPrompts);
        $sharedPrompts = \array_values(\array_filter($capturedPrompts, static fn(string $prompt): bool => \str_contains($prompt, 'Batch type: shared')));
        self::assertCount(2, $sharedPrompts);
        self::assertStringContainsString('Task keys in this batch: shared:header', $sharedPrompts[0]);
        self::assertStringContainsString('Task keys in this batch: shared:footer', $sharedPrompts[1]);
        $pagePrompts = \array_values(\array_filter($capturedPrompts, static fn(string $prompt): bool => \str_contains($prompt, 'Batch type: page')));
        self::assertCount(2, $pagePrompts);
        self::assertStringContainsString('Task keys in this batch: page:home_page:content/home-page-hero', $pagePrompts[0]);
        self::assertStringContainsString('Task keys in this batch: page:home_page:content/home-page-proof', $pagePrompts[1]);
        self::assertStringContainsString('Fanout group: stage2.block_task_plan', $pagePrompts[0]);
        self::assertSame(
            ['Hero', 'Proof'],
            \array_map(static fn(array $task): string => (string)($task['label'] ?? ''), $artifacts['structured']['page_tasks']['home_page'] ?? [])
        );
        self::assertSame('weline_queue', (string)($artifacts['structured']['stage2_queue']['fanout']['queue_driver'] ?? ''));
        self::assertSame('all_blocks_parallel_after_stage1_theme', (string)($artifacts['structured']['stage2_queue']['fanout']['dispatch_policy'] ?? ''));
    }

    public function testBuildTaskPlanArtifactsStreamEnforcesTimeoutAndFallsBackToJsonGenerateWhenStreamResponseIsInvalid(): void
    {
        $capturedStreamParams = [];
        $capturedGenerateParams = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::any())
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedStreamParams): void {
                $capturedStreamParams[] = $params;
                $callback('not-json');
            });
        $aiService->expects(self::atLeast(3))
            ->method('generate')
            ->willReturnCallback(function (
                string $prompt,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedGenerateParams): string {
                $capturedGenerateParams[] = $params;
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
        self::assertGreaterThanOrEqual(2, \count($capturedStreamParams));
        foreach ($capturedStreamParams as $params) {
            self::assertFalse((bool)($params['enforce_timeout_in_stream'] ?? true));
            self::assertSame(0, (int)($params['timeout'] ?? -1));
            self::assertLessThanOrEqual(8192, (int)($params['max_tokens'] ?? 0));
            self::assertSame(['type' => 'json_object'], $params['response_format'] ?? null);
            self::assertTrue((bool)($params['disable_conversation_history'] ?? false));
            self::assertTrue((bool)($params['disable_conversation_persist'] ?? false));
        }
        self::assertGreaterThanOrEqual(2, \count($capturedGenerateParams));
        foreach ($capturedGenerateParams as $params) {
            self::assertLessThanOrEqual(8192, (int)($params['max_tokens'] ?? 0));
            self::assertSame(['type' => 'json_object'], $params['response_format'] ?? null);
            self::assertTrue((bool)($params['disable_conversation_history'] ?? false));
            self::assertTrue((bool)($params['disable_conversation_persist'] ?? false));
        }
    }

    public function testBuildTaskPlanArtifactsSanitizesPromptLikeTaskCopy(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::atLeast(3))
            ->method('generate')
            ->willReturnCallback(function (string $prompt): string {
                return $this->buildPromptLikeTaskPlanBatchResponse($prompt);
            });
        $service = new AiSiteVirtualThemePlanService($aiService);

        $artifacts = $service->buildTaskPlanArtifacts(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint()
        );

        $sharedTask = $artifacts['structured']['shared_tasks'][0] ?? [];
        $pageTask = $artifacts['structured']['page_tasks']['home_page'][0] ?? [];
        self::assertIsArray($sharedTask);
        self::assertIsArray($pageTask);
        self::assertStringNotContainsString('Stage one only gives direction', (string)($sharedTask['task_script']['story_goal'] ?? ''));
        self::assertStringNotContainsString('待补充', (string)($sharedTask['task_script']['field_content_requirements'][0]['sample'] ?? ''));
        self::assertStringNotContainsString('write around', (string)($pageTask['task_script']['content_fill_rule'] ?? ''));
        self::assertStringNotContainsString('待补充', (string)($pageTask['task_script']['field_content_requirements'][0]['sample'] ?? ''));
        self::assertNotEmpty($sharedTask['task_script']['field_content_requirements'][0]['sample'] ?? '');
    }

    public function testBuildTaskPlanArtifactsStreamPassesHeartbeatCallbackToProvider(): void
    {
        $capturedStreamParams = [];
        $heartbeatCount = 0;
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::any())
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedStreamParams, &$heartbeatCount): void {
                $capturedStreamParams[] = $params;
                self::assertIsCallable($params['on_heartbeat'] ?? null);
                ($params['on_heartbeat'])();
                $callback($this->buildTaskPlanResponse());
            });
        $aiService->method('generate')->willReturn($this->buildTaskPlanResponse());

        $service = new AiSiteVirtualThemePlanService($aiService);
        $artifacts = $service->buildTaskPlanArtifactsStream(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint(),
            static function (string $chunk): void {
            },
            static function () use (&$heartbeatCount): void {
                $heartbeatCount++;
            }
        );

        self::assertSame('ai', (string)($artifacts['generation_source'] ?? ''));
        self::assertGreaterThanOrEqual(2, \count($capturedStreamParams));
        self::assertGreaterThanOrEqual(2, $heartbeatCount);
        foreach ($capturedStreamParams as $params) {
            self::assertLessThanOrEqual(8192, (int)($params['max_tokens'] ?? 0));
            self::assertIsCallable($params['on_heartbeat'] ?? null);
            self::assertTrue((bool)($params['disable_conversation_history'] ?? false));
            self::assertTrue((bool)($params['disable_conversation_persist'] ?? false));
        }
    }

    public function testBuildTaskPlanArtifactsAcceptsWrappedJsonGenerateResponses(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::atLeast(3))
            ->method('generate')
            ->willReturnCallback(function (string $prompt): string {
                return $this->buildWrappedTaskPlanBatchResponse($prompt);
            });
        $service = new AiSiteVirtualThemePlanService($aiService);

        $artifacts = $service->buildTaskPlanArtifacts(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint()
        );

        self::assertSame('ai', (string)($artifacts['generation_source'] ?? ''));
        self::assertNotEmpty($artifacts['structured']['shared_tasks'] ?? []);
        self::assertNotEmpty($artifacts['structured']['page_tasks']['home_page'] ?? []);
        self::assertSame('Header', (string)($artifacts['structured']['shared_tasks'][0]['label'] ?? ''));
    }

    public function testBuildTaskPlanArtifactsStreamAcceptsWrappedJsonStreamResponses(): void
    {
        $forwarded = '';
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::atLeast(3))
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback
            ): void {
                foreach (\str_split($this->buildWrappedTaskPlanBatchResponse($prompt), 31) as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }
                    $callback($chunk);
                }
            });
        $aiService->expects(self::never())->method('generate');
        $service = new AiSiteVirtualThemePlanService($aiService);

        $artifacts = $service->buildTaskPlanArtifactsStream(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint(),
            static function (string $chunk) use (&$forwarded): void {
                $forwarded .= $chunk;
            }
        );

        self::assertStringContainsString('```json', $forwarded);
        self::assertSame('ai', (string)($artifacts['generation_source'] ?? ''));
        self::assertNotEmpty($artifacts['structured']['shared_tasks'] ?? []);
        self::assertNotEmpty($artifacts['structured']['page_tasks']['home_page'] ?? []);
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
                        'page_design_plan' => [
                            'page_role' => 'conversion entry page',
                            'color_layering' => 'hero panel over slate base, proof cards on light surface, ember CTA accent',
                            'section_flow' => ['hero impact', 'proof layer', 'final CTA'],
                            'interaction_notes' => ['CTA hover glow', 'card lift on proof'],
                        ],
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
                ['task_key' => 'shared:footer', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Footer', 'sort_order' => 20],
                ['task_key' => 'page:home_page:hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'sort_order' => 100],
            ],
        ];

        $scope['plan_workbench']['confirmed']['plan_book']['structured'] = $this->buildMinimalConfirmedPlanBook();
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

    public function testRefineDraftTaskPlanStreamsAiChunksWhenCallbackProvided(): void
    {
        $chunks = \str_split($this->buildTaskPlanResponse(), 64);
        $forwarded = '';
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use ($chunks): void {
                foreach ($chunks as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }
                    $callback($chunk);
                }
            });
        $aiService->expects(self::never())->method('generate');
        $service = new AiSiteVirtualThemePlanService($aiService);

        $result = $service->refineDraftTaskPlan(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint(),
            [],
            [
                'instruction' => 'Only refine the home hero section.',
                'target_scope' => 'page:home_page:hero',
                'round' => 2,
            ],
            static function (string $chunk) use (&$forwarded): void {
                $forwarded .= $chunk;
            }
        );

        self::assertNotSame('', $forwarded);
        self::assertNotSame('', (string)($result['markdown'] ?? ''));
        self::assertSame('ai', (string)($result['generation_source'] ?? ''));
    }

    public function testRefineDraftTaskPlanUsesStreamHeartbeatPathWithoutRawChunkForwarding(): void
    {
        $capturedStreamParams = null;
        $heartbeatCount = 0;
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
            ) use (&$capturedStreamParams, &$heartbeatCount): void {
                $capturedStreamParams = $params;
                self::assertIsCallable($params['on_heartbeat'] ?? null);
                ($params['on_heartbeat'])();
                $callback($this->buildTaskPlanResponse());
            });
        $aiService->expects(self::never())->method('generate');
        $service = new AiSiteVirtualThemePlanService($aiService);

        $result = $service->refineDraftTaskPlan(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint(),
            [],
            [
                'instruction' => 'Only refine the home hero section.',
                'target_scope' => 'page:home_page:content/home-page-hero',
                'round' => 2,
            ],
            null,
            static function () use (&$heartbeatCount): void {
                $heartbeatCount++;
            }
        );

        self::assertGreaterThanOrEqual(1, $heartbeatCount);
        self::assertIsArray($capturedStreamParams);
        self::assertSame(['type' => 'json_object'], $capturedStreamParams['response_format'] ?? null);
        self::assertTrue((bool)($capturedStreamParams['disable_conversation_history'] ?? false));
        self::assertTrue((bool)($capturedStreamParams['disable_conversation_persist'] ?? false));
        self::assertSame('ai', (string)($result['generation_source'] ?? ''));
        self::assertNotSame('', (string)($result['markdown'] ?? ''));
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
                ['task_key' => 'shared:footer', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Footer', 'sort_order' => 20],
                ['task_key' => 'page:home_page:content/home-page-hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'section_code' => 'content/home-page-hero', 'sort_order' => 100],
            ],
        ];

        $scope['plan_workbench']['confirmed']['plan_book']['structured'] = $this->buildMinimalConfirmedPlanBook();
        $result = $service->rebuildDraftTaskPlan($scope, $buildBlueprint, [
            'instruction' => 'Rebuild the task plan around a new brand direction.',
            'round' => 3,
        ]);

        self::assertNotSame('', (string)($result['markdown'] ?? ''));
        self::assertIsArray($result['rebuild_summary'] ?? null);
        self::assertSame(3, (int)($result['rebuild_summary']['task_count'] ?? 0));
        self::assertSame(3, (int)($result['rebuild_summary']['round'] ?? 0));
        self::assertIsArray($result['virtual_theme_plan']['rebuild_summary'] ?? null);
    }

    public function testReorderDraftTaskPlanTasksUpdatesPageTasksAndExecutionOrder(): void
    {
        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub($this->buildTaskPlanResponse())
        );

        $structured = [
            'shared_tasks' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Header', 'sort_order' => 10],
                ['task_key' => 'shared:footer', 'group_key' => 'shared', 'page_type' => '', 'label' => 'Footer', 'sort_order' => 20],
            ],
            'page_tasks' => [
                'home_page' => [
                    ['task_key' => 'page:home_page:content/home-page-hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Hero', 'section_code' => 'content/home-page-hero', 'sort_order' => 100, 'block_task' => ['sort_order' => 100]],
                    ['task_key' => 'page:home_page:content/home-page-proof', 'group_key' => 'home_page', 'page_type' => 'home_page', 'label' => 'Proof', 'section_code' => 'content/home-page-proof', 'sort_order' => 110, 'block_task' => ['sort_order' => 110]],
                ],
            ],
            'execution_order' => [
                ['task_key' => 'shared:header', 'group_key' => 'shared', 'page_type' => '', 'sort_order' => 10],
                ['task_key' => 'shared:footer', 'group_key' => 'shared', 'page_type' => '', 'sort_order' => 20],
                ['task_key' => 'page:home_page:content/home-page-hero', 'group_key' => 'home_page', 'page_type' => 'home_page', 'sort_order' => 100],
                ['task_key' => 'page:home_page:content/home-page-proof', 'group_key' => 'home_page', 'page_type' => 'home_page', 'sort_order' => 110],
            ],
            'task_tree' => [
                'task_key' => 'site:virtual_theme',
                'children' => [],
            ],
            'risk_notes' => ['Shared tasks must stay ahead of page tasks.'],
        ];
        $scope = [
            'execution_blueprint' => [
                'page_types' => ['home_page'],
            ],
            'virtual_theme_plan' => [
                'draft' => $structured,
                'draft_markdown' => '# Task Plan',
                'plan_signature' => 'before-reorder',
            ],
            'task_plan_structured' => $structured,
            'task_plan_markdown' => '# Task Plan',
        ];

        $orderedTaskKeys = [
            'page:home_page:content/home-page-proof',
            'page:home_page:content/home-page-hero',
        ];
        $result = $service->reorderDraftTaskPlanTasks($scope, 'page', $orderedTaskKeys, 'home_page');

        self::assertSame(
            $orderedTaskKeys,
            \array_values(\array_map(
                static fn(array $task): string => (string)($task['task_key'] ?? ''),
                $result['structured']['page_tasks']['home_page'] ?? []
            ))
        );
        self::assertSame(
            $orderedTaskKeys,
            \array_values(\array_map(
                static fn(array $task): string => (string)($task['task_key'] ?? ''),
                $result['virtual_theme_plan']['page_tasks']['home_page'] ?? []
            ))
        );
        self::assertSame(
            $orderedTaskKeys,
            \array_values(\array_map(
                static fn(array $task): string => (string)($task['task_key'] ?? ''),
                $result['structured']['page_block_tasks'] ?? []
            ))
        );
        self::assertSame([100, 110], \array_values(\array_map(
            static fn(array $task): int => (int)($task['sort_order'] ?? 0),
            $result['structured']['page_block_tasks'] ?? []
        )));
        self::assertSame([100, 110], \array_values(\array_map(
            static fn(array $task): int => (int)($task['block_task']['sort_order'] ?? 0),
            $result['structured']['page_tasks']['home_page'] ?? []
        )));
        self::assertSame(
            $orderedTaskKeys,
            \array_values(\array_map(
                static fn(array $node): string => (string)($node['task_key'] ?? ''),
                $result['virtual_theme_plan']['virtual_theme_build_tree']['pages']['home_page']['blocks'] ?? []
            ))
        );
        self::assertSame(
            ['shared:header', 'shared:footer', 'page:home_page:content/home-page-proof', 'page:home_page:content/home-page-hero'],
            \array_values(\array_map(
                static fn(array $task): string => (string)($task['task_key'] ?? ''),
                $result['structured']['execution_order'] ?? []
            ))
        );
    }

    public function testMutateDraftTaskPlanTaskSyncsStructuredAliasesAndOperationLog(): void
    {
        $service = new AiSiteVirtualThemePlanService();
        /** @var array<string, mixed> $payload */
        $payload = \json_decode($this->buildTaskPlanResponse(), true, 512, \JSON_THROW_ON_ERROR);
        $structured = \is_array($payload['virtual_theme_plan'] ?? null) ? $payload['virtual_theme_plan'] : [];
        $scope = [
            'execution_blueprint' => ['page_types' => ['home_page']],
            'task_plan_structured' => $structured,
            'task_plan_markdown' => '# Task Plan',
            'virtual_theme_plan' => [
                'draft' => $structured,
                'draft_markdown' => '# Task Plan',
            ],
        ];

        $created = $service->mutateDraftTaskPlanTask(
            $scope,
            'create',
            'page',
            'home_page',
            '',
            [
                'label' => 'FAQ block',
                'section_code' => 'content/home-page-faq',
                'block_task' => [
                    'planning_reason' => 'FAQ removes last-mile conversion doubt.',
                ],
            ],
            'Add a conversion FAQ block.'
        );
        $createdKey = 'page:home_page:content/home-page-faq';
        self::assertSame(1, (int)($created['structured']['task_plan_version'] ?? 0));
        self::assertSame('create', (string)($created['structured']['last_task_plan_operation']['action'] ?? ''));
        self::assertContains($createdKey, \array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $created['structured']['page_tasks']['home_page'] ?? []
        ));
        self::assertContains($createdKey, \array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $created['structured']['page_block_tasks'] ?? []
        ));
        self::assertContains($createdKey, \array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $created['virtual_theme_plan']['execution_blueprint']['tasks'] ?? []
        ));

        $scope = [
            'execution_blueprint' => ['page_types' => ['home_page']],
            'task_plan_structured' => $created['structured'],
            'task_plan_markdown' => $created['markdown'],
            'virtual_theme_plan' => [
                'draft' => $created['virtual_theme_plan'],
                'draft_markdown' => $created['markdown'],
            ],
        ];
        $refined = $service->mutateDraftTaskPlanTask(
            $scope,
            'refine',
            'page',
            'home_page',
            $createdKey,
            ['label' => 'FAQ block refined'],
            'Tighten FAQ copy around buyer objections.'
        );
        $refinedTasks = $refined['structured']['page_tasks']['home_page'] ?? [];
        $refinedFaq = \current(\array_values(\array_filter(
            $refinedTasks,
            static fn(array $task): bool => (string)($task['task_key'] ?? '') === $createdKey
        )));
        self::assertIsArray($refinedFaq);
        self::assertSame('FAQ block refined', (string)($refinedFaq['label'] ?? ''));
        self::assertSame('Tighten FAQ copy around buyer objections.', (string)($refinedFaq['block_task']['task_goal'] ?? ''));
        self::assertSame(2, (int)($refined['structured']['task_plan_version'] ?? 0));

        $scope['task_plan_structured'] = $refined['structured'];
        $scope['task_plan_markdown'] = $refined['markdown'];
        $scope['virtual_theme_plan']['draft'] = $refined['virtual_theme_plan'];
        $scope['virtual_theme_plan']['draft_markdown'] = $refined['markdown'];
        $rebuilt = $service->mutateDraftTaskPlanTask(
            $scope,
            'rebuild',
            'page',
            'home_page',
            'page:home_page:content/home-page-hero',
            [],
            'Rebuild the hero task from the latest positioning.'
        );
        $rebuiltHero = \current(\array_values(\array_filter(
            $rebuilt['structured']['page_tasks']['home_page'] ?? [],
            static fn(array $task): bool => (string)($task['task_key'] ?? '') === 'page:home_page:content/home-page-hero'
        )));
        self::assertIsArray($rebuiltHero);
        self::assertSame('todo', (string)($rebuiltHero['status'] ?? ''));
        self::assertSame(1, (int)($rebuiltHero['rebuild_requested'] ?? 0));
        self::assertSame(3, (int)($rebuilt['structured']['task_plan_version'] ?? 0));

        $scope['task_plan_structured'] = $rebuilt['structured'];
        $scope['task_plan_markdown'] = $rebuilt['markdown'];
        $scope['virtual_theme_plan']['draft'] = $rebuilt['virtual_theme_plan'];
        $scope['virtual_theme_plan']['draft_markdown'] = $rebuilt['markdown'];
        $deleted = $service->mutateDraftTaskPlanTask($scope, 'delete', 'shared', '', 'shared:footer');
        self::assertNotContains('shared:footer', \array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $deleted['structured']['shared_tasks'] ?? []
        ));
        self::assertNotContains('shared:footer', \array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $deleted['structured']['shared_block_tasks'] ?? []
        ));
        self::assertSame(4, (int)($deleted['structured']['task_plan_version'] ?? 0));
        self::assertCount(4, $deleted['structured']['task_plan_operation_log'] ?? []);
    }

    public function testRebuildDraftTaskPlanStreamsAiChunksWhenCallbackProvided(): void
    {
        $chunks = \str_split($this->buildTaskPlanResponse(), 64);
        $forwarded = '';
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::any())
            ->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use ($chunks): void {
                foreach ($chunks as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }
                    $callback($chunk);
                }
            });
        $aiService->expects(self::never())->method('generate');
        $service = new AiSiteVirtualThemePlanService($aiService);

        $result = $service->rebuildDraftTaskPlan(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint(),
            [
                'instruction' => 'Rebuild the task plan around a new brand direction.',
                'round' => 3,
            ],
            static function (string $chunk) use (&$forwarded): void {
                $forwarded .= $chunk;
            }
        );

        self::assertNotSame('', $forwarded);
        self::assertNotSame('', (string)($result['markdown'] ?? ''));
        self::assertSame('ai', (string)($result['generation_source'] ?? ''));
    }

    public function testRebuildDraftTaskPlanFallsBackToJsonGenerateWhenHeartbeatStreamReturnsInvalidJson(): void
    {
        $capturedGenerateParams = [];
        $heartbeatCount = 0;
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::any())
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$heartbeatCount): void {
                self::assertIsCallable($params['on_heartbeat'] ?? null);
                ($params['on_heartbeat'])();
                $callback('not-json');
            });
        $aiService->expects(self::atLeast(3))
            ->method('generate')
            ->willReturnCallback(function (
                string $prompt,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedGenerateParams): string {
                $capturedGenerateParams[] = $params;
                if (\str_contains($prompt, 'Batch type: shared')) {
                    return \json_encode([
                        'shared_tasks' => \json_decode($this->buildTaskPlanResponse(), true)['virtual_theme_plan']['shared_tasks'] ?? [],
                        'risk_notes' => ['Shared batch fallback'],
                    ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
                }
                return \json_encode([
                    'page_type' => 'home_page',
                    'page_tasks' => \json_decode($this->buildTaskPlanResponse(), true)['virtual_theme_plan']['page_tasks']['home_page'] ?? [],
                    'risk_notes' => ['Page batch fallback'],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
            });
        $service = new AiSiteVirtualThemePlanService($aiService);

        $result = $service->rebuildDraftTaskPlan(
            $this->buildPromptScope(),
            $this->buildPromptBlueprint(),
            [
                'instruction' => 'Rebuild the task plan around a new brand direction.',
                'round' => 3,
            ],
            null,
            static function () use (&$heartbeatCount): void {
                $heartbeatCount++;
            }
        );

        self::assertGreaterThanOrEqual(2, $heartbeatCount);
        self::assertGreaterThanOrEqual(2, \count($capturedGenerateParams));
        foreach ($capturedGenerateParams as $params) {
            self::assertSame(['type' => 'json_object'], $params['response_format'] ?? null);
            self::assertTrue((bool)($params['disable_conversation_history'] ?? false));
            self::assertTrue((bool)($params['disable_conversation_persist'] ?? false));
        }
        self::assertSame('ai', (string)($result['generation_source'] ?? ''));
        self::assertNotSame('', (string)($result['markdown'] ?? ''));
    }

    private function createAiServiceStub(string $response): AiService
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->method('generate')->willReturn($response);
        return $aiService;
    }

    private function assertMissingBlockTaskFieldIsRejected(string $missingField): void
    {
        $service = new AiSiteVirtualThemePlanService(
            $this->createAiServiceStub($this->buildTaskPlanResponseMissingBlockTaskField($missingField))
        );

        try {
            $service->buildTaskPlanArtifacts($this->buildPromptScope(), $this->buildPromptBlueprint());
            self::fail('Expected missing block_task field to be rejected: ' . $missingField);
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('block_task', $exception->getMessage());
            self::assertStringContainsString($missingField, $exception->getMessage());
        }
    }

    private function buildTaskPlanResponseMissingBlockTaskField(string $field): string
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        if (isset($decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['block_task'])
            && \is_array($decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['block_task'])
        ) {
            unset($decoded['virtual_theme_plan']['page_tasks']['home_page'][0]['block_task'][$field]);
        }

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildTaskPlanResponseWithInternalComponentPathCopy(): string
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        $task =& $decoded['virtual_theme_plan']['page_tasks']['home_page'][0];
        $task['task_script']['story_goal'] = 'Make the hero conversion-ready.';
        $task['task_script']['content_fill_rule'] = 'Use short headline and one CTA.';
        $task['task_script']['field_content_requirements'][0]['sample'] = 'content/home-page-hero';
        $task['block_task']['content_plan']['content_copy'][0]['copy'] = 'page:home_page:content/home-page-hero';
        $task['block_task']['content_plan']['cta_plan'][0]['label'] = 'content/home-page-hero';

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildWrappedTaskPlanBatchResponse(string $prompt): string
    {
        $payload = $this->buildTaskPlanBatchPayloadForPrompt($prompt);
        $schemaExample = isset($payload['shared_tasks'])
            ? '{"shared_tasks":[],"risk_notes":[]}'
            : '{"page_type":"home_page","page_tasks":[],"risk_notes":[]}';

        return "\xEF\xBB\xBFSchema example: " . $schemaExample
            . "\nActual output:\n```json\n"
            . (\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) ?: '{}')
            . "\n```\nUse this payload only.";
    }

    private function buildPromptLikeTaskPlanBatchResponse(string $prompt): string
    {
        $payload = $this->buildTaskPlanBatchPayloadForPrompt($prompt);
        if (isset($payload['shared_tasks'][0]['task_script']) && \is_array($payload['shared_tasks'][0]['task_script'])) {
            $payload['shared_tasks'][0]['task_script']['story_goal'] = 'Stage one only gives direction; write around Header.';
            $payload['shared_tasks'][0]['task_script']['content_fill_rule'] = 'Write around brand and navigation value.';
            $payload['shared_tasks'][0]['task_script']['field_content_requirements'][0]['sample'] = '待补充';
        }
        if (isset($payload['page_tasks'][0]['task_script']) && \is_array($payload['page_tasks'][0]['task_script'])) {
            $payload['page_tasks'][0]['task_script']['story_goal'] = 'Stage one only gives direction; write around Hero.';
            $payload['page_tasks'][0]['task_script']['content_fill_rule'] = 'Write around the block goal and CTA direction.';
            $payload['page_tasks'][0]['task_script']['field_content_requirements'][0]['sample'] = '待补充';
        }

        return \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskPlanBatchPayloadForPrompt(string $prompt): array
    {
        $decoded = \json_decode($this->buildTaskPlanResponse(), true);
        $virtualThemePlan = \is_array($decoded['virtual_theme_plan'] ?? null) ? $decoded['virtual_theme_plan'] : [];
        if (\str_contains($prompt, 'Batch type: shared')) {
            return [
                'shared_tasks' => \is_array($virtualThemePlan['shared_tasks'] ?? null) ? $virtualThemePlan['shared_tasks'] : [],
                'risk_notes' => ['Shared batch payload'],
            ];
        }

        return [
            'page_type' => 'home_page',
            'page_tasks' => \is_array($virtualThemePlan['page_tasks']['home_page'] ?? null) ? $virtualThemePlan['page_tasks']['home_page'] : [],
            'risk_notes' => ['Page batch payload'],
        ];
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
                    [
                        'task_key' => 'shared:footer',
                        'group_key' => 'shared',
                        'page_type' => '',
                        'label' => 'Footer',
                        'sort_order' => 20,
                        'task_script' => [
                            'scene' => 'shared:footer',
                            'story_goal' => 'Build a reusable footer.',
                            'content_fill_rule' => 'Organize policy, support, and trust links.',
                            'stage3_directive' => 'Implement the footer component.',
                            'field_content_requirements' => [
                                ['field' => 'information_groups', 'sample' => 'Quick links, Policies, Support', 'reason' => 'Make footer navigation useful'],
                            ],
                        ],
                    ],
                ],
                'page_tasks' => [
                    'home_page' => [
                        [
                            'task_key' => 'page:home_page:content/home-page-hero',
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
                            'block_task' => [
                                'schema_version' => 'stage2-block-task-v1',
                                'task_goal' => 'Open with a clear value proposition.',
                                'meta_fields' => [
                                    [
                                        'field' => 'title',
                                        'type' => 'string',
                                        'default' => 'Grow faster with our service',
                                        'sample' => 'Grow faster with our service',
                                        'reason' => 'Lead with value',
                                    ],
                                ],
                                'content_plan' => [
                                    'story_goal' => 'Make the hero conversion-ready.',
                                    'content_fill_rule' => 'Use short headline and one CTA.',
                                    'content_copy' => [
                                        ['field' => 'title', 'copy' => 'Hero title'],
                                    ],
                                    'cta_plan' => [
                                        ['label' => 'Start now', 'href' => '#contact'],
                                    ],
                                    'link_plan' => [
                                        ['label' => 'Contact', 'href' => '#contact'],
                                    ],
                                    'asset_plan' => [
                                        ['slot' => 'primary_visual', 'description' => 'Hero visual'],
                                    ],
                                ],
                                'style_plan' => [
                                    'color' => 'Use Ocean Slate blue accents on CTA and proof highlights.',
                                    'font' => 'Use the confirmed heading scale with bold hero headline.',
                                    'spacing' => 'Use generous hero padding and clear CTA gaps.',
                                    'responsive' => 'Stack hero copy and visual on mobile.',
                                ],
                                'planning_reason' => 'Hero should translate the main value into first-screen conversion intent.',
                                'sort_order' => 100,
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
    private function buildMinimalConfirmedPlanBook(): array
    {
        return [
            'source' => 'stage1.block_tree',
            'source_signature' => 'phase-one-signature',
            'context_hash' => 'confirmed-plan-book-hash',
            'plan_locale' => 'en_US',
            'content_locale' => 'en_US',
            'theme_context_hash' => 'stage1-theme-hash',
            'shared_context_hash' => 'stage1-shared-hash',
            'theme_design' => [
                'theme_purpose' => 'Create a trustworthy conversion-focused website.',
                'color_scheme' => ['primary' => '#0f172a', 'accent' => '#f59e0b'],
                'typography_spacing_radius' => ['font_family' => 'Inter', 'spacing_scale' => 'generous'],
                'visual_keywords' => ['trustworthy', 'conversion'],
            ],
            'shared_blocks' => [
                [
                    'task_key' => 'shared:header',
                    'block_key' => 'shared:header',
                    'component' => 'header',
                    'sort_order' => 10,
                    'title' => 'Header',
                    'goal' => 'Build a reusable header with primary navigation.',
                    'implementation_detail' => 'Use brand name, nav links, and one primary CTA.',
                    'realtime_content' => [
                        'headline' => 'Task Plan Test',
                        'cta' => [['label' => 'Start now', 'target' => '#start']],
                    ],
                    'editable_fields' => ['brand_name', 'navigation_items', 'primary_cta'],
                    'field_plan' => [
                        ['field' => 'brand_name', 'sample' => 'Task Plan Test', 'reason' => 'Brand identification'],
                        ['field' => 'primary_cta', 'sample' => 'Start now', 'reason' => 'Main action'],
                    ],
                    'style_direction' => 'Trustworthy shared navigation.',
                    'responsive_rule' => 'Collapse nav on mobile.',
                    'completion_rule' => 'Header is reusable across pages.',
                    'context_hash' => 'stage1-header-hash',
                ],
                [
                    'task_key' => 'shared:footer',
                    'block_key' => 'shared:footer',
                    'component' => 'footer',
                    'sort_order' => 20,
                    'title' => 'Footer',
                    'goal' => 'Build a reusable footer with policies.',
                    'implementation_detail' => 'Use policy links and support copy.',
                    'realtime_content' => [
                        'headline' => 'Task Plan Test support',
                        'cta' => [['label' => 'Contact us', 'target' => '#contact']],
                    ],
                    'editable_fields' => ['footer_links', 'policy_links'],
                    'field_plan' => [
                        ['field' => 'footer_links', 'sample' => 'About / Contact', 'reason' => 'Footer navigation'],
                        ['field' => 'policy_links', 'sample' => 'Privacy / Terms', 'reason' => 'Compliance links'],
                    ],
                    'style_direction' => 'Trustworthy footer.',
                    'responsive_rule' => 'Stack footer links on mobile.',
                    'completion_rule' => 'Footer is reusable across pages.',
                    'context_hash' => 'stage1-footer-hash',
                ],
            ],
            'pages' => [
                'home_page' => [
                    'page_key' => 'home_page',
                    'page_label' => 'Home',
                    'page_goal' => 'Explain value',
                    'shared_context_hash' => 'stage1-shared-hash',
                    'theme_context_hash' => 'stage1-theme-hash',
                    'page_context_hash' => 'stage1-home-hash',
                    'blocks' => [
                        [
                            'task_key' => 'page:home_page:content/home-page-hero',
                            'block_key' => 'page:home_page:content/home-page-hero',
                            'source_block_key' => 'content/home-page-hero',
                            'component_kind' => 'hero',
                            'sort_order' => 100,
                            'title' => 'Hero',
                            'goal' => 'Open with a clear value proposition.',
                            'implementation_detail' => 'Build hero layout with headline, proof, and CTA.',
                            'realtime_content' => [
                                'headline' => 'Hero title',
                                'supporting_copy' => ['Launch faster with a focused hero message.'],
                                'cta' => [['label' => 'Start now', 'target' => '#start']],
                                'media' => [['kind' => 'image', 'rule' => 'SVG hero visual matching Ocean Slate.']],
                            ],
                            'reason' => 'Hero should translate the main value into first-screen conversion intent.',
                            'completion_rule' => 'Hero can be generated without guessing copy or style.',
                            'editable_fields' => ['title', 'description', 'button_text', 'button_link', 'image'],
                            'field_plan' => [
                                ['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer'],
                                ['field' => 'description', 'sample' => 'Launch faster with a focused hero message.', 'reason' => 'Clarify value'],
                                ['field' => 'button_text', 'sample' => 'Start now', 'reason' => 'Main action'],
                            ],
                            'design_tags' => ['visual' => ['premium banner'], 'motion' => ['5s fade in/out'], 'interaction' => ['CTA hover'], 'texture' => ['soft gradient'], 'responsive' => ['mobile stacked'], 'implementation_note' => 'Carry tags forward.'],
                            'style_direction' => 'Use confirmed style direction.',
                            'responsive_rule' => 'Stack hero copy and visual on mobile.',
                            'context_hash' => 'stage1-hero-hash',
                        ],
                    ],
                ],
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
                        'design_tags' => ['visual' => ['premium banner'], 'motion' => ['5s fade in/out'], 'interaction' => ['CTA hover'], 'texture' => ['soft gradient'], 'responsive' => ['mobile stacked'], 'implementation_note' => 'Carry tags forward.'],
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
            'plan_workbench' => [
                'stage1' => [
                    'theme_context_snapshot' => [
                        'context_hash' => 'stage1-theme-hash',
                        'palette' => ['name' => 'Ocean Slate', 'primary' => '#0f172a', 'accent' => '#f59e0b'],
                        'theme_style' => ['name' => 'Plan-Driven Hybrid', 'visual_tone' => 'Trustworthy'],
                    ],
                ],
                'confirmed' => [
                    'shared_prompt_context' => [
                        'context_hash' => 'stage1-shared-hash',
                        'theme_context_hash' => 'stage1-theme-hash',
                        'generation_rule' => 'shared first',
                    ],
                    'structured_plan' => [
                        'site_strategy' => ['site_display_name' => 'Task Plan Test', 'summary' => 'Summary'],
                        'palette' => ['name' => 'Ocean Slate'],
                        'theme_style' => ['name' => 'Plan-Driven Hybrid', 'responsive_rule' => 'Single column first'],
                        'seo_strategy' => ['core_intent' => 'intent'],
                        'navigation_plan' => ['header_items' => [['label' => 'Home', 'href' => '/'], ['label' => 'About', 'href' => '/about']]],
                        'footer_plan' => ['featured' => [['label' => 'About', 'href' => '/about']], 'policies' => [['label' => 'Privacy', 'href' => '/privacy']]],
                    ],
                    'plan_book' => [
                        'structured' => [
                            'source' => 'stage1.block_tree',
                            'source_signature' => 'phase-one-signature',
                            'context_hash' => 'confirmed-plan-book-hash',
                            'plan_locale' => 'en_US',
                            'content_locale' => 'en_US',
                            'theme_context_hash' => 'stage1-theme-hash',
                            'shared_context_hash' => 'stage1-shared-hash',
                            'theme_design' => [
                                'theme_purpose' => 'Create a trustworthy conversion-focused website.',
                                'color_scheme' => ['primary' => '#0f172a', 'accent' => '#f59e0b'],
                                'typography_spacing_radius' => ['font_family' => 'Inter', 'spacing_scale' => 'generous'],
                                'visual_keywords' => ['trustworthy', 'conversion'],
                            ],
                            'shared_blocks' => [
                                [
                                    'task_key' => 'shared:header',
                                    'block_key' => 'shared:header',
                                    'component' => 'header',
                                    'sort_order' => 10,
                                    'title' => 'Header',
                                    'goal' => 'Build a reusable header with primary navigation.',
                                    'implementation_detail' => 'Use brand name, nav links, and one primary CTA.',
                                    'realtime_content' => [
                                        'headline' => 'Task Plan Test',
                                        'cta' => [['label' => 'Start now', 'target' => '#start']],
                                    ],
                                    'editable_fields' => ['brand_name', 'navigation_items', 'primary_cta'],
                                    'field_plan' => [
                                        ['field' => 'brand_name', 'sample' => 'Task Plan Test', 'reason' => 'Brand identification'],
                                        ['field' => 'primary_cta', 'sample' => 'Start now', 'reason' => 'Main action'],
                                    ],
                                    'style_direction' => 'Trustworthy shared navigation.',
                                    'responsive_rule' => 'Collapse nav on mobile.',
                                    'completion_rule' => 'Header is reusable across pages.',
                                    'context_hash' => 'stage1-header-hash',
                                ],
                                [
                                    'task_key' => 'shared:footer',
                                    'block_key' => 'shared:footer',
                                    'component' => 'footer',
                                    'sort_order' => 20,
                                    'title' => 'Footer',
                                    'goal' => 'Build a reusable footer with policies.',
                                    'implementation_detail' => 'Use policy links and support copy.',
                                    'realtime_content' => [
                                        'headline' => 'Task Plan Test support',
                                        'cta' => [['label' => 'Contact us', 'target' => '#contact']],
                                    ],
                                    'editable_fields' => ['footer_links', 'policy_links'],
                                    'field_plan' => [
                                        ['field' => 'footer_links', 'sample' => 'About / Contact', 'reason' => 'Footer navigation'],
                                        ['field' => 'policy_links', 'sample' => 'Privacy / Terms', 'reason' => 'Compliance links'],
                                    ],
                                    'style_direction' => 'Trustworthy footer.',
                                    'responsive_rule' => 'Stack footer links on mobile.',
                                    'completion_rule' => 'Footer is reusable across pages.',
                                    'context_hash' => 'stage1-footer-hash',
                                ],
                            ],
                            'pages' => [
                                'home_page' => [
                                    'page_key' => 'home_page',
                                    'page_label' => 'Home',
                                    'page_goal' => 'Explain value',
                                    'page_design_plan' => [
                                        'page_role' => 'conversion entry page',
                                        'color_layering' => 'hero panel over slate base, proof cards on light surface, ember CTA accent',
                                        'section_flow' => ['hero impact', 'proof layer', 'final CTA'],
                                        'interaction_notes' => ['CTA hover glow', 'card lift on proof'],
                                    ],
                                    'shared_context_hash' => 'stage1-shared-hash',
                                    'theme_context_hash' => 'stage1-theme-hash',
                                    'page_context_hash' => 'stage1-home-hash',
                                    'blocks' => [
                                        [
                                            'task_key' => 'page:home_page:content/home-page-hero',
                                            'block_key' => 'page:home_page:content/home-page-hero',
                                            'source_block_key' => 'content/home-page-hero',
                                            'component_kind' => 'hero',
                                            'sort_order' => 100,
                                            'title' => 'Hero',
                                            'goal' => 'Open with a clear value proposition.',
                                            'implementation_detail' => 'Build hero layout with headline, proof, and CTA.',
                                            'realtime_content' => [
                                                'headline' => 'Hero title',
                                                'supporting_copy' => ['Launch faster with a focused hero message.'],
                                                'cta' => [['label' => 'Start now', 'target' => '#start']],
                                                'media' => [['kind' => 'image', 'rule' => 'SVG hero visual matching Ocean Slate.']],
                                            ],
                                            'reason' => 'Hero should translate the main value into first-screen conversion intent.',
                                            'completion_rule' => 'Hero can be generated without guessing copy or style.',
                                            'editable_fields' => ['title', 'description', 'button_text', 'button_link', 'image'],
                                            'field_plan' => [
                                                ['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain the offer'],
                                                ['field' => 'description', 'sample' => 'Launch faster with a focused hero message.', 'reason' => 'Clarify value'],
                                                ['field' => 'button_text', 'sample' => 'Start now', 'reason' => 'Main action'],
                                            ],
                                            'design_tags' => ['visual' => ['premium banner'], 'motion' => ['5s fade in/out'], 'interaction' => ['CTA hover'], 'texture' => ['soft gradient'], 'responsive' => ['mobile stacked'], 'implementation_note' => 'Carry tags forward.'],
                                            'style_direction' => 'Use confirmed style direction.',
                                            'responsive_rule' => 'Stack hero copy and visual on mobile.',
                                            'context_hash' => 'stage1-hero-hash',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
