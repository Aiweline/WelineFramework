<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use PHPUnit\Framework\TestCase;

class AiSiteBuildTaskServiceTest extends TestCase
{
    public function testEnsureTaskScopeBuildsSharedAndPageTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page', 'about_page']), [], 'virtual_theme');

        $buildPlan = $scope['build_plan_v2'] ?? [];
        $this->assertIsArray($buildPlan);
        $this->assertSame(
            ['shared:header', 'shared:footer', 'page:home_page:content/home-page-hero', 'page:about_page:content/about-page-hero'],
            $this->buildPlanExecutionTaskKeys($buildPlan)
        );
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $buildPlan['shared_execution']['header']['status'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $buildPlan['shared_execution']['footer']['status'] ?? null);
        $this->assertNotSame(
            [],
            $this->findBuildPlanBlockByExecutionTaskKey($buildPlan, 'page:home_page:content/home-page-hero')
        );
    }

    public function testBuildScopePatchCannotMutatePlanContractBeforeBuild(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $patch = $service->stripBuildPlanMutationScopePatch([
            'site_title' => 'Keep editable profile field',
            'build_plan_confirmed' => 1,
            'plan_projection' => ['page_types' => ['home_page']],
            'content_manifest' => ['items' => []],
            'build_plan_v2' => [
                'shared_execution' => [
                    'header' => ['status' => AiSiteBuildTaskService::TASK_STATUS_PENDING],
                ],
                'blocks' => [
                    [
                        'block_id' => 'home_page.hero',
                        'execution' => ['status' => AiSiteBuildTaskService::TASK_STATUS_PENDING],
                    ],
                ],
            ],
        ], []);

        $this->assertSame(['site_title' => 'Keep editable profile field'], $patch);
    }

    public function testPromptTraceGuardDoesNotTreatMetricFallbackAsNumericTagLeak(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $payload = <<<'JSON'
{"php_variables":"$fastResponse = $getConfig('metric.fast_response', '<2s', ENT_QUOTES, 'UTF-8');\n?>"}
JSON;

        $hasLeak = (function (string $payload): bool {
            return $this->containsGeneratedArtifactVisibleHtmlLeak($payload);
        })->call($service, $payload);

        self::assertFalse($hasLeak);
    }

    public function testPromptTraceGuardStillRejectsStandaloneNumericTags(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $hasLeak = (function (string $payload): bool {
            return $this->containsGeneratedArtifactVisibleHtmlLeak($payload);
        })->call($service, '{"html_content":"<section><1div>Broken</1div></section>"}');

        self::assertTrue($hasLeak);
    }

    public function testSharedComponentsRefreshPageTypeLayoutHeaderAndFooterConfigs(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = [
            'shared_components' => [
                'header' => [
                    'code' => 'header/ai-site-header',
                    'default_config' => [
                        'navigation.items' => "首页=>/",
                        'cta.text' => '立即开始',
                    ],
                ],
                'footer' => [
                    'code' => 'footer/ai-site-footer',
                    'default_config' => [
                        'links.column1_title' => '重点页面',
                        'copyright.text' => '保留所有权利。',
                    ],
                ],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'header' => [
                        'component' => 'header/ai-site-header',
                        'config' => [
                            'navigation.items' => 'Home=>/',
                            'cta.text' => 'Download Now',
                        ],
                    ],
                    'footer' => [
                        'component' => 'footer/ai-site-footer',
                        'config' => [
                            'links.column1_title' => 'Featured Pages',
                            'copyright.text' => 'All rights reserved.',
                        ],
                    ],
                    'content' => [],
                ],
            ],
        ];

        $result = (function (array $scope): array {
            return $this->syncPageTypeLayoutsWithSharedComponents($scope);
        })->call($service, $scope);

        $homeLayout = $result['page_type_layouts']['home_page'] ?? [];
        self::assertSame('立即开始', $homeLayout['header']['config']['cta.text'] ?? null);
        self::assertSame("首页=>/", $homeLayout['header']['config']['navigation.items'] ?? null);
        self::assertSame('重点页面', $homeLayout['footer']['config']['links.column1_title'] ?? null);
        self::assertSame('保留所有权利。', $homeLayout['footer']['config']['copyright.text'] ?? null);
    }

    public function testEnsureTaskScopeUsesConfirmedBuildPlanV2ContractWhenAvailable(): void
    {
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'page_types' => ['home_page'],
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Convert qualified buyers with clear proof.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                                'page_flow_role' => 'opening_conversions',
                                'visual_signature' => $this->buildStageOneVisualSignature('hero'),
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'A concise proof-led section helps visitors understand the next step.'],
                                    ['field' => 'cta', 'sample' => 'Schedule the AI workflow review'],
                                ],
                            ],
                            [
                                'block_key' => 'trust',
                                'title' => 'Proof that operations stay reliable',
                                'goal' => 'Show concrete evidence before conversion.',
                                'page_flow_role' => 'proof_builder',
                                'visual_signature' => $this->buildStageOneVisualSignature('trust'),
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Reliable operations are supported by clear proof and next steps.'],
                                    ['field' => 'cta', 'sample' => 'Review reliability evidence'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
        ], [], 'virtual_theme');

        $normalizedPlan = $scope['build_plan_v2'] ?? [];
        $this->assertIsArray($normalizedPlan);
        $this->assertSame($buildPlan['contract_meta']['id'], $normalizedPlan['contract_meta']['id'] ?? null);
        $this->assertSame($buildPlan['contract_meta']['signature'], $normalizedPlan['contract_meta']['signature'] ?? null);
        $this->assertSame(
            ['shared:header', 'shared:footer', 'page:home_page:content/home-page-hero', 'page:home_page:content/home-page-trust'],
            $this->buildPlanExecutionTaskKeys($normalizedPlan)
        );
        $pageBlock = $this->findBuildPlanBlockByExecutionTaskKey($normalizedPlan, 'page:home_page:content/home-page-hero');
        $this->assertNotSame([], $pageBlock);
        $this->assertSame(
            ['task_key', 'status', 'attempt_no', 'message', 'result_ref', 'updated_at', 'started_at', 'finished_at'],
            \array_keys($pageBlock['execution'] ?? [])
        );

        $pageTaskDefinition = $service->getTaskDefinition($scope, 'page:home_page:content/home-page-hero');
        $this->assertIsArray($pageTaskDefinition);
        $this->assertSame('content/home-page-hero', $pageTaskDefinition['section_code'] ?? null);
        $this->assertSame('home_page', $pageTaskDefinition['page_type'] ?? null);
        $this->assertSame(['shared:header', 'shared:footer'], $pageTaskDefinition['dependencies'] ?? []);
        $this->assertSame(
            $buildPlan['contract_meta']['id'],
            $pageTaskDefinition['implementation_contract']['contract_id'] ?? null
        );
    }

    public function testBuildPlanV2StoresExecutionRowsAndInflatesTaskDefinitionOnRead(): void
    {
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'page_types' => ['home_page'],
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Convert qualified buyers with clear proof.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                                'page_flow_role' => 'opening_conversions',
                                'visual_signature' => $this->buildStageOneVisualSignature('hero'),
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'A concise proof-led section helps visitors understand the next step.'],
                                ],
                            ],
                            [
                                'block_key' => 'trust',
                                'title' => 'Proof that operations stay reliable',
                                'goal' => 'Show concrete evidence before conversion.',
                                'page_flow_role' => 'proof_builder',
                                'visual_signature' => $this->buildStageOneVisualSignature('trust'),
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Reliable operations are supported by clear proof and next steps.'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
        ], [], 'virtual_theme');

        $buildPlan = $scope['build_plan_v2'] ?? [];
        self::assertIsArray($buildPlan);
        self::assertIsArray($buildPlan['shared_execution']['header'] ?? null);

        $rawPageBlock = $this->findBuildPlanBlockByExecutionTaskKey($buildPlan, 'page:home_page:content/home-page-hero');
        self::assertNotSame([], $rawPageBlock);
        self::assertArrayNotHasKey('runtime_context', $rawPageBlock);
        self::assertArrayNotHasKey('plan_context', $rawPageBlock);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $rawPageBlock['execution']['status'] ?? null);

        $definition = $service->getTaskDefinition($scope, 'page:home_page:content/home-page-hero');
        self::assertIsArray($definition);
        self::assertIsArray($definition['runtime_context']['theme_context_snapshot'] ?? null);
        self::assertIsArray($definition['runtime_context']['shared_prompt_context'] ?? null);
        self::assertArrayNotHasKey('runtime_context', $definition['plan_context']['task'] ?? []);
        self::assertArrayHasKey('task_id', $definition['plan_context']['task'] ?? []);
        self::assertArrayHasKey('input_scope', $definition['plan_context']['task'] ?? []);
    }

    public function testEnsureTaskScopeUsesConfirmedBuildPlanV2BeforeLegacyTaskPlan(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope(\array_replace($this->buildConfirmedScope(['home_page']), [
            'task_plan_confirmed' => 0,
        ]), [], 'virtual_theme');

        $buildPlan = $scope['build_plan_v2'] ?? [];
        $this->assertIsArray($buildPlan);
        $this->assertSame(1, $scope['build_plan_confirmed'] ?? 0);
        $this->assertArrayHasKey('header', $buildPlan['shared_execution'] ?? []);
        $this->assertNotSame(
            [],
            $this->findBuildPlanBlockByExecutionTaskKey($buildPlan, 'page:home_page:content/home-page-hero')
        );
    }

    public function testEnsureTaskScopeIgnoresLegacyTaskPlanContractsWhenBuildPlanV2IsMissing(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'legacy-should-not-win',
                    'shared_tasks' => [
                        ['task_key' => 'shared:legacy-only', 'sort_order' => 10],
                    ],
                ],
            ],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertSame(0, (int)($scope['build_plan_confirmed'] ?? 0));
        $this->assertSame([], $this->buildPlanExecutionTaskKeys($scope['build_plan_v2'] ?? []));
        $this->assertStringContainsString(
            'confirmed build_plan_v2 is required before build',
            \implode("\n", $scope['build_plan_v2_validation']['errors'] ?? [])
        );
    }

    public function testHasConfirmedBuildPlanForBuildIgnoresLegacyTaskPlanOnlyScope(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $this->assertFalse($service->hasConfirmedBuildPlanForBuild([
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'legacy-confirmed-signature',
                    'page_tasks' => [
                        'home_page' => [['task_key' => 'page:home_page:hero']],
                    ],
                ],
            ],
        ]));
    }

    public function testHasConfirmedBuildPlanForBuildRejectsMissingBuildPlanV2(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $this->assertFalse($service->hasConfirmedBuildPlanForBuild([
            'plan_confirmed' => 1,
            'page_types' => ['home_page'],
        ]));
    }

    public function testNormalizeConfirmedBuildPlanFlagRepairsStaleBuildPlanConfirmedFlag(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope(\array_replace($this->buildConfirmedScope(['home_page']), [
            'build_plan_confirmed' => 0,
        ]), [], 'virtual_theme');

        $this->assertSame(1, (int)($scope['build_plan_confirmed'] ?? 0));
        $buildPlan = $scope['build_plan_v2'] ?? [];
        $this->assertIsArray($buildPlan);
        $this->assertNotSame(
            [],
            $this->findBuildPlanBlockByExecutionTaskKey($buildPlan, 'page:home_page:content/home-page-hero')
        );
    }

    public function testEnsureTaskScopeUsesFullBuildPlanV2PageGraph(): void
    {
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope([
            'page_types' => ['home_page', 'about_page', 'contact_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'page_types' => ['home_page', 'about_page', 'contact_page'],
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Convert qualified buyers.',
                        'blocks' => [
                            $this->buildStageOneBlock('hero', 'Clear home hero', 'Open with value.', 'A focused opening explains the offer clearly.', 'Request the home offer review'),
                            $this->buildStageOneBlock('trust', 'Reliable proof', 'Build trust.', 'Proof points help visitors evaluate the offer.', 'Review the buyer proof'),
                            $this->buildStageOneBlock('cta', 'Next step', 'Drive action.', 'A direct call to action moves qualified visitors forward.', 'Plan the next step'),
                        ],
                    ],
                    'about_page' => [
                        'title' => 'About',
                        'page_goal' => 'Explain the company story.',
                        'blocks' => [
                            $this->buildStageOneBlock('story', 'Company story', 'Explain the company.', 'The story clarifies why the team can deliver.', 'Read the delivery story'),
                            $this->buildStageOneBlock('team', 'Team proof', 'Show credibility.', 'Team details support trust and credibility.', 'Meet the delivery team'),
                        ],
                    ],
                    'contact_page' => [
                        'title' => 'Contact',
                        'page_goal' => 'Make contact easy.',
                        'blocks' => [
                            $this->buildStageOneBlock('form', 'Contact form', 'Make contact easy.', 'Simple contact guidance helps visitors reach the team.', 'Send the project details'),
                            $this->buildStageOneBlock('faq', 'Common questions', 'Reduce hesitation.', 'Clear answers remove friction before contact.', 'Review contact answers'),
                        ],
                    ],
                ],
            ],
        ]));
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page', 'contact_page'],
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
        ], [], 'virtual_theme');

        $this->assertSame(
            [
                'shared:header',
                'shared:footer',
                'page:home_page:content/home-page-hero',
                'page:home_page:content/home-page-trust',
                'page:home_page:content/home-page-cta',
                'page:about_page:content/about-page-story',
                'page:about_page:content/about-page-team',
                'page:contact_page:content/contact-page-form',
                'page:contact_page:content/contact-page-faq',
            ],
            $this->buildPlanExecutionTaskKeys($scope['build_plan_v2'] ?? [])
        );
        $this->assertSame(['home_page', 'about_page', 'contact_page'], $this->buildPlanPageTypes($scope['build_plan_v2'] ?? []));
        $this->assertCount(9, $this->buildPlanExecutionTaskKeys($scope['build_plan_v2'] ?? []));
    }

    public function testEnsureTaskScopeRejectsMissingBuildPlanV2Contract(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
            'plan_confirmed' => 1,
        ], [], 'virtual_theme');

        self::assertSame([], $this->buildPlanExecutionTaskKeys($scope['build_plan_v2'] ?? []));
        self::assertSame(0, (int)($scope['build_plan_confirmed'] ?? 0));
        self::assertStringContainsString('build_plan_v2', \implode("\n", $scope['build_plan_v2_validation']['errors'] ?? []));
    }

    public function testFreshRepairResetsAttemptCountForQualityRetry(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
            'attempt_no' => 3,
            'message' => 'old failure',
            'result_ref' => ['page_type' => 'home_page'],
            'started_at' => '2026-01-01 00:00:00',
            'finished_at' => '2026-01-01 00:01:00',
        ]);

        $scope = $service->markTaskPendingForFreshRepair($scope, $taskKey, 'Quality gate retry');
        $state = $this->buildPlanTaskState($scope, $taskKey);

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $state['status']);
        self::assertSame(0, $state['attempt_no']);
        self::assertSame('Quality gate retry', $state['message']);
        self::assertSame([], $state['result_ref']);
        self::assertSame('', $state['started_at']);
        self::assertSame('', $state['finished_at']);
    }

    public function testFreshRepairResetOnlyTouchesFailedBlueprintTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $homeTaskKey = 'page:home_page:content/home-page-hero';
        $aboutTaskKey = 'page:about_page:content/about-page-story';
        $contactTaskKey = 'page:contact_page:content/contact-page-form';
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'home_page' => ['hero'],
            'about_page' => ['story'],
            'contact_page' => ['form'],
        ]), [], 'virtual_theme');
        $scope = $this->withBuildPlanTaskState($scope, $homeTaskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
            'attempt_no' => 3,
            'message' => 'old failure',
            'result_ref' => ['page_type' => 'home_page'],
            'started_at' => '2026-01-01 00:00:00',
            'finished_at' => '2026-01-01 00:01:00',
        ]);
        $scope = $this->withBuildPlanTaskState($scope, $aboutTaskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            'attempt_no' => 1,
            'message' => '',
            'result_ref' => ['page_type' => 'about_page'],
        ]);
        $scope = \array_replace_recursive($scope, [
            'retryable_ai_failures' => [
                'build' => [
                    'items' => [
                        $contactTaskKey => [
                            'operation' => 'build',
                            'item_key' => $contactTaskKey,
                            'item_type' => 'page_section',
                            'retry_scope' => 'build_task',
                            'message' => 'ledger-only failure',
                        ],
                        'orphan:ledger' => [
                            'operation' => 'build',
                            'item_key' => 'orphan:ledger',
                            'item_type' => 'page_section',
                            'retry_scope' => 'build_task',
                            'message' => 'orphan ledger failure',
                        ],
                    ],
                    'updated_at' => '2026-01-01 00:02:00',
                ],
            ],
        ]);

        $scope = $service->resetFailedTasksForFreshRepair($scope, 'Fresh build repair');
        $homeState = $this->buildPlanTaskState($scope, $homeTaskKey);
        $aboutState = $this->buildPlanTaskState($scope, $aboutTaskKey);
        $contactState = $this->buildPlanTaskState($scope, $contactTaskKey);

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $homeState['status']);
        self::assertSame(0, $homeState['attempt_no']);
        self::assertSame([], $homeState['result_ref']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $aboutState['status']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $contactState['status']);
        self::assertSame(0, $contactState['attempt_no']);
        self::assertSame([], $contactState['result_ref']);
        self::assertSame('Fresh build repair', $contactState['message']);
        self::assertSame([], $scope['retryable_ai_failures'] ?? []);
        self::assertSame(0, (int)($scope['retryable_ai_failure_count'] ?? 0));
    }

    public function testInterruptedBuildResetOnlyTouchesRunningBlueprintTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $contactTaskKey = 'page:contact_page:content/contact-page-faq';
        $aboutTaskKey = 'page:about_page:content/about-page-story';
        $homeTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'contact_page' => ['faq'],
            'about_page' => ['story'],
            'home_page' => ['hero'],
        ]), [], 'virtual_theme');
        $scope = $this->withBuildPlanTaskState($scope, $contactTaskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
            'attempt_no' => 2,
            'message' => 'generating',
            'result_ref' => ['page_type' => 'contact_page'],
            'started_at' => '2026-01-01 00:00:00',
            'finished_at' => '',
        ]);
        $scope = $this->withBuildPlanTaskState($scope, $aboutTaskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            'attempt_no' => 1,
            'message' => '',
            'result_ref' => ['page_type' => 'about_page'],
        ]);
        $scope = $this->withBuildPlanTaskState($scope, $homeTaskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
            'attempt_no' => 3,
            'message' => 'quality failure',
        ]);

        $scope = $service->resetRunningTasksForInterruptedBuild($scope, 'Provider interrupted build');
        $contactState = $this->buildPlanTaskState($scope, $contactTaskKey);

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $contactState['status']);
        self::assertSame(0, $contactState['attempt_no']);
        self::assertSame('Provider interrupted build', $contactState['message']);
        self::assertSame([], $contactState['result_ref']);
        self::assertSame('', $contactState['started_at']);
        self::assertSame('', $contactState['finished_at']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $this->buildPlanTaskState($scope, $aboutTaskKey)['status']);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_FAILED, $this->buildPlanTaskState($scope, $homeTaskKey)['status']);
    }

    public function testSyncBuildTaskFailuresKeepsRetryEntryForLatestBuildFailureWithoutBlueprintTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'latest_build_failed' => 1,
            'publish_blocked_by_latest_ai_failure' => 1,
            'publish_blocked_reason' => 'Home hero generation failed.',
            'latest_build_failure' => [
                'blocked' => true,
                'operation' => 'build',
                'status' => 'error',
                'message' => 'Home hero generation failed.',
                'page_type' => 'home_page',
                'section_code' => 'hero',
            ],
            'retryable_ai_failures' => [],
        ];

        $scope = $service->syncBuildTaskFailuresToRetryableLedger($scope);

        self::assertSame(1, (int)($scope['retryable_ai_failure_count'] ?? 0));
        self::assertArrayHasKey('build', $scope['retryable_ai_failures']);
        self::assertArrayHasKey('home_page', $scope['retryable_ai_failures']['build']['items']);
        self::assertSame(
            'Home hero generation failed.',
            $scope['retryable_ai_failures']['build']['items']['home_page']['message'] ?? ''
        );
    }

    public function testSyncBuildTaskFailuresDoesNotClearPublishBlockingWhenCompletionGateFails(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'page_types' => ['home_page', 'about_page'],
            'page_type_layouts' => [
                'home_page' => ['blocks' => [['block_key' => 'hero']]],
                'about_page' => ['blocks' => [['block_key' => 'intro']]],
            ],
            'build_plan_confirmed' => 1,
            'build_plan_v2' => [
                'contract_meta' => [
                    'status' => 'confirmed',
                    'signature' => 'completion-gate-fails',
                ],
                'pages' => [
                    [
                        'page_id' => 'home_page',
                        'page_type' => 'home_page',
                    ],
                ],
                'blocks' => [
                    [
                        'block_id' => 'home_page.hero',
                        'page_id' => 'home_page',
                        'page_type' => 'home_page',
                        'section_key' => 'hero',
                        'execution' => [
                            'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                            'updated_at' => '2026-05-11 00:00:00',
                            'finished_at' => '2026-05-11 00:00:00',
                        ],
                    ],
                ],
                'shared_execution' => [
                    'header' => [
                        'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                        'updated_at' => '2026-05-11 00:00:00',
                        'finished_at' => '2026-05-11 00:00:00',
                    ],
                    'footer' => [
                        'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                        'updated_at' => '2026-05-11 00:00:00',
                        'finished_at' => '2026-05-11 00:00:00',
                    ],
                ],
            ],
            'latest_build_failed' => 1,
            'publish_blocked_by_latest_ai_failure' => 1,
            'publish_blocked_reason' => 'missing page types',
            'latest_build_failure' => [
                'blocked' => true,
                'operation' => 'build',
                'status' => 'error',
                'message' => 'missing page types',
            ],
            'retryable_ai_failures' => [],
        ];

        $scope = $service->syncBuildTaskFailuresToRetryableLedger($scope);

        self::assertSame(1, (int)($scope['latest_build_failed'] ?? 0));
        self::assertSame(1, (int)($scope['retryable_ai_failure_count'] ?? 0));
        self::assertArrayHasKey('build', $scope['retryable_ai_failures']);
    }

    public function testEnsureTaskScopeRejectsScopeWithoutConfirmedBuildPlanV2(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'build_plan_confirmed' => 1,
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertFalse($service->hasConfirmedBuildPlanForBuild($scope));
        $this->assertSame(0, (int)($scope['build_plan_confirmed'] ?? 0));
        $this->assertSame([], $this->buildPlanExecutionTaskKeys($scope['build_plan_v2'] ?? []));
        $this->assertStringContainsString('build_plan_v2', \implode("\n", $scope['build_plan_v2_validation']['errors'] ?? []));
    }

    public function testSummarizeReflectsDoneAndPendingTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'html_blocks');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $summary = $service->summarize($scope);

        $this->assertGreaterThan(0, $summary['total']);
        $this->assertSame(1, $summary['done']);
        $this->assertGreaterThan(0, $summary['pending']);
        $this->assertArrayHasKey('shared', $summary['groups']);
        $this->assertIsArray($summary['groups']['shared']['tasks'] ?? null);
        $this->assertNotEmpty($summary['groups']['shared']['tasks'] ?? []);
    }

    public function testMarkTaskDoneClearsPriorRepairMessage(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'html_blocks');
        $scope = $this->withBuildPlanTaskState($scope, 'shared:header', [
            'message' => 'Previous completion gate failure.',
        ]);

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $state = $this->buildPlanTaskState($scope, 'shared:header');

        $this->assertSame('', $state['message'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $state['status'] ?? null);
    }

    public function testEnsureTaskScopeInitializesExpandedBuildPlanExecutionRows(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $homeTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskDone($scope, $homeTaskKey, [
            'page_type' => 'home_page',
            'section_code' => 'content/home-page-hero',
        ]);

        $expandedScope = \array_replace($scope, $this->buildConfirmedScope(['home_page', 'about_page']));
        $expandedScope = $service->ensureTaskScope($expandedScope, [], 'virtual_theme');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($expandedScope, 'shared:header')['status'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($expandedScope, $homeTaskKey)['status'] ?? null);
        $this->assertSame(
            AiSiteBuildTaskService::TASK_STATUS_PENDING,
            $this->buildPlanTaskState($expandedScope, 'page:about_page:content/about-page-hero')['status'] ?? null
        );
    }

    public function testPickConcurrentTasksKeepsSharedTasksExclusiveUntilSharedGateIsSatisfied(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page', 'about_page']), [], 'virtual_theme');

        $initial = $service->pickConcurrentTasks($scope, 3);
        $this->assertSame(['shared:header', 'shared:footer'], \array_column($initial, 'task_key'));

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $stillSharedOnly = $service->pickConcurrentTasks($scope, 3);
        $this->assertSame(['shared:footer'], \array_column($stillSharedOnly, 'task_key'));

        $scope = $this->withBuildPlanTaskState($scope, 'shared:footer', [
            'status' => AiSiteBuildTaskService::TASK_STATUS_CANCELLED,
        ]);
        $pageTasks = $service->pickConcurrentTasks($scope, 2);
        $pageTaskKeys = \array_column($pageTasks, 'task_key');

        $this->assertCount(2, $pageTasks);
        $this->assertContains('page:home_page:content/home-page-hero', $pageTaskKeys);
        $this->assertContains('page:about_page:content/about-page-hero', $pageTaskKeys);
    }

    public function testPickConcurrentTasksDefaultsToAllCurrentlySchedulableTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page', 'about_page']), [], 'virtual_theme');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);

        $picked = $service->pickConcurrentTasks($scope);
        $pickedKeys = \array_column($picked, 'task_key');

        $this->assertContains('page:home_page:content/home-page-hero', $pickedKeys);
        $this->assertContains('page:about_page:content/about-page-hero', $pickedKeys);
        $this->assertSame(2, \count($pickedKeys));
    }

    public function testPickConcurrentTasksHonorsNonParallelTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page', 'about_page']), [], 'virtual_theme');
        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);

        $picked = $service->pickConcurrentTasks($scope, 3);

        $this->assertSame(
            ['page:home_page:content/home-page-hero', 'page:about_page:content/about-page-hero'],
            \array_column($picked, 'task_key')
        );
    }

    public function testListPendingTasksSkipsCancelledAndRunningTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');

        $scope = $service->markTaskRunning($scope, 'shared:header');
        $scope = $this->withBuildPlanTaskState($scope, 'shared:footer', [
            'status' => AiSiteBuildTaskService::TASK_STATUS_CANCELLED,
        ]);

        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertNotContains('shared:footer', $pendingKeys);
        $this->assertTrue($service->hasPendingTasks($scope));
    }

    public function testRetryingTaskDoesNotExposeRawTechnicalFailureWhileRunning(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $scope = $service->markTaskRunning($scope, $taskKey);
        $scope = $service->markTaskPendingForRetry($scope, $taskKey, 'AI生成失败: OpenSSL SSL_read unexpected eof while reading');

        self::assertSame('Retrying generation in the current queue.', $this->buildPlanTaskState($scope, $taskKey)['message'] ?? null);

        $scope = $service->markTaskRunning($scope, $taskKey);
        $summary = $service->summarize($scope);

        self::assertSame('', $this->buildPlanTaskState($scope, $taskKey)['message'] ?? null);
        self::assertStringNotContainsString('OpenSSL', (string)($summary['groups']['home_page']['tasks'][0]['message'] ?? ''));
    }

    public function testFailedImageTaskDoesNotExposeProviderDiagnosticInSummaryOrRetryLedger(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';
        $rawMessage = 'REQUIRED_IMAGE_ASSET_UNRESOLVED: required image generation failed for slot page:home_page:content-home-page-hero: Inline block image generation failed for page:home_page:content-home-page-hero: VectorEngine API returned error (URL: https://api.vectorengine.cn/v1beta/models/gemini-3.1-flash-image-preview:generateContent, HTTP: 403): chat pre-consumed quota failed, user quota: $0.14, need quota: $0.39 (request id: abc123)';

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $scope = $service->markTaskRunning($scope, $taskKey);
        $scope = $service->markTaskFailed($scope, $taskKey, $rawMessage);
        $scope = $service->syncBuildTaskFailuresToRetryableLedger($scope);

        $summary = $service->summarize($scope);
        $summaryMessage = (string)($summary['groups']['home_page']['tasks'][0]['message'] ?? '');
        $ledgerMessage = (string)($scope['retryable_ai_failures']['build']['items'][$taskKey]['message'] ?? '');

        self::assertSame(
            'Image generation is temporarily unavailable. The section will need another generation attempt.',
            $summaryMessage
        );
        self::assertSame($summaryMessage, $ledgerMessage);
        foreach (['REQUIRED_IMAGE_ASSET_UNRESOLVED', 'VectorEngine', 'https://', 'HTTP: 403', 'request id', 'user quota', 'need quota'] as $needle) {
            self::assertStringNotContainsString($needle, $summaryMessage);
            self::assertStringNotContainsString($needle, $ledgerMessage);
        }
    }

    public function testRetryableLedgerNormalizationSanitizesRawProviderMessages(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $ledger = $service->getRetryableAiFailures([
            'retryable_ai_failures' => [
                'build' => [
                    'items' => [
                        'home' => [
                            'operation' => 'build',
                            'item_key' => 'home',
                            'message' => 'VectorEngine API returned error (URL: https://api.vectorengine.cn, HTTP: 403): request id xyz',
                            'error_message' => 'OpenSSL SSL_read unexpected eof while reading',
                        ],
                    ],
                ],
            ],
        ], 'build');

        $item = $ledger['build']['items']['home'] ?? [];

        self::assertSame(
            'Image generation is temporarily unavailable. The section will need another generation attempt.',
            $item['message'] ?? ''
        );
        self::assertSame(
            'AI generation timed out. The section will need another generation attempt.',
            $item['error_message'] ?? ''
        );
        self::assertStringNotContainsString('VectorEngine', (string)($item['message'] ?? ''));
        self::assertStringNotContainsString('https://', (string)($item['message'] ?? ''));
        self::assertStringNotContainsString('OpenSSL', (string)($item['error_message'] ?? ''));
    }

    public function testBuildResumeResetPreservesDoneTasksAndRetriesOnlyFailedOrInterruptedTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskRunning($scope, 'shared:footer');
        $scope = $service->markTaskFailed($scope, $pageTaskKey, 'AI generation failed.');
        $scope = $service->resetFailedTasksForFreshRepair($scope, 'Resume build after previous task failure');
        $scope = $service->resetRunningTasksForInterruptedBuild($scope, 'Resume build after interrupted task execution');
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $this->buildPlanTaskState($scope, 'shared:header')['status'] ?? null);
        $this->assertSame(['region' => 'header'], $this->buildPlanTaskState($scope, 'shared:header')['result_ref'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($scope, 'shared:footer')['status'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($scope, $pageTaskKey)['status'] ?? null);
        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertContains('shared:footer', $pendingKeys);
        $this->assertContains($pageTaskKey, $pendingKeys);
    }

    public function testReconcileGeneratedArtifactsMarksPersistedPendingTasksDoneBeforeDispatch(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->markTaskRunning($scope, 'shared:header');
        $scope = $service->markTaskRunning($scope, $pageTaskKey);
        $scope['shared_components']['header'] = [
            'region' => 'header',
            'code' => 'header/ai-site-header',
            'html' => '<header>Ready</header>',
        ];
        $scope['page_type_layouts']['home_page']['content'][] = [
            'code' => 'content/home-page-hero',
            'component' => 'content/home-page-hero',
        ];

        $scope = $service->reconcileGeneratedArtifactsWithTaskState($scope);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $this->buildPlanTaskState($scope, 'shared:header')['status'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_RUNNING, $this->buildPlanTaskState($scope, $pageTaskKey)['status'] ?? null);
        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertNotContains($pageTaskKey, $pendingKeys);
    }

    public function testReconcileGeneratedArtifactsUsesMatchedSectionAndAllowsCreativeDesignLanguage(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:about_page:content/about-page-mission-values';

        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'about_page' => ['mission-values'],
        ]), [], 'virtual_theme');
        $scope = \array_replace_recursive($scope, [
            'page_type_layouts' => [
                'about_page' => [
                    'content' => [
                        [
                            'code' => 'about-page-mission-values',
                            'component' => 'about-page-mission-values',
                            'html' => '<section><h2>Mission values</h2><p>Proof points, visual hierarchy, and player trust signals guide every table.</p></section>',
                        ],
                        [
                            'code' => 'content/about-page-internal-note',
                            'component' => 'content/about-page-internal-note',
                            'html' => '<section>Return ONLY JSON for this unrelated draft note.</section>',
                        ],
                    ],
                ],
            ],
        ]);

        $scope = $service->reconcileGeneratedArtifactsWithTaskState($scope);
        $state = $this->buildPlanTaskState($scope, $taskKey);

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $state['status'] ?? null);
        self::assertSame(
            ['page_type' => 'about_page', 'section_code' => 'content/about-page-mission-values'],
            $state['result_ref'] ?? null
        );
    }

    public function testForceRebuildResetPreservesPersistedGeneratedArtifactsForResume(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $this->withBuildPlanTaskState($scope, 'shared:header', [
            'status' => AiSiteBuildTaskService::TASK_STATUS_PENDING,
        ]);
        $scope = $this->withBuildPlanTaskState($scope, $pageTaskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_PENDING,
        ]);
        $scope['shared_components']['header'] = [
            'region' => 'header',
            'code' => 'header/ai-site-header',
            'html' => '<header>Ready</header>',
        ];
        $scope['page_type_layouts']['home_page']['content'][] = [
            'code' => 'content/home-page-hero',
            'component' => 'content/home-page-hero',
            'html' => '<section><h1>Hero</h1></section>',
        ];

        $scope = $service->resetBuildTasksToPendingForRebuild($scope);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $this->buildPlanTaskState($scope, 'shared:header')['status'] ?? null);
        $this->assertSame(['region' => 'header'], $this->buildPlanTaskState($scope, 'shared:header')['result_ref'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $this->buildPlanTaskState($scope, $pageTaskKey)['status'] ?? null);
        $this->assertSame(
            ['page_type' => 'home_page', 'section_code' => 'content/home-page-hero'],
            $this->buildPlanTaskState($scope, $pageTaskKey)['result_ref'] ?? null
        );
        $this->assertNotContains('shared:header', $pendingKeys);
        $this->assertNotContains($pageTaskKey, $pendingKeys);
    }

    public function testForceRebuildResetCanIgnorePersistedArtifactsForFullRegeneration(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $this->withBuildPlanTaskState($scope, 'shared:header', [
            'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
        ]);
        $scope = $this->withBuildPlanTaskState($scope, $pageTaskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
        ]);
        $scope['shared_components']['header'] = [
            'region' => 'header',
            'code' => 'header/ai-site-header',
            'html' => '<header>Ready</header>',
        ];
        $scope['page_type_layouts']['home_page']['content'][] = [
            'code' => 'content/home-page-hero',
            'component' => 'content/home-page-hero',
        ];

        $scope = $service->resetBuildTasksToPendingForRebuild($scope, false);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($scope, 'shared:header')['status'] ?? null);
        $this->assertSame([], $this->buildPlanTaskState($scope, 'shared:header')['result_ref'] ?? null);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($scope, $pageTaskKey)['status'] ?? null);
        $this->assertSame([], $this->buildPlanTaskState($scope, $pageTaskKey)['result_ref'] ?? null);
        $this->assertContains('shared:header', $pendingKeys);
        $this->assertContains($pageTaskKey, $pendingKeys);
    }

    public function testClearBuildArtifactsForRegenerationClearsImageFailureTrail(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->clearBuildArtifactsForRegeneration([
            'virtual_pages_by_type' => ['home_page' => ['blocks' => [['code' => 'hero']]]],
            'asset_image_generation_failures' => [
                [
                    'slot_id' => 'home:hero',
                    'message' => 'Old provider failure',
                    'updated_at' => '2026-05-24 08:00:00',
                ],
            ],
            'latest_build_failed' => 1,
            'publish_blocked_reason' => 'Old failure',
        ]);

        self::assertSame([], $scope['virtual_pages_by_type'] ?? null);
        self::assertSame([], $scope['asset_image_generation_failures'] ?? null);
        self::assertSame(0, $scope['latest_build_failed'] ?? null);
        self::assertSame('', $scope['publish_blocked_reason'] ?? null);
    }

    public function testForceRebuildResetRetriesDoneTaskWhenGeneratedArtifactIsMissing(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);

        $scope = $service->resetBuildTasksToPendingForRebuild($scope);

        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($scope, 'shared:header')['status'] ?? null);
        $this->assertSame([], $this->buildPlanTaskState($scope, 'shared:header')['result_ref'] ?? null);
    }

    public function testSummarizeTracksCancelledTasksWithoutPretendingTheyArePending(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $this->withBuildPlanTaskState($scope, 'shared:footer', [
            'status' => AiSiteBuildTaskService::TASK_STATUS_CANCELLED,
        ]);

        $summary = $service->summarize($scope);

        $this->assertSame(1, $summary['done']);
        $this->assertSame(1, $summary['cancelled']);
        $this->assertGreaterThan(0, $summary['pending']);
        $this->assertSame(1, $summary['groups']['shared']['cancelled']);
        $sharedFooterTask = \array_values(\array_filter(
            $summary['groups']['shared']['tasks'],
            static fn(array $task): bool => ($task['task_key'] ?? '') === 'shared:footer'
        ));
        $this->assertCount(1, $sharedFooterTask);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_CANCELLED, $sharedFooterTask[0]['status']);
    }

    public function testResetPageTasksForRetryOnlyTouchesTargetPageTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page', 'about_page']), [], 'html_blocks');

        $scope = $service->markTaskRunning($scope, 'page:home_page:content/home-page-hero');
        $scope = $service->markTaskDone($scope, 'page:home_page:content/home-page-hero', ['page_type' => 'home_page']);
        $scope = $service->markTaskRunning($scope, 'page:about_page:content/about-page-hero');
        $scope = $service->markTaskDone($scope, 'page:about_page:content/about-page-hero', ['page_type' => 'about_page']);

        $homeAttemptsBefore = (int)($this->buildPlanTaskState($scope, 'page:home_page:content/home-page-hero')['attempt_no'] ?? 0);
        $aboutAttemptsBefore = (int)($this->buildPlanTaskState($scope, 'page:about_page:content/about-page-hero')['attempt_no'] ?? 0);
        $this->assertSame(1, $homeAttemptsBefore);
        $this->assertSame(1, $aboutAttemptsBefore);

        $scope = $service->resetPageTasksForRetry($scope, 'home_page');

        $this->assertSame(
            AiSiteBuildTaskService::TASK_STATUS_PENDING,
            $this->buildPlanTaskState($scope, 'page:home_page:content/home-page-hero')['status']
        );
        $this->assertSame(
            AiSiteBuildTaskService::TASK_STATUS_DONE,
            $this->buildPlanTaskState($scope, 'page:about_page:content/about-page-hero')['status']
        );
        $this->assertSame(2, (int)($this->buildPlanTaskState($scope, 'page:home_page:content/home-page-hero')['attempt_no'] ?? 0));
        $this->assertSame(1, (int)($this->buildPlanTaskState($scope, 'page:about_page:content/about-page-hero')['attempt_no'] ?? 0));
    }

    public function testArePageTasksCompleteOnlyTurnsTrueAfterAllPageTasksAreDone(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page', 'about_page']), [], 'virtual_theme');

        $this->assertFalse($service->arePageTasksComplete($scope, 'home_page'));

        foreach ($service->listTaskKeysByPageType($scope, 'home_page') as $taskKey) {
            $scope = $service->markTaskDone($scope, $taskKey, ['page_type' => 'home_page']);
        }

        $this->assertTrue($service->arePageTasksComplete($scope, 'home_page'));
        $this->assertFalse($service->arePageTasksComplete($scope, 'about_page'));
    }

    public function testHasUnfinishedBlueprintTasksCountsRunningMarkers(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $taskKey = 'page:contact_page:content/contact-page-faq';
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'contact_page' => ['faq'],
        ]), [], 'virtual_theme');
        $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
            'attempt_no' => 1,
        ]);
        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);

        self::assertTrue($service->hasUnfinishedBlueprintTasks($scope));
        self::assertSame([], $service->listPendingTasks($scope));
    }

    public function testFinalizeBuildTaskStatesMarksDoneWhenArtifactAlreadyPresent(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $taskKey = 'page:contact_page:content/contact-page-faq';
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'contact_page' => ['faq'],
        ]), [], 'virtual_theme');
        $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
        ]);
        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $scope = $service->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);
        $scope = \array_replace_recursive($scope, [
            'page_type_layouts' => [
                'contact_page' => [
                    'content' => [
                        [
                            'code' => 'content/contact-page-faq',
                            'html' => '<section>Generated contact FAQ content.</section>',
                        ],
                    ],
                ],
            ],
        ]);

        $next = $service->finalizeBuildTaskStatesAfterRunLoop($scope);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $this->buildPlanTaskState($next, $taskKey)['status']);
        self::assertFalse($service->hasUnfinishedBlueprintTasks($next));
    }

    public function testFinalizeBuildTaskStatesWritesRenderDataContractWhenBuildComplete(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $scope['workspace_track'] = 'virtual_theme';
        foreach (['shared:header', 'shared:footer', $pageTaskKey] as $taskKey) {
            $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
                'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            ]);
        }
        $scope = \array_replace_recursive($scope, [
            'shared_components' => [
                'header' => ['code' => 'header/ai-site-header', 'html' => '<header>Ready</header>'],
                'footer' => ['code' => 'footer/ai-site-footer', 'html' => '<footer>Ready</footer>'],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'title' => 'Ready Home Page',
                    'description' => 'Ready home page with trustworthy conversion copy for visitors.',
                    'h1' => 'Ready Home Page',
                    'content' => [
                        [
                            'code' => 'content/home-page-hero',
                            'component' => 'content/home-page-hero',
                            'title' => 'Ready Home Page',
                            'description' => 'A concrete home section with visitor-facing proof and a direct action.',
                            'html' => '<section>Ready home page visitor-facing proof and direct action.</section>',
                            'design_tags' => [
                                'visual' => ['trust band'],
                                'motion' => ['subtle reveal'],
                            ],
                        ],
                    ],
                ],
                'blog_list' => [
                    'title' => 'Unselected Blog',
                    'content' => [],
                ],
            ],
            'materialized_pages_by_type' => [
                'home_page' => [
                    'page_id' => 123,
                    'seo_title' => 'Ready Home Page Conversion Guide',
                    'seo_description' => 'Ready home page content with trust proof, action copy, and a clear visitor path.',
                    'h1' => 'Ready Home Page',
                ],
            ],
            'asset_manifest' => [
                'version' => 1,
                'slots' => [],
            ],
        ]);

        $next = $service->finalizeBuildTaskStatesAfterRunLoop($scope);
        $contract = $next['render_data_contract'] ?? [];

        $this->assertSame(ContractType::TYPE_RENDER_DATA, $contract['contract_meta']['type'] ?? null);
        $this->assertSame(ContractType::STAGE_BUILD, $contract['contract_meta']['stage'] ?? null);
        $this->assertSame($scope['build_plan_v2']['contract_meta']['signature'], $contract['payload']['build_plan_signature'] ?? null);
        $this->assertSame(['home_page'], $contract['payload']['page_types'] ?? null);
        $this->assertSame(
            ['home_page' => $scope['page_type_layouts']['home_page']],
            $contract['payload']['page_type_layouts'] ?? null
        );
        $this->assertArrayNotHasKey('blog_list', $contract['payload']['page_type_layouts'] ?? []);
        $this->assertSame(3, $contract['payload']['build_summary']['done'] ?? null);
        $this->assertSame('build_plan_v2', $contract['source_contracts'][0]['type'] ?? null);
        $this->assertSame($contract, $next['build_contracts'][ContractType::TYPE_RENDER_DATA] ?? null);
        $this->assertSame($contract, $next['build_workbench']['contracts'][ContractType::TYPE_RENDER_DATA] ?? null);

        $qaReport = $next['qa_report_contract'] ?? [];
        $this->assertSame(ContractType::TYPE_QA_REPORT, $qaReport['contract_meta']['type'] ?? null);
        $this->assertSame('pass', $qaReport['payload']['status'] ?? null);
        $this->assertSame(0, $qaReport['payload']['summary']['finding_count'] ?? null);
        $this->assertSame($qaReport, $next['build_contracts'][ContractType::TYPE_QA_REPORT] ?? null);
        $this->assertSame($qaReport, $next['build_workbench']['contracts'][ContractType::TYPE_QA_REPORT] ?? null);
    }

    public function testFinalizeBuildTaskStatesLeavesPendingWhenNoArtifact(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $taskKey = 'page:contact_page:content/contact-page-missing-section';
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'contact_page' => ['missing-section'],
        ]), [], 'virtual_theme');
        $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
        ]);
        $scope = \array_replace_recursive($scope, [
            'page_type_layouts' => [
                'contact_page' => ['content' => []],
            ],
        ]);

        $next = $service->finalizeBuildTaskStatesAfterRunLoop($scope);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($next, $taskKey)['status']);
        self::assertTrue($service->hasUnfinishedBlueprintTasks($next));
    }

    public function testFinalizeBuildTaskStatesThrowsWhenRenderDataQualityGateFails(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $pageTaskKey = 'page:home_page:content/home-page-hero';
        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $scope['workspace_track'] = 'virtual_theme';
        foreach (['shared:header', 'shared:footer', $pageTaskKey] as $taskKey) {
            $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
                'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            ]);
        }
        $scope = \array_replace_recursive($scope, [
            'shared_components' => [
                'header' => ['code' => 'header/ai-site-header', 'html' => '<header>Ready</header>'],
                'footer' => ['code' => 'footer/ai-site-footer', 'html' => '<footer>Ready</footer>'],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'title' => 'Home',
                    'description' => 'Home description.',
                    'h1' => 'Home',
                    'content' => [
                        [
                            'code' => 'content/home-page-hero',
                            'component' => 'content/home-page-hero',
                            'design_tags' => ['visual' => ['hero']],
                            'html' => '<section><div></section>',
                        ],
                    ],
                ],
            ],
            'materialized_pages_by_type' => [
                'home_page' => [
                    'page_id' => 123,
                    'seo_title' => 'Home',
                    'seo_description' => 'Home seo.',
                    'h1' => 'Home',
                ],
            ],
            'asset_manifest' => [
                'version' => 1,
                'slots' => [],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $service->finalizeBuildTaskStatesAfterRunLoop($scope);
    }

    public function testReconcileDoesNotTreatStageOneSharedPlanAsBuiltArtifact(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $scope['shared_components'] = [
            'header' => [
                'task_key' => 'shared:header',
                'task_type' => 'shared_component',
                'component' => 'header',
                'goal' => 'Build the shared site header with brand recognition, navigation, and a primary CTA.',
            ],
            'footer' => [
                'task_key' => 'shared:footer',
                'task_type' => 'shared_component',
                'component' => 'footer',
                'goal' => 'Build the shared site footer with contact, policy, and support links.',
            ],
        ];

        $scope = $service->reconcileGeneratedArtifactsWithTaskState($scope);
        $pendingKeys = \array_column($service->listPendingTasks($scope), 'task_key');

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($scope, 'shared:header')['status'] ?? null);
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $this->buildPlanTaskState($scope, 'shared:footer')['status'] ?? null);
        self::assertContains('shared:header', $pendingKeys);
        self::assertContains('shared:footer', $pendingKeys);
    }

    public function testFormatBuildCompletionGateFailureDetailIncludesFailedTaskMessage(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:blog_category:content/blog-category-article-collection';
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'blog_category' => ['article_collection'],
        ]), [], 'virtual_theme');
        $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
            'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
            'message' => 'AI HTML generation timeout',
        ]);
        $gate = $service->inspectBuildCompletionGate($scope);

        $detail = $service->formatBuildCompletionGateFailureDetail($gate);
        self::assertStringContainsString($taskKey, $detail);
        self::assertStringContainsString('AI HTML generation timeout', $detail);
        self::assertStringContainsString('重试失败项', $detail);
    }

    public function testConfirmedBuildPlanCoverageDetectsMissingSelectedPageTypes(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $coverage = $service->inspectConfirmedBuildPlanPageTypeCoverage([
            'page_types' => ['home_page', 'about_page'],
            'build_plan_v2' => [
                'pages' => [
                    ['page_id' => 'home_page', 'page_type' => 'home_page'],
                ],
                'tasks' => [
                    [
                        'task_id' => 'page:home_page:content/home-page-hero',
                        'input_scope' => ['page_type' => 'home_page'],
                    ],
                ],
            ],
        ]);

        self::assertSame(['about_page'], $coverage['missing_page_types']);
        self::assertSame(['home_page'], $coverage['actual_page_types']);
    }

    public function testInspectBuildCompletionGateFailsWhenPageTypeLayoutsMissSelectedPageType(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page', 'about_page']), [], 'virtual_theme');
        $gate = $service->inspectBuildCompletionGate(\array_replace_recursive($scope, [
            'page_types' => ['home_page', 'about_page'],
            'page_type_layouts' => [
                'home_page' => [
                    'content' => [
                        ['code' => 'content/home-page-hero'],
                    ],
                ],
            ],
        ]));

        self::assertFalse((bool)$gate['passed']);
        self::assertSame('missing_page_type_layouts', $gate['reason']);
        self::assertSame(['about_page'], $gate['missing_page_type_layouts']);
        self::assertStringContainsString('about_page', $service->formatBuildCompletionGateFailureDetail($gate));
    }

    public function testInspectBuildCompletionGateFailsWhenPageLayoutHasFewerBlocksThanPlan(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope($this->buildConfirmedScope(['home_page']), [], 'virtual_theme');
        $firstBlock = \is_array($scope['build_plan_v2']['blocks'][0] ?? null) ? $scope['build_plan_v2']['blocks'][0] : [];
        $scope['build_plan_v2']['blocks'][] = $firstBlock;

        foreach (['shared:header', 'shared:footer', 'page:home_page:content/home-page-hero'] as $taskKey) {
            $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
                'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            ]);
        }
        $scope = \array_replace_recursive($scope, [
            'shared_components' => [
                'header' => ['code' => 'header/ai-site-header', 'html' => '<header>Ready</header>'],
                'footer' => ['code' => 'footer/ai-site-footer', 'html' => '<footer>Ready</footer>'],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'content' => [
                        [
                            'code' => 'content/home-page-hero',
                            'component' => 'content/home-page-hero',
                            'html' => '<section>Hero generated from the confirmed plan.</section>',
                        ],
                    ],
                ],
            ],
        ]);

        $gate = $service->inspectBuildCompletionGate($scope);
        $shortfall = $gate['page_block_shortfalls'][0] ?? [];

        self::assertFalse((bool)$gate['passed']);
        self::assertSame('incomplete_page_block_counts', $gate['reason']);
        self::assertSame('home_page', $shortfall['page_type'] ?? null);
        self::assertSame(2, $shortfall['expected_blocks'] ?? null);
        self::assertSame(1, $shortfall['layout_blocks'] ?? null);
        self::assertStringContainsString('home_page expected=2', $service->formatBuildCompletionGateFailureDetail($gate));
    }

    public function testInspectBuildCompletionGateFailsWhenLayoutHasWrongBlockIdentitiesEvenIfCountsMatch(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'home_page' => ['hero', 'trust'],
        ]), [], 'virtual_theme');

        foreach ([
            'shared:header',
            'shared:footer',
            'page:home_page:content/home-page-hero',
            'page:home_page:content/home-page-trust',
        ] as $taskKey) {
            $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
                'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            ]);
        }

        $scope = \array_replace_recursive($scope, [
            'shared_components' => [
                'header' => ['code' => 'header/ai-site-header', 'html' => '<header>Ready</header>'],
                'footer' => ['code' => 'footer/ai-site-footer', 'html' => '<footer>Ready</footer>'],
            ],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'blocks' => [
                        ['code' => 'content/home-page-hero', 'html' => '<section>Hero generated from the confirmed plan.</section>'],
                        ['code' => 'content/home-page-trust', 'html' => '<section>Trust generated from the confirmed plan.</section>'],
                    ],
                ],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'content' => [
                        ['code' => 'content/home-page-default-one', 'html' => '<section>Wrong generated block one.</section>'],
                        ['code' => 'content/home-page-default-two', 'html' => '<section>Wrong generated block two.</section>'],
                    ],
                ],
            ],
        ]);

        $gate = $service->inspectBuildCompletionGate($scope);
        $shortfall = $gate['page_block_shortfalls'][0] ?? [];

        self::assertFalse((bool)$gate['passed']);
        self::assertSame('incomplete_page_block_counts', $gate['reason']);
        self::assertSame(2, $shortfall['layout_blocks'] ?? null);
        self::assertSame([
            'content/home-page-hero',
            'content/home-page-trust',
        ], $shortfall['missing_layout_block_codes'] ?? null);
    }

    public function testInspectBuildCompletionGateFailsWhenBuildPlanDropsStageOneBlocks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'home_page' => ['hero', 'trust'],
        ]), [], 'virtual_theme');
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $buildPlan['blocks'] = \array_values(\array_filter(
            \is_array($buildPlan['blocks'] ?? null) ? $buildPlan['blocks'] : [],
            static fn(array $block): bool => (string)($block['section_key'] ?? '') !== 'trust'
        ));
        $scope['build_plan_v2'] = $buildPlan;

        foreach (['shared:header', 'shared:footer', 'page:home_page:content/home-page-hero'] as $taskKey) {
            $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
                'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            ]);
        }
        $scope = \array_replace_recursive($scope, [
            'shared_components' => [
                'header' => ['code' => 'header/ai-site-header', 'html' => '<header>Ready</header>'],
                'footer' => ['code' => 'footer/ai-site-footer', 'html' => '<footer>Ready</footer>'],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'content' => [
                        ['code' => 'content/home-page-hero', 'html' => '<section>Hero generated from the confirmed plan.</section>'],
                    ],
                ],
            ],
        ]);

        $gate = $service->inspectBuildCompletionGate($scope);

        self::assertFalse((bool)$gate['passed']);
        self::assertSame('build_plan_missing_stage1_blocks', $gate['reason']);
        self::assertSame('content/home-page-trust', $gate['build_plan_missing_stage1_blocks'][0]['missing_block_codes'][0] ?? null);
    }

    public function testInspectBuildCompletionGateFailsWhenGeneratedLayoutStillUsesDefaultTemplateCopy(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = $service->ensureTaskScope($this->buildConfirmedScopeWithBlocks([
            'blog_category' => ['category_hero'],
        ]), [], 'virtual_theme');

        foreach (['shared:header', 'shared:footer', 'page:blog_category:content/blog-category-category-hero'] as $taskKey) {
            $scope = $this->withBuildPlanTaskState($scope, $taskKey, [
                'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
            ]);
        }
        $scope = \array_replace_recursive($scope, [
            'shared_components' => [
                'header' => ['code' => 'header/ai-site-header', 'html' => '<header>Ready</header>'],
                'footer' => ['code' => 'footer/ai-site-footer', 'html' => '<footer>Ready</footer>'],
            ],
            'page_type_layouts' => [
                'blog_category' => [
                    'content' => [
                        [
                            'code' => 'content/blog-category-category-hero',
                            'html' => '<section><h1>欢迎访问</h1><p>默认页面模板</p></section>',
                        ],
                    ],
                ],
            ],
        ]);

        $gate = $service->inspectBuildCompletionGate($scope);

        self::assertFalse((bool)$gate['passed']);
        self::assertSame('default_template_page_layouts', $gate['reason']);
        self::assertSame(['blog_category'], $gate['default_template_page_layouts']);
    }

    /**
     * @param array<string, mixed> $buildPlan
     * @return list<string>
     */
    private function buildPlanExecutionTaskKeys(array $buildPlan): array
    {
        $taskKeys = [];
        $sharedExecution = \is_array($buildPlan['shared_execution'] ?? null) ? $buildPlan['shared_execution'] : [];
        foreach (['header', 'footer'] as $region) {
            $row = \is_array($sharedExecution[$region] ?? null) ? $sharedExecution[$region] : [];
            $taskKey = \trim((string)($row['task_key'] ?? ''));
            if ($taskKey !== '') {
                $taskKeys[] = $taskKey;
            }
        }

        foreach (\is_array($buildPlan['blocks'] ?? null) ? $buildPlan['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $execution = \is_array($block['execution'] ?? null) ? $block['execution'] : [];
            $taskKey = \trim((string)($execution['task_key'] ?? ''));
            if ($taskKey !== '') {
                $taskKeys[] = $taskKey;
            }
        }

        return $taskKeys;
    }

    /**
     * @param array<string, mixed> $buildPlan
     * @return list<string>
     */
    private function buildPlanPageTypes(array $buildPlan): array
    {
        $pageTypes = [];
        foreach (\is_array($buildPlan['pages'] ?? null) ? $buildPlan['pages'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? ''));
            if ($pageType !== '') {
                $pageTypes[] = $pageType;
            }
        }

        return $pageTypes;
    }

    /**
     * @param array<string, mixed> $buildPlan
     * @return array<string, mixed>
     */
    private function findBuildPlanBlockByExecutionTaskKey(array $buildPlan, string $taskKey): array
    {
        foreach (\is_array($buildPlan['blocks'] ?? null) ? $buildPlan['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $execution = \is_array($block['execution'] ?? null) ? $block['execution'] : [];
            if (($execution['task_key'] ?? null) === $taskKey) {
                return $block;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildPlanTaskState(array $scope, string $taskKey): array
    {
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if (\str_starts_with($taskKey, 'shared:')) {
            $region = \substr($taskKey, 7);
            return \is_array($buildPlan['shared_execution'][$region] ?? null) ? $buildPlan['shared_execution'][$region] : [];
        }

        $block = $this->findBuildPlanBlockByExecutionTaskKey($buildPlan, $taskKey);
        return \is_array($block['execution'] ?? null) ? $block['execution'] : [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function withBuildPlanTaskState(array $scope, string $taskKey, array $patch): array
    {
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if (\str_starts_with($taskKey, 'shared:')) {
            $region = \substr($taskKey, 7);
            $shared = \is_array($buildPlan['shared_execution'] ?? null) ? $buildPlan['shared_execution'] : [];
            $existing = \is_array($shared[$region] ?? null) ? $shared[$region] : ['task_key' => $taskKey];
            $shared[$region] = \array_replace($existing, $patch);
            $buildPlan['shared_execution'] = $shared;
            $scope['build_plan_v2'] = $buildPlan;
            return $scope;
        }

        $blocks = \is_array($buildPlan['blocks'] ?? null) ? $buildPlan['blocks'] : [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $execution = \is_array($block['execution'] ?? null) ? $block['execution'] : [];
            if (($execution['task_key'] ?? null) !== $taskKey) {
                continue;
            }
            $block['execution'] = \array_replace($execution, $patch);
            $blocks[$index] = $block;
            $buildPlan['blocks'] = $blocks;
            $scope['build_plan_v2'] = $buildPlan;
            return $scope;
        }

        return $scope;
    }

    /**
     * @param array<string, list<string>|list<array<string, mixed>>> $pageBlocks
     * @return array<string, mixed>
     */
    private function buildConfirmedScopeWithBlocks(array $pageBlocks): array
    {
        $pages = [];
        foreach ($pageBlocks as $pageType => $blocks) {
            $pageBlocksForPlan = [];
            foreach ($blocks as $block) {
                if (\is_string($block)) {
                    $pageBlocksForPlan[] = $this->buildStageOneBlock(
                        $block,
                        \ucfirst(\str_replace(['_', '-'], ' ', $block)),
                        'Build the ' . $block . ' section.',
                        'A concise proof-led section helps visitors understand the next step.',
                        'Continue'
                    );
                    continue;
                }
                if (\is_array($block)) {
                    $pageBlocksForPlan[] = $block;
                }
            }
            $pages[$pageType] = [
                'title' => \ucwords(\str_replace('_', ' ', (string)$pageType)),
                'page_goal' => 'Explain the offer clearly and move qualified visitors to the next step.',
                'blocks' => $pageBlocksForPlan,
            ];
        }

        $pageTypes = \array_keys($pageBlocks);
        $planJson = [
            'page_types' => $pageTypes,
            'pages' => $pages,
        ];
        $sourceScope = [
            'page_types' => $pageTypes,
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => $planJson,
        ];
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope($sourceScope));

        return [
            'page_types' => $pageTypes,
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => $planJson,
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStageOneBlock(string $blockKey, string $title, string $goal, string $copy, string $cta = ''): array
    {
        $fieldPlan = [
            ['field' => 'description', 'sample' => $copy],
        ];
        if (\trim($cta) !== '') {
            $fieldPlan[] = ['field' => 'cta', 'sample' => $cta];
        }

        return [
            'block_key' => $blockKey,
            'title' => $title,
            'goal' => $goal,
            'page_flow_role' => $this->buildStageOnePageFlowRole($blockKey),
            'visual_signature' => $this->buildStageOneVisualSignature($blockKey),
            'field_plan' => $fieldPlan,
        ];
    }

    private function buildStageOnePageFlowRole(string $blockKey): string
    {
        return match ($blockKey) {
            'hero' => 'opening_conversion',
            'trust' => 'evidence_builder',
            'cta' => 'decision_prompt',
            'story' => 'credibility_context',
            'team' => 'operator_proof',
            'form' => 'conversion_capture',
            'faq' => 'objection_reducer',
            default => 'page_section_' . \str_replace('-', '_', $blockKey),
        };
    }

    /**
     * @return array<string, string>
     */
    private function buildStageOneVisualSignature(string $blockKey): array
    {
        return [
            'composition_pattern' => $blockKey . ' section with a clear content hierarchy and purposeful supporting detail',
            'spatial_rhythm' => 'balanced vertical rhythm with distinct heading, copy, action, and evidence zones',
            'media_strategy' => 'integrated media or CSS motif that supports the block message without decorative filler',
            'surface_treatment' => 'clean contrast, restrained depth, and readable content surfaces',
            'interaction_pattern' => 'subtle focus and hover feedback on actionable elements',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildConfirmedScope(array $pageTypes = ['home_page']): array
    {
        $pages = [];
        foreach ($pageTypes as $pageType) {
            $pages[$pageType] = [
                'title' => \ucwords(\str_replace('_', ' ', $pageType)),
                'page_goal' => 'Explain the offer clearly and move qualified visitors to the next step.',
                'blocks' => [
                    $this->buildStageOneBlock(
                        'hero',
                        'Clear offer for qualified visitors',
                        'Show the core value with a direct CTA.',
                        'A concise proof-led section helps visitors understand the next step.',
                        'Request the ' . \str_replace('_', ' ', $pageType) . ' consultation'
                    ),
                ],
            ];
        }

        $sourceScope = [
            'page_types' => $pageTypes,
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'page_types' => $pageTypes,
                'pages' => $pages,
            ],
        ];
        $buildPlanService = new AiSiteBuildPlanService();
        $buildPlan = $buildPlanService->confirm($buildPlanService->buildFromScope($sourceScope));

        return [
            'page_types' => $pageTypes,
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'build_plan_v2' => $buildPlan,
            'build_plan_confirmed' => 1,
        ];
    }
}
